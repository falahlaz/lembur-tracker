<?php

namespace App\Filament\Resources\OvertimeRules\Tables;

use App\Models\OvertimeRule;
use App\Support\Format;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OvertimeRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Berlaku dari')
                    ->formatStateUsing(fn ($state) => Format::tanggalPanjang($state))
                    ->sortable(),

                TextColumn::make('effective_to')
                    ->label('Sampai')
                    ->formatStateUsing(fn ($state) => $state ? Format::tanggalPanjang($state) : 'masih berlaku')
                    ->color(fn ($state) => $state ? 'gray' : 'success'),

                TextColumn::make('tier1_meal_amount')
                    ->label('Tier 1')
                    ->formatStateUsing(fn ($state, OvertimeRule $r) => Format::durasiRingkas($r->tier1_min_minutes)
                        .' → '.Format::rupiah((int) $state).' + '.Format::durasiRingkas($r->tier1_leave_minutes)),

                TextColumn::make('tier2_meal_amount')
                    ->label('Tier 2')
                    ->formatStateUsing(fn ($state, OvertimeRule $r) => Format::durasiRingkas($r->tier2_min_minutes)
                        .' → '.Format::rupiah((int) $state).' + '.Format::durasiRingkas($r->tier2_leave_minutes)),

                TextColumn::make('cutoff_day')->label('Cut-off')->alignEnd(),

                TextColumn::make('expiry_months')
                    ->label('Masa berlaku')
                    ->formatStateUsing(fn ($state) => $state.' bulan')
                    ->alignEnd(),

                TextColumn::make('monthly_claim_quota_days')
                    ->label('Kuota/bulan')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 1, ',', '.'), '0'), ',').' hari')
                    ->alignEnd(),

                TextColumn::make('overtime_records_count')
                    ->label('Dipakai')
                    ->counts('overtimeRecords')
                    ->formatStateUsing(fn ($state) => $state.' record')
                    ->alignEnd(),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Belum ada versi aturan')
            ->emptyStateDescription('Minimal satu versi harus ada agar lembur bisa dihitung.');
    }
}
