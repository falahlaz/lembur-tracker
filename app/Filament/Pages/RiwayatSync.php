<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\SyncsWithKimai;
use App\Models\SyncRun;
use App\Support\Format;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * F-13 — "berhasil" saja tidak memberi tahu apa pun. Halaman ini menjawab
 * pertanyaan yang sebenarnya: apa yang berubah, dan kenapa yang itu dilewati.
 */
class RiwayatSync extends Page implements HasTable
{
    use InteractsWithTable;
    use SyncsWithKimai;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Riwayat Sync';

    protected static ?string $title = 'Riwayat Sync Kimai';

    protected string $view = 'filament.pages.riwayat-sync';

    /** Hanya relevan bagi user yang memang memakai Kimai. */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasKimaiConnection() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [$this->kimaiSyncAction()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SyncRun::query()
                ->where('user_id', Auth::id())
                ->with('items.overtimeRecord'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->formatStateUsing(fn ($state) => Format::tanggalRingkas($state)
                        .' '.$state->timezone(config('app.display_timezone'))->format('H:i')),

                TextColumn::make('trigger')->label('Jenis')->badge(),

                TextColumn::make('status')->label('Status')->badge(),

                TextColumn::make('range_start')
                    ->label('Rentang')
                    ->formatStateUsing(fn ($state, SyncRun $record) => Format::tanggalRingkas($state)
                        .' – '.Format::tanggalRingkas($record->range_end)),

                TextColumn::make('count_fetched')->label('Diambil')->alignEnd(),
                TextColumn::make('count_created')->label('Baru')->alignEnd(),
                TextColumn::make('count_updated')->label('Diperbarui')->alignEnd(),
                TextColumn::make('count_skipped')->label('Dilewati')->alignEnd(),
                TextColumn::make('count_failed')->label('Gagal')->alignEnd(),
            ])
            // §10 — run yang gagal menampilkan pesan yang dapat ditindaklanjuti,
            // bukan stack trace. error_message sudah berisi userMessage().
            ->recordUrl(null)
            ->paginated([10, 25])
            ->emptyStateHeading('Belum ada sync')
            ->emptyStateDescription('Tekan tombol Sync Kimai untuk menarik lembur pertama kamu.');
    }
}
