<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AktivitasTerakhir;
use App\Filament\Widgets\BannerSaldoHangus;
use App\Filament\Widgets\RingkasanStats;
use App\Filament\Widgets\SaldoAktifTable;
use App\Filament\Widgets\TimelinePayroll;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

/**
 * F-03 — urutan widget mengikuti URGENSI, bukan urutan logis data:
 * peringatan lebih dulu, lalu angka, lalu konteks waktu, lalu detail.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Beranda';

    public function getTitle(): string
    {
        return 'Halo, '.(Auth::user()?->name ?? '');
    }

    public function getSubheading(): ?string
    {
        // P-5 / R-1 — jujur soal batasnya, di layar yang menampilkan nominal.
        return 'Angka rupiah di halaman ini estimasi berdasarkan catatanmu, bukan perhitungan payroll resmi.';
    }

    public function getWidgets(): array
    {
        return [
            BannerSaldoHangus::class,
            RingkasanStats::class,
            TimelinePayroll::class,
            SaldoAktifTable::class,
            AktivitasTerakhir::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
