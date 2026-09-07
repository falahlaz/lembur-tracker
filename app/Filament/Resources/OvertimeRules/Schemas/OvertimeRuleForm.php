<?php

namespace App\Filament\Resources\OvertimeRules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * BR-24 — mengelola versi aturan.
 *
 * Nilai disimpan dalam MENIT, bukan jam, karena itulah satuan yang dipakai
 * seluruh perhitungan; mengubahnya jadi jam di sini hanya akan menambah satu
 * konversi yang bisa salah.
 */
class OvertimeRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Masa berlaku')
                ->description('Aturan dipilih berdasarkan TANGGAL LEMBUR, sehingga data lama tidak ikut berubah saat SOP direvisi.')
                ->columns(2)
                ->schema([
                    DatePicker::make('effective_from')
                        ->label('Berlaku dari')
                        ->native(false)
                        ->required(),

                    DatePicker::make('effective_to')
                        ->label('Berlaku sampai')
                        ->native(false)
                        ->afterOrEqual('effective_from')
                        ->helperText('Kosongkan bila masih berlaku sampai sekarang.'),
                ]),

            Section::make('Tier 1')
                ->columns(3)
                ->schema([
                    TextInput::make('tier1_min_minutes')->label('Minimal (menit)')->numeric()->required()->default(240),
                    TextInput::make('tier1_meal_amount')->label('Uang makan (Rp)')->numeric()->required()->default(50000),
                    TextInput::make('tier1_leave_minutes')->label('Cuti pengganti (menit)')->numeric()->required()->default(240),
                ]),

            Section::make('Tier 2')
                ->description('Capped, tidak bertingkat: lembur 12 jam tetap memperoleh tier 2 (BR-06).')
                ->columns(3)
                ->schema([
                    TextInput::make('tier2_min_minutes')->label('Minimal (menit)')->numeric()->required()->default(480),
                    TextInput::make('tier2_meal_amount')->label('Uang makan (Rp)')->numeric()->required()->default(100000),
                    TextInput::make('tier2_leave_minutes')->label('Cuti pengganti (menit)')->numeric()->required()->default(480),
                ]),

            Section::make('Periode & kuota')
                ->columns(3)
                ->schema([
                    TextInput::make('cutoff_day')
                        ->label('Tanggal cut-off')
                        ->numeric()->minValue(1)->maxValue(28)->required()->default(19)
                        ->helperText('Maksimal 28 supaya ada di setiap bulan.'),

                    TextInput::make('expiry_months')
                        ->label('Masa berlaku saldo (bulan)')
                        ->numeric()->minValue(1)->maxValue(24)->required()->default(1),

                    TextInput::make('monthly_claim_quota_days')
                        ->label('Kuota klaim per bulan (hari)')
                        ->numeric()->step(0.5)->minValue(0.5)->required()->default(3.0),
                ]),

            Section::make('Jam kerja normal')
                ->columns(2)
                ->schema([
                    TimePicker::make('work_start_time')->label('Mulai')->seconds(false)->required()->default('09:00'),
                    TimePicker::make('work_end_time')->label('Selesai')->seconds(false)->required()->default('18:00'),
                ]),

            Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
        ]);
    }
}
