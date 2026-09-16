<?php

namespace App\Domain\Kimai;

use App\Domain\Lembur\DurationCalculator;
use App\Enums\OvertimeStatus;
use App\Enums\Source;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRecordKimaiEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * SY-23 — memetakan satu sesi lembur ke satu `OvertimeRecord`, beserta seluruh
 * pagarnya.
 *
 * Dipisahkan dari KimaiSynchronizer karena ada DUA pemanggil: sync harian, dan
 * command `lemburku:kimai:regroup` yang membereskan record warisan sync 1:1. Kalau
 * keduanya punya salinan aturan masing-masing, keduanya akan berbeda perlahan —
 * dan yang berbeda adalah aturan tentang kapan data user boleh ditimpa.
 *
 * Record ditulis lewat save() biasa, BUKAN query langsung: dengan begitu
 * OvertimeRecordObserver tetap jalan dan OvertimeDayCalculator yang menghitung tier,
 * uang makan, dan saldo (BR-02/BR-07). Kelas ini tidak pernah menyentuh satu pun
 * kolom hasil hitungan.
 */
class SessionWriter
{
    /**
     * SY-14 — menandai bahwa tulisan yang sedang terjadi berasal dari pipeline Kimai,
     * bukan dari manusia, sehingga observer tidak menyalakan `locally_modified`.
     * Meniru guard OvertimeDayCalculator::isRecalculating().
     */
    private static bool $writing = false;

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @param  array<int, int>  $claimedElsewhere  id timesheet yang dimiliki sesi LAIN
     *                                             pada putaran yang sama
     */
    public function write(User $user, OvertimeSession $session, array $claimedElsewhere = []): SessionWriteResult
    {
        try {
            self::$writing = true;

            return $this->apply($user, $session, $claimedElsewhere);
        } finally {
            self::$writing = false;
        }
    }

    private function apply(User $user, OvertimeSession $session, array $claimedElsewhere): SessionWriteResult
    {
        $related = $this->relatedRecords($user, $session);
        $existing = $this->survivor($session, $related);

        // SY-14 — sekali user menyentuhnya, sync tidak pernah menimpanya lagi. Pada
        // record gabungan ini membekukan SELURUH sesi: anggota baru pun tidak masuk,
        // karena memasukkannya berarti mengubah jam yang sudah disetujui orang.
        if ($existing !== null && ($reason = $this->protectionReason($existing)) !== null) {
            return SessionWriteResult::skipped($reason, $existing);
        }

        $donors = $related->reject(fn (OvertimeRecord $r) => $existing !== null && $r->is($existing));

        $protected = $donors->first(fn (OvertimeRecord $r) => $this->protectionReason($r) !== null);

        if ($protected !== null) {
            return SessionWriteResult::skipped(
                $this->protectionReason($protected)." di catatan #{$protected->id}",
                $protected,
            );
        }

        // BR-23 — saldo yang sudah ditahan atau dipotong klaim tidak boleh lenyap
        // diam-diam, dan `leave_balances` ikut terhapus bersama recordnya (cascade).
        // Sesi seperti ini ditinggalkan untuk dilihat manusia, bukan digabung paksa.
        $claimed = $donors->first(fn (OvertimeRecord $r) => $this->hasSpentBalance($r));

        if ($claimed !== null) {
            return SessionWriteResult::skipped(
                "saldonya sudah dipakai klaim di catatan #{$claimed->id}",
                $claimed,
            );
        }

        $merged = $this->reconcile($session, $existing, $claimedElsewhere);

        $clash = $this->findClash($user, $merged, $related);

        if ($clash !== null) {
            return SessionWriteResult::skipped($this->clashReason($clash), $existing);
        }

        // Dilepas SEBELUM record ditulis: record gabungan sering memakai jam mulai
        // atau kunci grup yang sama dengan salah satu donor, dan
        // unique(user_id, overtime_date, start_time) akan menolaknya.
        $this->releaseDonors($donors);

        return $existing === null
            ? $this->createFrom($user, $merged, $claimedElsewhere)
            : $this->updateFrom($user, $merged, $existing, $claimedElsewhere);
    }

    /**
     * SY-13 — seluruh record yang berkaitan dengan sesi ini: yang memegang kunci
     * grupnya, dan yang masih memegang salah satu entrinya.
     */
    public function relatedRecords(User $user, OvertimeSession $session): Collection
    {
        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q
                ->where('kimai_group_key', $session->groupKey)
                ->orWhere(fn ($o) => $o->owningKimaiEntries($session->timesheetIds())))
            ->orderBy('overtime_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * Record mana yang BERTAHAN dan diperbarui di tempat, alih-alih dihapus lalu
     * dibuat ulang.
     *
     * Ini bukan soal selera. `leave_balances` menempel pada `overtime_record_id`
     * pemegang hak, dan BalanceReconciler mencarinya lewat daftar record di tanggal
     * itu. Selama pemegangnya masih hidup, batch-nya diarahkan ulang — bukan
     * dibatalkan — sehingga klaim yang sedang berjalan tidak ikut ditandai
     * `needs_review` tanpa sebab (BR-23).
     */
    public function survivor(OvertimeSession $session, Collection $related): ?OvertimeRecord
    {
        return $related->first(fn (OvertimeRecord $r) => $r->kimai_group_key === $session->groupKey)
            ?? $related->first(fn (OvertimeRecord $r) => $r->leaveBalance !== null)
            ?? $related->first();
    }

    /** SY-14 — alasan sebuah record terkunci dari sync, atau null kalau ia terbuka. */
    public function protectionReason(OvertimeRecord $record): ?string
    {
        return match (true) {
            (bool) $record->locally_modified => 'ada perubahan lokal',
            $record->status !== OvertimeStatus::Recorded => 'sudah diajukan/disetujui',
            default => null,
        };
    }

    private function hasSpentBalance(OvertimeRecord $record): bool
    {
        $balance = $record->leaveBalance;

        return $balance !== null
            && ((int) $balance->consumed_minutes + (int) $balance->held_minutes) > 0;
    }

    private function clashReason(OvertimeRecord $clash): string
    {
        return $clash->isFromKimai()
            ? "bentrok dengan catatan lama dari Kimai #{$clash->id} — jalankan lemburku:kimai:regroup"
            : "bentrok dengan catatan manual #{$clash->id}";
    }

    /**
     * Isi donor sudah pindah seluruhnya ke record yang bertahan, jadi membiarkannya
     * berarti lembur yang sama terhitung dua kali (BR-02).
     *
     * Dihapus permanen, bukan soft delete: baris yang tertinggal tetap memegang
     * unique(user_id, overtime_date, start_time) dan unique(user_id, kimai_group_key),
     * sehingga record gabungan yang mengambil alih jam mulainya akan ditolak database.
     * Jejaknya tetap ada di `sync_run_items` dan di log aktivitas.
     */
    private function releaseDonors(Collection $donors): void
    {
        foreach ($donors as $donor) {
            $donor->kimaiEntries()->delete();
            $donor->forceDelete();
        }
    }

    /**
     * SY-25 — anggota sesi adalah GABUNGAN entri yang baru ditarik dengan entri yang
     * sudah tersimpan, bukan hanya yang baru ditarik.
     *
     * Rentang sync tidak selalu memuat satu jendela utuh: sync jam 22:00 saat orangnya
     * masih bekerja hanya membawa separuh sesi, dan watermark yang sudah maju membuat
     * entri lama tidak ikut ditanyakan lagi. Tanpa penggabungan ini, sesi akan
     * menyusut setiap kali sebagian anggotanya di luar jangkauan.
     *
     * Sync sengaja TIDAK pernah membuang anggota yang tersimpan hanya karena ia tidak
     * muncul di respons. Entri yang hilang belum tentu terhapus di Kimai — bisa jadi
     * timernya dijalankan lagi (SY-06) atau tagnya dilepas — dan menghapus lembur atas
     * dasar baris yang sekadar tidak ditanyakan terlalu mahal bila tebakannya salah.
     *
     * Satu-satunya anggota yang dilepas adalah yang pada putaran ini jelas-jelas milik
     * sesi LAIN: jamnya diperbaiki di Kimai sehingga ia keluar dari jendela ini.
     * Membiarkannya berarti lembur yang sama terhitung di dua record (BR-02).
     *
     * @param  array<int, int>  $claimedElsewhere
     */
    private function reconcile(
        OvertimeSession $session,
        ?OvertimeRecord $existing,
        array $claimedElsewhere,
    ): OvertimeSession {
        if ($existing === null) {
            return $session;
        }

        $members = [];

        foreach ($existing->kimaiEntries as $row) {
            if (in_array((int) $row->kimai_timesheet_id, $claimedElsewhere, true)) {
                continue;
            }

            $members[(int) $row->kimai_timesheet_id] = $row->toTimesheet();
        }

        // Yang baru ditarik menang: jam atau durasinya mungkin diperbaiki di Kimai.
        foreach ($session->entries as $entry) {
            $members[$entry->id] = $entry;
        }

        return $session->withEntries(array_values($members));
    }

    private function createFrom(User $user, OvertimeSession $session, array $claimedElsewhere): SessionWriteResult
    {
        $record = new OvertimeRecord;
        $record->fill([
            'user_id' => $user->id,
            'overtime_date' => $session->anchorDate->toDateString(),
            'start_time' => $session->startTime(),
            'end_time' => $session->endTime(),
            'work_description' => $session->workDescription(),
            'evidence_url' => $session->evidenceUrl(),
            'status' => OvertimeStatus::Recorded,
            'source' => Source::Kimai,
            'kimai_timesheet_id' => $session->first()->id,
            'kimai_project_id' => $session->projectId(),
            'kimai_activity_id' => $session->activityId(),
            'kimai_group_key' => $session->groupKey,
            'break_minutes' => $session->breakMinutes(),
            // SY-12 — deep link Kimai membuktikan jam kerja, tetapi SOP §6 tetap
            // mewajibkan SPL dan timesheet. Link ini pengisi sementara.
            'evidence_needs_review' => true,
        ]);

        $this->stampKimai($record, $session);
        $record->save();

        $this->attachMembers($user, $record, $session, $claimedElsewhere);

        return SessionWriteResult::created($record, $session);
    }

    private function updateFrom(
        User $user,
        OvertimeSession $session,
        OvertimeRecord $record,
        array $claimedElsewhere,
    ): SessionWriteResult {
        $record->fill([
            'overtime_date' => $session->anchorDate->toDateString(),
            'start_time' => $session->startTime(),
            'end_time' => $session->endTime(),
            'work_description' => $session->workDescription(),
            'kimai_timesheet_id' => $session->first()->id,
            'kimai_project_id' => $session->projectId(),
            'kimai_activity_id' => $session->activityId(),
            'kimai_group_key' => $session->groupKey,
            'break_minutes' => $session->breakMinutes(),
        ]);

        $this->stampKimai($record, $session);

        // `synced_at` selalu berubah, jadi ia tidak boleh ikut menentukan "identik" —
        // kalau ikut, tidak akan pernah ada entri `tidak berubah`.
        $changed = collect($record->getDirty())->except(['synced_at'])->isNotEmpty();

        $record->save();

        // Keanggotaan ikut menentukan "berubah": entri yang menyusul ke sesi yang
        // sudah ada belum tentu menggeser jam mana pun, tapi tetap bukan `Unchanged`.
        $membershipChanged = $this->attachMembers($user, $record, $session, $claimedElsewhere);

        return $changed || $membershipChanged
            ? SessionWriteResult::updated($record, $session)
            : SessionWriteResult::unchanged($record, $session);
    }

    /**
     * SY-10 — kolom hasil hitungan tidak fillable, jadi durasi dari Kimai ditempelkan
     * eksplisit di luar fill(). Untuk sesi gabungan nilainya adalah JUMLAH durasi
     * seluruh anggota.
     */
    private function stampKimai(OvertimeRecord $record, OvertimeSession $session): void
    {
        $record->kimai_duration_minutes = $session->durationMinutes();
        $record->synced_at = now();
    }

    /**
     * SY-13 — baris penghubung inilah yang memegang dedup.
     *
     * @param  array<int, int>  $claimedElsewhere
     * @return bool keanggotaan berubah
     */
    private function attachMembers(
        User $user,
        OvertimeRecord $record,
        OvertimeSession $session,
        array $claimedElsewhere,
    ): bool {
        $before = $record->kimaiEntries()->pluck('kimai_timesheet_id')->map(intval(...))->sort()->values()->all();

        // Anggota yang sudah pindah ke sesi lain dilepas di sini, supaya sesi tujuannya
        // menemukannya sebagai entri bebas dan unique(user_id, kimai_timesheet_id) di
        // tabel penghubung tidak menolak pemindahannya.
        if ($claimedElsewhere !== []) {
            $record->kimaiEntries()->whereIn('kimai_timesheet_id', $claimedElsewhere)->delete();
        }

        foreach ($session->entries as $entry) {
            OvertimeRecordKimaiEntry::query()->updateOrCreate(
                ['user_id' => $user->id, 'kimai_timesheet_id' => $entry->id],
                [
                    'overtime_record_id' => $record->id,
                    'begin' => $entry->begin,
                    'end' => $entry->end ?? $entry->begin,
                    'duration_minutes' => $entry->durationMinutes(),
                    'break_minutes' => $entry->breakMinutes(),
                    'description' => $entry->description,
                ],
            );
        }

        $after = $session->timesheetIds();
        sort($after);

        return $before !== $after;
    }

    /**
     * SY-15 — bentrok jam dengan record lain di tanggal yang sama (aturan F-02).
     *
     * Yang dibandingkan adalah rentang GABUNGAN, dan SELURUH record yang berkaitan
     * dengan sesi ini wajib dikecualikan. Kalau tidak, dua entri berurutan
     * (18:00–20:00 lalu 20:00–22:00) membuat sesi bentrok dengan dirinya sendiri dan
     * tidak akan pernah ada satu pun yang terimpor.
     *
     * Pemeriksaan ini WAJIB di sini: aturan overlap F-02 hanya hidup sebagai validasi
     * form Filament, jadi tulisan programatik tidak terlindungi.
     */
    private function findClash(User $user, OvertimeSession $session, Collection $related): ?OvertimeRecord
    {
        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('overtime_date', $session->anchorDate->toDateString())
            ->whereKeyNot($related->pluck('id')->all())
            ->get()
            ->first(fn (OvertimeRecord $r) => DurationCalculator::overlaps(
                $session->startTime(),
                $session->endTime(),
                (string) $r->start_time,
                (string) $r->end_time,
            ));
    }
}
