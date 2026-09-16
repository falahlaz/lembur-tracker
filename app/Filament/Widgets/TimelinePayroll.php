<?php

namespace App\Filament\Widgets;

use App\Domain\Lembur\DashboardSummary;
use App\Domain\Lembur\PayrollPeriodResolver;
use App\Filament\Concerns\RefreshesAfterKimaiSync;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * F-03 — timeline periode payroll berjalan.
 *
 * Fungsinya menjawab satu pertanyaan yang sering muncul: "masih sempat nggak
 * saya catat lembur minggu lalu?" — tanpa user perlu menghitung tanggal sendiri.
 */
class TimelinePayroll extends Widget
{
    use RefreshesAfterKimaiSync;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.timeline-payroll';

    public function data(): array
    {
        $summary = app(DashboardSummary::class);
        $period = $summary->currentPeriod();

        $start = $period->period_start;
        $end = $period->period_end;

        $total = max(1, (int) $start->diffInDays($end));
        $elapsed = max(0, min($total, (int) $start->diffInDays(today())));

        return [
            'period' => $period,
            'daysLeft' => app(PayrollPeriodResolver::class)->daysUntilCutoff(),
            // Posisi penanda "hari ini" dalam persen, dijepit agar tidak keluar garis.
            'progress' => (int) round($elapsed / $total * 100),
            'unapproved' => $summary->unapprovedThisPeriod(Auth::user()),
        ];
    }
}
