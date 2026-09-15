<?php

namespace App\Console\Commands;

use App\Domain\Kimai\OvertimeSession;
use App\Domain\Kimai\SessionGrouper;
use App\Domain\Kimai\SessionWriter;
use App\Enums\Source;
use App\Models\OvertimeRecord;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SY-23 — membereskan record yang terlanjur masuk dengan skema lama, ketika satu
 * entri timesheet Kimai selalu menjadi satu record.
 *
 * Kimai membatasi satu timesheet maksimal 2 jam, sehingga lembur 8 jam dulu tercatat
 * sebagai empat record — dan yang melewati tengah malam bahkan tersebar di dua
 * tanggal, dengan tier yang salah di keduanya. Sync sendiri sebenarnya menyerap
 * record lama begitu entrinya ditarik lagi, tetapi rentang fetch hanya mundur sampai
 * awal periode payroll berjalan; apa pun yang lebih tua tidak akan pernah ditanyakan
 * lagi. Command ini yang menjangkaunya, bekerja murni dari database tanpa menyentuh
 * Kimai sama sekali.
 *
 * Aturannya TIDAK diduplikasi di sini: pengelompokan tetap milik SessionGrouper dan
 * penulisannya tetap milik SessionWriter, termasuk seluruh pagarnya — record yang
 * sudah disentuh manusia (SY-14), yang sudah diajukan, dan yang saldonya sudah
 * dipakai klaim (BR-23) tidak akan tersentuh.
 */
class RegroupKimaiRecords extends Command
{
    protected $signature = 'lemburku:kimai:regroup
        {--user= : Batasi ke satu user (id atau email)}
        {--dry-run : Tampilkan rencananya saja, tanpa menulis apa pun}';

    protected $description = 'Menggabungkan record lembur warisan sync 1:1 menjadi satu sesi (SY-23).';

    public function handle(SessionGrouper $grouper, SessionWriter $writer): int
    {
        $users = $this->targetUsers();

        if ($users->isEmpty()) {
            $this->warn('Tidak ada user dengan lembur bersumber Kimai.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Mode --dry-run: tidak ada satu baris pun yang ditulis.');
        }

        $touched = 0;

        foreach ($users as $user) {
            $touched += $this->regroup($user, $grouper, $writer, $dryRun);
        }

        $this->newLine();
        $this->info($dryRun
            ? "Selesai. {$touched} sesi akan digabung."
            : "Selesai. {$touched} sesi digabung.");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function targetUsers()
    {
        $query = User::query()->whereHas('overtimeRecords', fn ($q) => $q->where('source', Source::Kimai->value));

        if (filled($option = $this->option('user'))) {
            $query->where(fn ($q) => $q->where('id', $option)->orWhere('email', $option));
        }

        return $query->orderBy('id')->get();
    }

    private function regroup(User $user, SessionGrouper $grouper, SessionWriter $writer, bool $dryRun): int
    {
        // Menjalankan ini bersamaan dengan sync berarti dua penulis memperebutkan
        // record yang sama, dan yang kalah akan menulis di atas keadaan yang sudah basi.
        if (SyncRun::query()->where('user_id', $user->id)->active()->exists()) {
            $this->warn("{$user->email}: dilewati, ada sync yang sedang berjalan.");

            return 0;
        }

        $records = OvertimeRecord::query()
            ->with(['kimaiEntries', 'leaveBalance'])
            ->where('user_id', $user->id)
            ->where('source', Source::Kimai)
            ->orderBy('overtime_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $timesheets = $records->flatMap(fn (OvertimeRecord $r) => $r->toKimaiTimesheets())->all();

        if ($timesheets === []) {
            return 0;
        }

        // Tanpa `knownGroupKeys`: seluruh riwayat user ada di tangan, jadi pembuka
        // jendela mana pun pasti ikut terbaca dari daftar ini sendiri.
        $sessions = $grouper->group($timesheets);

        $this->line("<options=bold>{$user->email}</> — ".count($records).' record, '.count($sessions).' sesi');

        $changed = 0;

        foreach ($sessions as $session) {
            $related = $writer->relatedRecords($user, $session);

            if (! $this->needsRegrouping($session, $related)) {
                continue;
            }

            if ($dryRun) {
                $this->reportPlan($session, $related);
                $changed++;

                continue;
            }

            $claimedElsewhere = $grouper->claimedElsewhere($sessions, $session);

            $result = DB::transaction(fn () => $writer->write($user, $session, $claimedElsewhere));

            if ($result->wasSkipped()) {
                $this->line("  <fg=yellow>dilewati</> {$session->groupKey} — {$result->reason}");

                continue;
            }

            $this->reportPlan($session, $related);
            $changed++;
        }

        return $changed;
    }

    /**
     * Sesi yang sudah berbentuk benar dibiarkan apa adanya — command ini boleh
     * dijalankan berkali-kali tanpa menyentuh record yang tidak perlu disentuh.
     */
    private function needsRegrouping(OvertimeSession $session, $related): bool
    {
        if ($related->count() !== 1) {
            return true;
        }

        $record = $related->first();

        return $record->kimai_group_key !== $session->groupKey
            || $record->kimaiEntries->count() !== count($session->entries);
    }

    private function reportPlan(OvertimeSession $session, $related): void
    {
        $from = $related->map(fn (OvertimeRecord $r) => '#'.$r->id)->implode(', ');

        $this->line(sprintf(
            '  <fg=green>%s</> %s %s–%s · %d menit · dari %s',
            $session->groupKey,
            $session->anchorDate->toDateString(),
            $session->startTime(),
            $session->endTime(),
            $session->durationMinutes(),
            $from !== '' ? $from : 'baru',
        ));
    }
}
