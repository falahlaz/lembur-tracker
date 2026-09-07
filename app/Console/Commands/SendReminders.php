<?php

namespace App\Console\Commands;

use App\Domain\Lembur\ReminderDispatcher;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'lemburku:reminders {--jenis=semua : saldo|cutoff|ringkasan|semua}';

    protected $description = 'Mengirim reminder F-07: saldo akan hangus, cut-off mendekat, ringkasan periode.';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $jenis = $this->option('jenis');

        if (in_array($jenis, ['saldo', 'semua'], true)) {
            $this->info($dispatcher->sendExpiryReminders().' reminder saldo akan hangus terkirim.');
        }

        if (in_array($jenis, ['cutoff', 'semua'], true)) {
            $this->info($dispatcher->sendCutoffReminders().' reminder cut-off terkirim.');
        }

        if (in_array($jenis, ['ringkasan', 'semua'], true)) {
            $this->info($dispatcher->sendPeriodSummaries().' ringkasan periode terkirim.');
        }

        return self::SUCCESS;
    }
}
