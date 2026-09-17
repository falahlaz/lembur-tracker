<?php

namespace App\Domain\Timesheet;

use App\Domain\Kimai\Exceptions\KimaiRejectedRequest;
use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\Exceptions\KimaiUnavailable;
use App\Domain\Kimai\KimaiClient;
use App\Domain\Kimai\KimaiConnection;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\SyncKimaiTimesheets;
use App\Models\TimesheetUpload;
use Throwable;

/**
 * Langkah 2: mengirim entri yang masih `pending` ke Kimai.
 *
 * Dua aturan yang membuat seluruhnya aman diulang:
 *
 * 1. HANYA entri berstatus `pending` yang pernah diambil. Entri yang sudah
 *    `posted` secara struktural tidak terjangkau, jadi menjalankan ulang upload
 *    yang gagal di tengah tidak akan pernah mengirim ulang yang sudah masuk.
 * 2. TIDAK ADA transaksi yang membungkus perulangannya. Rollback akan menghapus
 *    catatan tentang apa yang sudah terlanjur masuk ke Kimai — sementara Kimai
 *    sendiri tidak ikut di-rollback. Status tiap entri harus commit begitu
 *    responsnya datang. Ini kebalikan sengaja dari transaksi per sesi di
 *    KimaiSynchronizer, yang memang boleh dibatalkan karena datanya milik kita.
 */
class UploadPoster
{
    public function __construct(
        private readonly KimaiClient $client,
        private readonly KimaiConnection $connection,
    ) {}

    public function post(TimesheetUpload $upload): TimesheetUpload
    {
        $user = $upload->user;
        $token = (string) $user->kimai_api_token;
        $project = (int) $upload->project_id;

        $upload->forceFill([
            'status' => UploadStatus::Posting->value,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $jeda = max(0, (int) config('kimai.upload_post_delay_ms')) * 1000;
        $berhenti = null;

        try {
            foreach ($upload->pendingEntries()->orderBy('begin_at')->orderBy('id')->cursor() as $entry) {
                try {
                    $created = $this->client->createTimesheet($token, $entry->toKimaiPayload($project));

                    $entry->forceFill([
                        'status' => UploadEntryStatus::Posted->value,
                        'kimai_timesheet_id' => $created['id'] ?? null,
                        'posted_at' => now(),
                        'skip_reason' => null,
                    ])->save();
                } catch (KimaiRejectedRequest $e) {
                    // 4xx validasi: masalahnya ada pada entri INI — activity id
                    // yang tidak berlaku, project yang tidak diizinkan. Satu sel
                    // keliru tidak boleh menyandera sisa berkasnya (semangat SY-19).
                    $entry->forceFill([
                        'status' => UploadEntryStatus::Failed->value,
                        'skip_reason' => $e->userMessage(),
                    ])->save();

                    continue;
                }

                if ($jeda > 0) {
                    // Satu periode berarti 60–150 permintaan beruntun ke instance
                    // yang dipakai seluruh tim. Jeda kecil membuatnya tidak terlihat
                    // seperti serangan.
                    usleep($jeda);
                }
            }
        } catch (KimaiTokenInvalid $e) {
            // SY-22 — setiap permintaan berikutnya akan menghasilkan 401 yang sama.
            // Meneruskannya hanya membakar seratus permintaan dan mengubur pesan
            // aslinya di bawah seratus kegagalan identik.
            $this->connection->markInvalid($user);
            $berhenti = $e->userMessage();
        } catch (KimaiUnavailable $e) {
            // Instance-nya yang sedang tidak bisa dihubungi, bukan entrinya. Sisa
            // entri ditinggal `pending`, dan user bisa menekan "Lanjutkan" setelah
            // VPN-nya hidup — tanpa risiko mengirim ulang yang sudah masuk.
            $berhenti = $e->userMessage();
        } catch (Throwable $e) {
            // Upload yang tertinggal di status `posting` selamanya lebih buruk
            // daripada kegagalan aslinya: tombolnya tidak akan pernah hidup lagi.
            $this->close($upload, 'Upload berhenti karena kesalahan tak terduga.');

            throw $e;
        }

        $this->close($upload, $berhenti);

        $this->chainSync($upload);

        TimesheetUpload::pruneFor($upload->user_id);

        return $upload->refresh();
    }

    private function close(TimesheetUpload $upload, ?string $error): void
    {
        $tally = $upload->tally();

        $posted = $tally[UploadEntryStatus::Posted->value] ?? 0;
        $failed = $tally[UploadEntryStatus::Failed->value] ?? 0;
        $skipped = $tally[UploadEntryStatus::Skipped->value] ?? 0;
        $pending = $tally[UploadEntryStatus::Pending->value] ?? 0;

        $status = match (true) {
            // Berhenti di tengah dengan entri tersisa: masih ada yang bisa
            // dilanjutkan, dan statusnya harus mengatakannya.
            $error !== null && $pending > 0 => UploadStatus::Failed,
            $error !== null => UploadStatus::Partial,
            $failed > 0 && $posted > 0 => UploadStatus::Partial,
            $failed > 0 => UploadStatus::Failed,
            default => UploadStatus::Success,
        };

        $upload->forceFill([
            'status' => $status->value,
            'count_posted' => $posted,
            'count_failed' => $failed,
            'count_skipped' => $skipped,
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Entri Overtime yang baru masuk Kimai belum menjadi catatan lembur sampai
     * sync menariknya kembali, jadi sync dipicu di sini.
     *
     * Hanya kalau ada entri BERTAG yang benar-benar terkirim: upload yang isinya
     * Daily saja tidak menghasilkan apa pun yang akan ditarik sync, dan run kosong
     * di Riwayat Sync hanya membingungkan.
     */
    private function chainSync(TimesheetUpload $upload): void
    {
        $adaOvertime = $upload->entries()
            ->where('status', UploadEntryStatus::Posted->value)
            ->whereNotNull('tag')
            ->exists();

        if (! $adaOvertime || ! $upload->user->hasKimaiConnection()) {
            return;
        }

        if (SyncKimaiTimesheets::isRunningFor($upload->user) || SyncKimaiTimesheets::isPendingFor($upload->user)) {
            return;
        }

        SyncKimaiTimesheets::markPending($upload->user);
        SyncKimaiTimesheets::dispatch($upload->user);
    }
}
