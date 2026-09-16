<?php

namespace App\Filament\Widgets;

use App\Domain\Lembur\DashboardSummary;
use App\Filament\Concerns\RefreshesAfterKimaiSync;
use App\Models\LeaveBalance;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * P-4 — peringatan hadir sebelum terlambat, bukan sesudah.
 *
 * Muncul HANYA bila ada saldo yang hangus dalam <= 7 hari. Tidak ada banner
 * "semuanya aman": ruang kosong lebih baik daripada notifikasi kosong, dan
 * banner yang selalu ada berhenti dibaca orang.
 */
class BannerSaldoHangus extends Widget
{
    use RefreshesAfterKimaiSync;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.banner-saldo-hangus';

    public static function canView(): bool
    {
        return app(DashboardSummary::class)->expiringSoon(Auth::user())->isNotEmpty();
    }

    /** @return Collection<int, LeaveBalance> */
    public function balances(): Collection
    {
        return app(DashboardSummary::class)->expiringSoon(Auth::user());
    }
}
