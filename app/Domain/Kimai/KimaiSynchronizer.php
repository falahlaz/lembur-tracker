<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Lembur\BalanceMaintenance;
use App\Domain\Lembur\DurationCalculator;
use App\Enums\OvertimeStatus;
use App\Enums\Source;
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
 * SY-19 — setiap entri diproses dalam transaksinya sendiri, sehingga satu entri
 * bermasalah tidak menggagalkan seluruh batch dan job boleh diulang kapan saja.
 *
 * Record ditulis lewat OvertimeRecord::create()/save() biasa, BUKAN query
 * langsung: dengan begitu OvertimeRecordObserver tetap jalan dan
 * OvertimeDayCalculator yang menghitung tier, uang makan, dan saldo (BR-02/07).
 * Sync tidak pernah menyentuh satu pun kolom hasil hitungan.
 */
class KimaiSynchronizer
{
    /**
     * SY-14 — menandai bahwa tulisan yang sedang terjadi berasal dari sync, bukan
     * dari manusia, sehingga observer tidak menyalakan `locally_modified`.
     * Meniru guard OvertimeDayCalculator::isRecalculating().
     */
    private static bool $syncing = false;

    public function __construct(
        private readonly KimaiClient $client,
        private readonly SyncRangeResolver $ranges,
        private readonly BalanceMaintenance $maintenance,
    ) {}

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

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
        } catch (KimaiException $e) {
            $this->fail($run, $e->userMessage());

            throw $e;
        }

        $eligible = $this->filter($entries, $run);
        $run->count_fetched = count($entries);

        foreach ($eligible as $entry) {
            $this->processOne($user, $run, $entry);
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

    private function processOne(User $user, SyncRun $run, KimaiTimesheet $entry): void
    {
        try {
            self::$syncing = true;

            DB::transaction(function () use ($user, $run, $entry) {
                $existing = OvertimeRecord::query()
                    ->where('user_id', $user->id)
                    ->where('kimai_timesheet_id', $entry->id)
                    ->first();

                $existing === null
                    ? $this->createFrom($user, $run, $entry)
                    : $this->updateFrom($run, $entry, $existing);
            });
        } catch (Throwable $e) {
            // §10 — entri gagal ditandai, job lanjut; run berakhir `partial`.
            $this->record($run, $entry, SyncAction::Failed, mb_substr($e->getMessage(), 0, 200));
        } finally {
            self::$syncing = false;
        }
    }

    private function createFrom(User $user, SyncRun $run, KimaiTimesheet $entry): void
    {
        // SY-15 — dua catatan yang bertabrakan untuk jam yang sama adalah hal yang
        // harus dilihat manusia. Sistem tidak menebak mana yang benar.
        //
        // Pemeriksaan ini WAJIB di sini: aturan overlap F-02 hanya hidup sebagai
        // validasi form Filament, jadi tulisan programatik tidak terlindungi.
        $clash = $this->findClash($user, $entry);

        if ($clash !== null) {
            $this->record($run, $entry, SyncAction::Skipped, "bentrok dengan catatan manual #{$clash->id}");

            return;
        }

        $record = new OvertimeRecord;
        $record->fill([
            'user_id' => $user->id,
            'overtime_date' => $entry->overtimeDate()->toDateString(),
            'start_time' => $entry->startTime(),
            'end_time' => $entry->endTime(),
            'work_description' => $entry->workDescription(),
            'evidence_url' => $entry->evidenceUrl(),
            'status' => OvertimeStatus::Recorded,
            'source' => Source::Kimai,
            'kimai_timesheet_id' => $entry->id,
            'kimai_project_id' => $entry->projectId,
            'kimai_activity_id' => $entry->activityId,
            'break_minutes' => $entry->breakMinutes(),
            // SY-12 — deep link Kimai membuktikan jam kerja, tetapi SOP §6 tetap
            // mewajibkan SPL dan timesheet. Link ini pengisi sementara.
            'evidence_needs_review' => true,
        ]);

        $this->stampKimai($record, $entry);
        $record->save();

        $this->record($run, $entry, SyncAction::Created, null, $record);
    }

    private function updateFrom(SyncRun $run, KimaiTimesheet $entry, OvertimeRecord $record): void
    {
        // SY-14 — sekali user menyentuhnya, sync tidak pernah menimpanya lagi.
        if ($record->locally_modified) {
            $this->record($run, $entry, SyncAction::Skipped, 'ada perubahan lokal', $record);

            return;
        }

        if ($record->status !== OvertimeStatus::Recorded) {
            $this->record($run, $entry, SyncAction::Skipped, 'sudah diajukan/disetujui', $record);

            return;
        }

        $record->fill([
            'overtime_date' => $entry->overtimeDate()->toDateString(),
            'start_time' => $entry->startTime(),
            'end_time' => $entry->endTime(),
            'work_description' => $entry->workDescription(),
            'kimai_project_id' => $entry->projectId,
            'kimai_activity_id' => $entry->activityId,
            'break_minutes' => $entry->breakMinutes(),
        ]);

        $this->stampKimai($record, $entry);

        // `synced_at` selalu berubah, jadi ia tidak boleh ikut menentukan
        // "identik" — kalau ikut, tidak akan pernah ada entri `tidak berubah`.
        $changed = collect($record->getDirty())->except(['synced_at'])->isNotEmpty();

        if (! $changed) {
            $record->save();
            $this->record($run, $entry, SyncAction::Unchanged, null, $record);

            return;
        }

        $record->save();
        $this->record($run, $entry, SyncAction::Updated, null, $record);
    }

    /**
     * SY-10 — kolom hasil hitungan tidak fillable, jadi durasi dari Kimai
     * ditempelkan eksplisit di luar fill().
     */
    private function stampKimai(OvertimeRecord $record, KimaiTimesheet $entry): void
    {
        $record->kimai_duration_minutes = $entry->durationMinutes();
        $record->synced_at = now();
    }

    /** SY-15 — bentrok jam dengan record lain di tanggal yang sama (aturan F-02). */
    private function findClash(User $user, KimaiTimesheet $entry): ?OvertimeRecord
    {
        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('overtime_date', $entry->overtimeDate()->toDateString())
            ->get()
            ->first(fn (OvertimeRecord $r) => DurationCalculator::overlaps(
                $entry->startTime(),
                $entry->endTime(),
                (string) $r->start_time,
                (string) $r->end_time,
            ));
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
        $tally = $run->tally();

        $run->count_created = $tally[SyncAction::Created->value] ?? 0;
        $run->count_updated = $tally[SyncAction::Updated->value] ?? 0;
        $run->count_skipped = $tally[SyncAction::Skipped->value] ?? 0;
        $run->count_failed = $tally[SyncAction::Failed->value] ?? 0;
        $run->status = $run->count_failed > 0 ? SyncStatus::Partial : SyncStatus::Success;
        $run->finished_at = now();
        $run->save();
    }

    private function fail(SyncRun $run, string $message): void
    {
        $run->status = SyncStatus::Failed;
        $run->error_message = $message;
        $run->finished_at = now();
        $run->save();
    }
}
