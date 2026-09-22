<?php

namespace App\Domain\Lembur;

use App\Domain\Kimai\Exceptions\KimaiRejectedRequest;
use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\Exceptions\KimaiUnavailable;
use App\Domain\Kimai\KimaiClient;
use App\Domain\Kimai\KimaiConnection;
use App\Domain\Timesheet\ActivityResolver;
use App\Domain\Timesheet\KimaiCatalog;
use App\Enums\LeaveTimesheetStatus;
use App\Models\LeaveClaim;
use App\Models\LeaveClaimTimesheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CT-04 — klaim cuti pengganti yang diajukan menulis sendiri entri cuti di Kimai.
 *
 * Dua langkah yang sengaja dipisah karena sifatnya berbeda:
 *
 *   1. plan()  — murni lokal, selalu berhasil. Ia memutuskan slot mana yang
 *                SEHARUSNYA ada dan mana yang tidak lagi dikehendaki. Karena
 *                tidak pernah gagal, klaim tidak pernah batal tersimpan gara-gara
 *                Kimai: itu pagar utama fitur ini.
 *   2. post()  — menyentuh jaringan, dan karena itu meminjam seluruh aturan
 *                keselamatan UploadPoster.
 *
 * Aturan yang dipinjam dari UploadPoster, karena alasannya identik:
 *
 *   - HANYA baris `pending` yang pernah diambil pengirim. Baris `posted` secara
 *     struktural tidak terjangkau, jadi klaim yang disimpan ulang sepuluh kali
 *     tidak pernah menghasilkan satu pun entri ganda.
 *   - TIDAK ADA transaksi yang membungkus perulangan jaringannya. Rollback akan
 *     menghapus catatan tentang apa yang sudah terlanjur masuk ke Kimai —
 *     sementara Kimai sendiri tidak ikut di-rollback.
 */
class LeaveTimesheetSync
{
    public function __construct(
        private readonly LeaveDayPlanner $planner,
        private readonly KimaiCatalog $catalog,
        private readonly ActivityResolver $resolver,
        private readonly KimaiClient $client,
        private readonly KimaiConnection $connection,
    ) {}

    /** Jalur normal: susun rencana, lalu kirim. Tidak pernah melempar. */
    public function sync(LeaveClaim $claim): LeaveTimesheetResult
    {
        $blocker = $this->plan($claim);

        if ($blocker !== null) {
            // Rencana tidak bisa disusun, TETAPI entri yang sudah terlanjur
            // ditandai buang pada pemanggilan sebelumnya tetap layak dihapus.
            $result = $this->post($claim);

            return LeaveTimesheetResult::make(
                posted: $result->posted,
                deleted: $result->deleted,
                pending: $result->pending,
                failed: $result->failed,
                blocker: $blocker,
            );
        }

        return $this->post($claim);
    }

    /**
     * Menarik SELURUH entri klaim ini dari Kimai, apa pun statusnya.
     *
     * Dipakai saat klaimnya dihapus. Tidak bisa diwakili sync(), karena pada
     * observer `deleting` baris klaimnya belum bertanda terhapus: status-nya masih
     * `submitted` dan sync() justru akan menyimpulkan slotnya masih dikehendaki.
     */
    public function withdraw(LeaveClaim $claim): LeaveTimesheetResult
    {
        $this->discardAll($claim);

        return $this->post($claim);
    }

    /**
     * Menyelaraskan baris `leave_claim_timesheets` dengan apa yang semestinya ada
     * di Kimai untuk klaim ini. Mengembalikan alasan kalau rencananya tidak bisa
     * disusun; null berarti beres.
     *
     * Satu invarian yang wajib dipegang: sebuah baris TIDAK PERNAH ditandai buang
     * kalau slotnya masih dikehendaki. Itu yang membuat `lct_slot_unik` tidak
     * pernah bentrok antara baris lama dan baris baru pada jam yang sama.
     */
    public function plan(LeaveClaim $claim): ?string
    {
        $user = $claim->user;

        if ($user === null || ! $user->hasKimaiConnection()) {
            // Tanpa token tidak ada yang bisa dikirim MAUPUN dihapus. Baris lama
            // sengaja dibiarkan apa adanya: menghapus jejaknya sendiri sementara
            // entrinya masih hidup di Kimai jauh lebih buruk daripada diam.
            return null;
        }

        $slots = $this->wantedSlots($claim);

        if ($slots === []) {
            $this->discardAll($claim);

            return null;
        }

        $projectId = (int) config('kimai.leave_project');
        $activityName = (string) config('kimai.leave_activity');
        $catalog = $this->catalog->activitiesOrMirror($user, $projectId);

        if (! $catalog->tersedia()) {
            // Bedakan "katalognya kosong" dari "namanya tidak ada di katalog":
            // yang pertama diselesaikan admin dengan sync katalog, yang kedua
            // dengan membetulkan nama activity-nya.
            return sprintf(
                'Daftar activity Kimai tidak bisa dibaca (%s), jadi timesheet cuti belum dibuat. '
                .'Kirim ulang dari daftar klaim setelah Kimai bisa dihubungi.',
                $catalog->error ?? 'sebab tidak diketahui',
            );
        }

        $ids = $this->resolver->idsFor($catalog->items, $activityName);

        if ($ids === []) {
            return sprintf(
                'Activity "%s" tidak ada di project %d, jadi timesheet cuti belum dibuat. '
                .'Minta admin menjalankan sync katalog Kimai, lalu kirim ulang dari daftar klaim.',
                $activityName,
                $projectId,
            );
        }

        if (count($ids) > 1) {
            // Menebak salah satu berarti cuti masuk ke activity yang keliru tanpa
            // ada yang tahu — alasan yang sama dengan ActivityResolver::resolve().
            return sprintf(
                'Activity "%s" cocok ke lebih dari satu (id %s), jadi timesheet cuti belum dibuat.',
                $activityName,
                implode(', ', $ids),
            );
        }

        $this->reconcile($claim, $slots, $projectId, $ids[0]);

        return null;
    }

    /**
     * Slot yang DIKEHENDAKI untuk sebuah klaim.
     *
     * isActive() dipakai, bukan `=== Submitted`, karena `approved` dan `taken`
     * ada di HILIR submitted: barisnya sudah lahir saat pengajuan, dan
     * menghapusnya begitu atasan menyetujui justru membuang cuti yang sudah benar
     * dari Kimai. Sebaliknya BR-20 releasesBalance() (rejected/cancelled) dan
     * draft menghasilkan daftar kosong: apa yang tidak lagi memakan saldo tidak
     * boleh lagi memakan jam kerja.
     *
     * @return array<int, LeaveSlot>
     */
    private function wantedSlots(LeaveClaim $claim): array
    {
        if ($claim->trashed() || ! $claim->status->isActive()) {
            return [];
        }

        return $this->planner->slots((int) $claim->minutes_required);
    }

    /**
     * @param  array<int, LeaveSlot>  $slots
     */
    private function reconcile(LeaveClaim $claim, array $slots, int $projectId, int $activityId): void
    {
        $date = CarbonImmutable::parse($claim->claim_date);

        // Dicocokkan sebagai INSTAN, tidak pernah sebagai jam dinding: dua baris
        // bisa punya "09:00" yang sama pada tanggal yang berbeda.
        $wanted = [];

        foreach ($slots as $slot) {
            $wanted[$slot->beginAt($date)->getTimestamp()] = $slot;
        }

        DB::transaction(function () use ($claim, $wanted, $date, $projectId, $activityId) {
            $existing = $claim->timesheets()->kept()->get();
            $seen = [];

            foreach ($existing as $row) {
                $key = $row->begin_at->getTimestamp();

                // Slot yang masih dikehendaki DAN masih menunjuk activity yang sama
                // dibiarkan utuh — termasuk `kimai_timesheet_id`-nya, sehingga
                // mengecilkan klaim tidak mengirim ulang slot yang tidak berubah.
                $cocok = isset($wanted[$key])
                    && $row->end_at->getTimestamp() === $wanted[$key]->endAt($date)->getTimestamp()
                    && $row->kimai_activity_id === $activityId
                    && $row->kimai_project_id === $projectId;

                if ($cocok) {
                    $seen[$key] = true;

                    continue;
                }

                $this->discard($row);
            }

            foreach ($wanted as $key => $slot) {
                if (isset($seen[$key])) {
                    continue;
                }

                $claim->timesheets()->create([
                    'user_id' => $claim->user_id,
                    'kimai_project_id' => $projectId,
                    'kimai_activity_id' => $activityId,
                    // ->utc() seperti UploadDrafter, dan karena alasan yang sama:
                    // kolom datetime dibaca kembali BERLABEL zona aplikasi, jadi
                    // menyimpan jam dinding WIB apa adanya akan membuat
                    // toKimaiPayload() menggesernya tujuh jam lagi saat dikirim.
                    'begin_at' => $slot->beginAt($date)->utc(),
                    'end_at' => $slot->endAt($date)->utc(),
                    'duration_minutes' => $slot->durationMinutes(),
                    'status' => LeaveTimesheetStatus::Pending->value,
                ]);
            }
        });
    }

    /** Klaim yang tidak lagi berhak atas jam kerja: seluruh slotnya ditandai buang. */
    private function discardAll(LeaveClaim $claim): void
    {
        DB::transaction(function () use ($claim) {
            foreach ($claim->timesheets()->kept()->get() as $row) {
                $this->discard($row);
            }
        });
    }

    /**
     * Baris yang belum pernah sampai ke Kimai dihapus habis — tidak ada apa pun
     * di sana yang perlu ditarik, dan menyimpannya hanya menumpuk jejak kosong.
     * Baris yang sudah terkirim ditandai supaya pengirimlah yang menghapusnya.
     */
    private function discard(LeaveClaimTimesheet $row): void
    {
        if (! $row->status->holdsKimaiEntry()) {
            $row->delete();

            return;
        }

        $row->forceFill(['discarded_at' => now()])->save();
    }

    /**
     * Langkah jaringan: hapus dulu, kirim kemudian.
     *
     * Urutannya penting. Kalau sebuah slot dibuang lalu dibuat ulang pada jam yang
     * sama — misalnya karena activity id-nya berubah — menghapus belakangan akan
     * menghapus entri yang baru saja dibuat.
     */
    public function post(LeaveClaim $claim): LeaveTimesheetResult
    {
        $user = $claim->user;

        if ($user === null || ! $user->hasKimaiConnection()) {
            return LeaveTimesheetResult::skipped();
        }

        $token = (string) $user->kimai_api_token;
        $description = $this->description($claim);
        $jeda = max(0, (int) config('kimai.upload_post_delay_ms')) * 1000;

        $deleted = 0;
        $posted = 0;
        $failed = 0;
        $blocker = null;
        $range = null;

        try {
            foreach ($claim->timesheets()->awaitingDeletion()->orderBy('begin_at')->cursor() as $row) {
                $this->client->deleteTimesheet($token, (int) $row->kimai_timesheet_id);

                $row->forceFill([
                    'status' => LeaveTimesheetStatus::Deleted->value,
                    'deleted_from_kimai_at' => now(),
                    'error_message' => null,
                ])->save();

                $deleted++;
            }

            foreach ($claim->timesheets()->pending()->kept()->orderBy('begin_at')->cursor() as $row) {
                try {
                    $created = $this->client->createTimesheet($token, $row->toKimaiPayload($description));

                    $row->forceFill([
                        'status' => LeaveTimesheetStatus::Posted->value,
                        'kimai_timesheet_id' => $created['id'] ?? null,
                        'posted_at' => now(),
                        'error_message' => null,
                    ])->save();

                    $posted++;
                } catch (KimaiRejectedRequest $e) {
                    // 4xx validasi: masalahnya ada pada slot INI — jam yang bentrok,
                    // activity yang tidak berlaku. Satu slot keliru tidak boleh
                    // menyandera sisanya (semangat SY-19).
                    $row->forceFill([
                        'status' => LeaveTimesheetStatus::Failed->value,
                        'error_message' => $e->userMessage(),
                    ])->save();

                    $failed++;

                    continue;
                }

                if ($jeda > 0) {
                    usleep($jeda);
                }
            }
        } catch (KimaiTokenInvalid $e) {
            // SY-22 — setiap permintaan berikutnya akan menghasilkan 401 yang sama.
            $this->connection->markInvalid($user);
            $blocker = $e->userMessage().' Perbarui API key di Preferensi, lalu kirim ulang.';
        } catch (KimaiUnavailable $e) {
            // Instance-nya yang sedang tidak bisa dihubungi, bukan slotnya. Sisanya
            // ditinggal `pending` dan bisa dikirim ulang tanpa risiko ganda.
            $blocker = $e->userMessage();
        } catch (Throwable $e) {
            report($e);
            $blocker = 'Pengiriman timesheet cuti berhenti karena kesalahan tak terduga.';
        }

        if ($posted > 0) {
            $range = $this->range($claim);
        }

        return LeaveTimesheetResult::make(
            posted: $posted,
            deleted: $deleted,
            pending: $claim->timesheets()->pending()->kept()->count(),
            failed: $failed,
            blocker: $blocker,
            range: $range,
        );
    }

    /** "Cuti pengganti — libur 1 hari penuh" : terbaca sebagai kalimat di Kimai. */
    private function description(LeaveClaim $claim): string
    {
        return sprintf(
            '%s — %s',
            (string) config('kimai.leave_description'),
            mb_strtolower($claim->claim_type->getLabel()),
        );
    }

    /** "09:00–18:00" dari slot yang benar-benar ada di Kimai. */
    private function range(LeaveClaim $claim): ?string
    {
        $rows = $claim->timesheets()
            ->kept()
            ->where('status', LeaveTimesheetStatus::Posted->value)
            ->orderBy('begin_at')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $zone = config('kimai.timezone');

        return $rows->first()->begin_at->setTimezone($zone)->format('H:i')
            .'–'.$rows->last()->end_at->setTimezone($zone)->format('H:i');
    }
}
