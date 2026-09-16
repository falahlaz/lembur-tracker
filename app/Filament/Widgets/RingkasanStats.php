<?php

namespace App\Filament\Widgets;

use App\Domain\Lembur\DashboardSummary;
use App\Filament\Concerns\RefreshesAfterKimaiSync;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * F-03 — empat stat tile. Angkanya adalah elemen terbesar di layar (P-1):
 * kalau user hanya sempat melihat tiga detik, saldo dan tanggal hangus yang
 * harus terbaca.
 */
class RingkasanStats extends StatsOverviewWidget
{
    use RefreshesAfterKimaiSync;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $summary = app(DashboardSummary::class);

        $activeMinutes = $summary->activeMinutes($user);
        $meal = $summary->mealEstimate($user);
        $quota = $summary->quota($user);
        $overtime = $summary->overtimeThisPeriod($user);
        $expiring = $summary->expiringSoon($user);

        return [
            Stat::make('Saldo cuti pengganti', Format::durasi($activeMinutes))
                ->description(Format::saldoManusiawi($activeMinutes))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($expiring->isNotEmpty() ? 'danger' : 'success')
                // Aksesibilitas §9 — angka besar diberi versi panjang untuk screen reader.
                ->extraAttributes([
                    'class' => 'tabular-nums',
                    'aria-label' => 'Saldo cuti pengganti '.Format::saldo($activeMinutes),
                ]),

            // BR-10 — dua angka bertingkat: yang besar harapan, yang kecil kepastian.
            Stat::make('Estimasi uang makan', Format::rupiah($meal->maximum))
                ->description($meal->pending() > 0
                    ? 'Sudah pasti '.Format::rupiah($meal->certain).' · sisanya menunggu approval'
                    : 'Semuanya sudah disetujui')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success')
                ->extraAttributes([
                    'class' => 'tabular-nums',
                    'title' => 'Estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.',
                ]),

            Stat::make('Kuota bulan ini', self::trim($quota['used']).' / '.self::trim($quota['limit']).' hari')
                ->description('terpakai bulan ini')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($quota['used'] >= $quota['limit'] ? 'warning' : 'gray')
                ->extraAttributes(['class' => 'tabular-nums']),

            Stat::make('Lembur periode ini', (string) $overtime['count'])
                ->description(Format::durasi($overtime['minutes']).' total')
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray')
                ->extraAttributes(['class' => 'tabular-nums']),
        ];
    }

    /** "1,5" dan "3" — bukan "1.5" dan "3.0". */
    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
