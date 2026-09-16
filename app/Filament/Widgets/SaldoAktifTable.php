<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesAfterKimaiSync;
use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use App\Models\LeaveBalance;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** F-03 — tabel batch saldo aktif: tanggal lembur, sisa, hangus, sisa waktu. */
class SaldoAktifTable extends TableWidget
{
    use RefreshesAfterKimaiSync;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Saldo aktif';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => LeaveBalance::query()
                ->where('user_id', Auth::id())
                ->whereIn('status', ['active', 'partially_used'])
                // Ditulis sebagai penjumlahan, bukan pengurangan: kolomnya UNSIGNED,
                // dan di MySQL selisih yang sempat negatif melempar "BIGINT UNSIGNED
                // value is out of range" — galat yang tidak pernah muncul di SQLite.
                ->whereColumn('earned_minutes', '>', DB::raw('consumed_minutes + held_minutes'))
                ->orderBy('expires_at')
                ->orderBy('earned_date'))
            ->columns([
                TextColumn::make('earned_date')
                    ->label('Lembur')
                    ->formatStateUsing(fn ($state) => Format::tanggalRingkas($state)),

                TextColumn::make('earned_minutes')
                    ->label('Sisa')
                    ->formatStateUsing(fn ($state, LeaveBalance $b) => Format::durasiRingkas($b->remainingMinutes()))
                    ->description(fn (LeaveBalance $b) => Format::saldoManusiawi($b->remainingMinutes()))
                    ->alignEnd(),

                TextColumn::make('expires_at')
                    ->label('Hangus')
                    ->formatStateUsing(fn ($state) => Format::tanggalRingkas($state)),

                // Dot berwarna + teks — status tidak pernah disampaikan lewat warna saja.
                TextColumn::make('sisa_waktu')
                    ->label('Sisa waktu')
                    ->state(fn (LeaveBalance $b) => Format::sisaWaktu($b->daysUntilExpiry()))
                    ->badge()
                    ->color(fn (LeaveBalance $b) => match (true) {
                        $b->daysUntilExpiry() <= 7 => 'danger',
                        $b->daysUntilExpiry() <= 14 => 'warning',
                        default => 'success',
                    }),
            ])
            ->recordActions([
                Action::make('pakai')
                    ->label('Pakai')
                    ->icon('heroicon-m-arrow-right')
                    ->url(fn () => LeaveClaimResource::getUrl('create')),
            ])
            ->paginated(false)
            ->emptyStateHeading('Belum ada saldo aktif')
            ->emptyStateDescription('Saldo muncul otomatis dari lembur minimal 4 jam.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
