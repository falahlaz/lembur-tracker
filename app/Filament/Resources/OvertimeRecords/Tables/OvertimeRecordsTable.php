<?php

namespace App\Filament\Resources\OvertimeRecords\Tables;

use App\Domain\Lembur\PayrollPeriodResolver;
use App\Enums\OvertimeStatus;
use App\Enums\Tier;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Support\Format;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use App\Domain\Lembur\DurationCalculator;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** F-05 — history lembur: bisa difilter, dan setiap angka bisa ditelusuri. */
class OvertimeRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('overtime_date', 'desc')
            ->columns([
                // Design Brief §7 — Split menyusun mendatar sejak 640px dan menumpuk
                // di bawahnya, sehingga tabel berubah jadi daftar kartu di HP.
                // Yang tampak di muka kartu persis empat hal: tanggal, jam, durasi,
                // tier dan status. Sisanya dibuka lewat tap.
                Split::make([
                    TextColumn::make('overtime_date')
                        ->label('Tanggal')
                        ->formatStateUsing(fn ($state) => Format::tanggalRingkas($state))
                        ->description(fn (OvertimeRecord $r) => $r->overtime_date->translatedFormat('Y'))
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(fn ($state, OvertimeRecord $r) => Format::jam((string) $state).'–'.Format::jam((string) $r->end_time))
                        ->description(fn (OvertimeRecord $r) => DurationCalculator::crossesMidnight((string) $r->start_time, (string) $r->end_time)
                            ? 'lewat tengah malam'
                            : null)
                        ->grow(false),

                    TextColumn::make('duration_effective_minutes')
                        ->label('Durasi')
                        ->formatStateUsing(fn ($state) => Format::durasiRingkas((int) $state))
                        // BR-04 — indikator visual untuk record yang durasinya dibulatkan.
                        ->icon(fn (OvertimeRecord $r) => $r->rounding_applied ? 'heroicon-m-arrows-right-left' : null)
                        ->tooltip(fn (OvertimeRecord $r) => $r->rounding_applied
                            ? Format::durasi($r->duration_raw_minutes).' dibulatkan jadi '.Format::durasi($r->duration_effective_minutes)
                            : null)
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('tier')
                        ->label('Tier')
                        ->badge()
                        ->grow(false),

                    TextColumn::make('meal_allowance_amount')
                        ->label('Uang makan')
                        ->formatStateUsing(fn ($state) => $state > 0 ? Format::rupiah((int) $state) : '—')
                        ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                        ->weight('medium')
                        ->summarize(Sum::make()
                            ->label('Total')
                            ->formatStateUsing(fn ($state) => Format::rupiah((int) $state))),

                    TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->grow(false),
                ])->from('sm'),

                Panel::make([
                    Stack::make([
                        TextColumn::make('duration_raw_minutes')
                            ->label('Durasi mentah')
                            ->formatStateUsing(fn ($state) => 'Durasi mentah '.Format::durasi((int) $state))
                            ->color('gray')
                            ->size('sm'),

                        TextColumn::make('leave_credit_minutes')
                            ->label('Saldo diperoleh')
                            ->formatStateUsing(fn ($state) => $state > 0
                                ? 'Saldo diperoleh '.Format::saldo((int) $state)
                                : 'Tidak menghasilkan saldo')
                            ->color('gray')
                            ->size('sm'),

                        TextColumn::make('payrollPeriod.label')
                            ->label('Periode payroll')
                            ->formatStateUsing(fn ($state) => 'Periode '.$state)
                            ->color('gray')
                            ->size('sm'),

                        TextColumn::make('work_description')
                            ->label('Deskripsi')
                            ->limit(120)
                            ->wrap()
                            ->size('sm'),

                        TextColumn::make('evidence_url')
                            ->label('Evidence')
                            ->formatStateUsing(fn () => 'Buka evidence')
                            ->url(fn (OvertimeRecord $r) => $r->evidence_url, shouldOpenInNewTab: true)
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->color('primary')
                            ->size('sm'),
                    ])->space(1),
                ])->collapsible(),
            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(OvertimeStatus::class),

                SelectFilter::make('tier')
                    ->label('Tier')
                    ->options([
                        Tier::None->value => Tier::None->getLabel(),
                        Tier::Tier1->value => Tier::Tier1->getLabel(),
                        Tier::Tier2->value => Tier::Tier2->getLabel(),
                    ]),

                SelectFilter::make('payroll_period_id')
                    ->label('Periode payroll')
                    ->options(fn () => PayrollPeriod::query()
                        ->orderByDesc('period_end')
                        ->pluck('label', 'id')),

                Filter::make('rentang_tanggal')
                    ->schema([
                        DatePicker::make('dari')->label('Dari tanggal')->native(false),
                        DatePicker::make('sampai')->label('Sampai tanggal')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $d) => $q->whereDate('overtime_date', '>=', $d))
                        ->when($data['sampai'] ?? null, fn ($q, $d) => $q->whereDate('overtime_date', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['dari'] ?? null) {
                            $indicators[] = 'Dari '.Format::tanggalPanjang(\Illuminate\Support\Facades\Date::parse($data['dari']));
                        }
                        if ($data['sampai'] ?? null) {
                            $indicators[] = 'Sampai '.Format::tanggalPanjang(\Illuminate\Support\Facades\Date::parse($data['sampai']));
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada lembur tercatat')
            ->emptyStateDescription('Catat lembur pertamamu untuk mulai menghitung uang makan dan cuti pengganti.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
