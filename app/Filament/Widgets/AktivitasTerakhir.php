<?php

namespace App\Filament\Widgets;

use App\Domain\Lembur\DashboardSummary;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** F-03 — lima entri terbaru dari lembur dan klaim, digabung menjadi satu aliran. */
class AktivitasTerakhir extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.aktivitas-terakhir';

    public function entries(): Collection
    {
        return app(DashboardSummary::class)->recentActivity(Auth::user());
    }
}
