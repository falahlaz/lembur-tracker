<?php

namespace App\Filament\Pages;

use App\Enums\BalanceStatus;
use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use App\Filament\Resources\LeaveClaims\Tables\LeaveClaimsTable;
use App\Models\LeaveBalance;
use App\Support\Format;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * IA Design Brief §3 — satu halaman, tiga tab. User memikirkan "cuti pengganti"
 * sebagai satu hal: saldo saya, klaim saya, dan yang sudah terlanjur hangus.
 * Memecahnya jadi dua item navigasi memaksa mereka menyusun sendiri gambarannya.
 */
class CutiPengganti extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Pencatatan';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Cuti Pengganti';

    protected static ?string $title = 'Cuti Pengganti';

    protected string $view = 'filament.pages.cuti-pengganti';

    public string $tab = 'aktif';

    public function getTabs(): array
    {
        return [
            'aktif' => 'Saldo aktif',
            'riwayat' => 'Riwayat klaim',
            'hangus' => 'Saldo hangus',
        ];
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, $this->getTabs()) ? $tab : 'aktif';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ajukan')
                ->label('Ajukan Klaim')
                ->icon('heroicon-m-plus')
                ->url(fn () => LeaveClaimResource::getUrl('create')),
        ];
    }

    /** Tab "Riwayat klaim" memakai tabel yang sama dengan resource-nya. */
    public function table(Table $table): Table
    {
        return LeaveClaimsTable::configure(
            $table->query(fn () => LeaveClaimResource::getEloquentQuery())
        );
    }

    /** @return Collection<int, LeaveBalance> */
    public function activeBalances(): Collection
    {
        return $this->balanceQuery()
            ->whereIn('status', [BalanceStatus::Active->value, BalanceStatus::PartiallyUsed->value])
            ->orderBy('expires_at')
            ->orderBy('earned_date')
            ->get()
            ->filter(fn (LeaveBalance $b) => $b->remainingMinutes() > 0)
            ->values();
    }

    /** @return Collection<int, LeaveBalance> */
    public function expiredBalances(): Collection
    {
        return $this->balanceQuery()
            ->whereIn('status', [BalanceStatus::Expired->value, BalanceStatus::Void->value])
            ->orderByDesc('expires_at')
            ->get();
    }

    /**
     * Kalimat pembuka tab "Saldo hangus". Keras, dan memang harus — angka itulah
     * alasan sistem ini dibangun.
     */
    public function wastedSummary(): ?string
    {
        $expired = $this->expiredBalances()
            ->where('status', BalanceStatus::Expired)
            ->filter(fn (LeaveBalance $b) => $b->expires_at->year === (int) today()->year);

        $minutes = (int) $expired->sum(fn (LeaveBalance $b) => $b->remainingMinutes());

        if ($minutes <= 0) {
            return null;
        }

        return sprintf(
            'Sepanjang %d kamu kehilangan %s cuti pengganti karena lewat masa berlaku.',
            today()->year,
            Format::durasi($minutes),
        );
    }

    public function totalActiveMinutes(): int
    {
        return (int) $this->activeBalances()->sum(fn (LeaveBalance $b) => $b->remainingMinutes());
    }

    private function balanceQuery(): Builder
    {
        $user = Auth::user();

        return LeaveBalance::query()
            ->with('overtimeRecord')
            ->when(! $user?->isAdmin(), fn ($q) => $q->where('user_id', $user?->id));
    }
}
