<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Lembur\BalanceMaintenance;
use App\Enums\SyncAction;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\OvertimeRecord;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orkestrator sync. Menjalankan alur PRD §8 langkah 1–8.
 *
 * SY-23 — unit kerjanya SESI, bukan entri. Kimai membatasi satu timesheet maksimal
 * 2 jam, jadi lembur 8 jam datang sebagai empat entri; kalau masing-masing jadi
 * record sendiri, yang melewati tengah malam pecah ke dua tanggal dan tier-nya salah.
 * SessionGrouper yang memutuskan entri mana milik sesi mana, dan SessionWriter yang
 * memetakannya ke record beserta seluruh pagarnya. Yang tersisa di sini adalah
 * urusan satu kali jalan: rentang, fetch, saringan, dan pelaporan.
 *
 * SY-19 — setiap SESI diproses dalam transaksinya sendiri, sehingga satu sesi
 * bermasalah tidak menggagalkan seluruh batch dan job boleh diulang kapan saja.
 */
class KimaiSynchronizer
{
    public function __construct(
        private readonly KimaiClient $client,
        private readonly SyncRangeResolver $ranges,
        private readonly SessionGrouper $grouper,
        private readonly SessionWriter $writer,
        private readonly BalanceMaintenance $maintenance,
    ) {}

    public function run(User $user, SyncTrigger $trigger = SyncTrigger::Manual): SyncRun
    {
        $range = $this->ranges->for($user);

        $run = SyncRun::query()->create([
            'user_id' => $user->id,
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'range_start' => $range->begin,
            'range_end' => $range->end,
        ]);

        $run->started_at = now();
        $run->save();

        try {
            $entries = $this->client->timesheets((string) $user->kimai_api_token, $range->begin, $range->end);

            $eligible = $this->filter($entries, $run);
            $run->count_fetched = count($entries);

            $sessions = $this->grouper->group($eligible, $this->knownGroupKeys($user, $range));

            foreach ($sessions as $session) {
                $this->processSession($user, $run, $session, $this->grouper->claimedElsewhere($sessions, $session));
            }
        } catch (KimaiException $e) {
            $this->fail($run, $e->userMessage());

            throw $e;
        } catch (Throwable $e) {
            // Apa pun yang tidak terduga tetap harus menutup run-nya. Kalau tidak,
            // baris ini tertinggal berstatus `running` selamanya dan tombol sync
            // terkunci permanen — kegagalan yang jauh lebih menyebalkan daripada
            // kegagalan sync itu sendiri.
            $this->fail($run, 'Sync berhenti karena kesalahan tak terduga. Coba lagi, atau hubungi admin.');

            throw $e;
        }

        // Batch yang masa berlakunya sudah lewat ditandai hangus sekarang juga,
        // supaya tidak sempat tampil sebagai saldo aktif yang menyesatkan —
        // perilaku yang sama dengan HistoricalImporter::commit().
        $this->maintenance->markExpiredBatches();

        // Watermark hanya bergerak setelah seluruh entri diproses. Kalau job mati
        // di tengah jalan, rentang yang sama ditarik ulang pada run berikutnya —
        // aman karena dedup (SY-13) membuatnya idempoten.
        $user->kimai_synced_through = $range->end->toDateString();
        $user->kimai_token_valid_at = now();
        $user->save();

        $this->close($run);
        SyncRun::pruneFor($user->id);

        return $run->refresh();
    }

    /**
     * SY-05/06/07 — saringan sisi aplikasi. Filter tag dikirim ke API DAN
     * diperiksa ulang di sini: perilaku filter tag berbeda antar versi Kimai dan
     * pernah menjadi bug, jadi kalau filter server longgar, yang ini tetap menahan.
     *
     * @param  array<int, KimaiTimesheet>  $entries
     * @return array<int, KimaiTimesheet>
     */
    private function filter(array $entries, SyncRun $run): array
    {
        $tags = (array) config('kimai.tags');
        $keep = [];

        foreach ($entries as $entry) {
            if (! $entry->hasAnyTag($tags)) {
                continue;
            }

            // SY-06 — timer masih jalan. Bukan error; akan tertangkap pada sync
            // berikutnya setelah timer dihentikan.
            if ($entry->isRunning()) {
                continue;
            }

            if ($entry->durationMinutes() <= 0) {
                $this->record($run, $entry, SyncAction::Skipped, 'durasi tidak valid');

                continue;
            }

            $keep[] = $entry;
        }

        return $keep;
    }

    /**
     * SY-23 — pembuka jendela yang sudah tersimpan dari sync sebelumnya.
     *
     * Tanpa ini, sync yang memajukan watermark lewat tengah malam membuat entri pagi
     * berikutnya kehilangan jejak sesinya: jendela "kemarin 18:00 → hari ini 09:00"
     * tidak terlihat terbuka karena pembukanya tidak ikut ditarik lagi, dan lembur
     * dini hari itu salah mendarat sebagai record sendiri.
     *
     * Batas bawahnya mundur satu hari justru untuk menjangkau anchor yang lebih tua
     * dari `range_start`.
     *
     * @return array<int, string>
     */
    private function knownGroupKeys(User $user, SyncRange $range): array
    {
        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereNotNull('kimai_group_key')
            ->whereBetween('overtime_date', [
                $range->begin->subDay()->toDateString(),
                $range->end->toDateString(),
            ])
            ->pluck('kimai_group_key')
            ->all();
    }

    private function processSession(User $user, SyncRun $run, OvertimeSession $session, array $claimedElsewhere): void
    {
        try {
            $result = DB::transaction(fn () => $this->writer->write($user, $session, $claimedElsewhere));

            $this->logSession($run, $session, $result->action, $result->reason, $result->record);
        } catch (Throwable $e) {
            // §10 — sesi gagal ditandai, job lanjut; run berakhir `partial`.
            $this->logSession($run, $session, SyncAction::Failed, mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** Satu baris jejak per entri timesheet yang benar-benar ditarik run ini. */
    private function logSession(
        SyncRun $run,
        OvertimeSession $session,
        SyncAction $action,
        ?string $reason = null,
        ?OvertimeRecord $record = null,
    ): void {
        foreach ($session->entries as $entry) {
            $this->record($run, $entry, $action, $reason, $record);
        }
    }

    private function record(
        SyncRun $run,
        KimaiTimesheet $entry,
        SyncAction $action,
        ?string $reason = null,
        ?OvertimeRecord $record = null,
    ): void {
        $run->items()->create([
            'kimai_timesheet_id' => $entry->id,
            'action' => $action,
            'reason' => $reason,
            'overtime_record_id' => $record?->id,
            'overtime_date' => $entry->overtimeDate()->toDateString(),
            'duration_minutes' => $entry->durationMinutes(),
        ]);
    }

    private function close(SyncRun $run): void
    {
        // SY-23 — `created`/`updated` dihitung per RECORD, bukan per baris item.
        // Empat entri Kimai yang melebur jadi satu lembur harus terbaca "1 lembur
        // baru", bukan "4 lembur baru"; yang dilewati dan yang gagal memang per entri.
        $run->count_created = $this->countRecords($run, SyncAction::Created);
        $run->count_updated = $this->countRecords($run, SyncAction::Updated);

        $tally = $run->tally();

        $run->count_skipped = $tally[SyncAction::Skipped->value] ?? 0;
        $run->count_failed = $tally[SyncAction::Failed->value] ?? 0;
        $run->status = $run->count_failed > 0 ? SyncStatus::Partial : SyncStatus::Success;
        $run->finished_at = now();
        $run->save();
    }

    private function countRecords(SyncRun $run, SyncAction $action): int
    {
        return $run->items()
            ->where('action', $action->value)
            ->whereNotNull('overtime_record_id')
            ->distinct()
            ->count('overtime_record_id');
    }

    /**
     * §10 — run yang macet melewati TTL kunci ditandai gagal, sehingga tombol
     * sync hidup kembali. Dipanggil saat UI memeriksa status, bukan lewat job
     * terjadwal: yang butuh jawabannya hanya layar yang sedang dibuka.
     */
    public static function failStaleRuns(User $user): void
    {
        SyncRun::query()
            ->where('user_id', $user->id)
            ->active()
            ->where('created_at', '<', now()->subSeconds((int) config('kimai.lock_ttl')))
            ->update([
                'status' => SyncStatus::Failed->value,
                'error_message' => 'Sync berhenti di tengah jalan dan tidak selesai. Coba jalankan lagi.',
                'finished_at' => now(),
            ]);
    }

    private function fail(SyncRun $run, string $message): void
    {
        $run->status = SyncStatus::Failed;
        $run->error_message = $message;
        $run->finished_at = now();
        $run->save();
    }
}
