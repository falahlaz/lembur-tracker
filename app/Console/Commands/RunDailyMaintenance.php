<?php

namespace App\Console\Commands;

use App\Domain\Lembur\BalanceMaintenance;
use Illuminate\Console\Command;

class RunDailyMaintenance extends Command
{
    protected $signature = 'lemburku:maintenance';

    protected $description = 'Menandai saldo yang hangus dan menuntaskan klaim yang tanggalnya sudah lewat (§9.3).';

    public function handle(BalanceMaintenance $maintenance): int
    {
        $result = $maintenance->run();

        $this->info(sprintf(
            '%d klaim dituntaskan, %d batch saldo ditandai hangus.',
            $result['settled'],
            $result['expired'],
        ));

        return self::SUCCESS;
    }
}
