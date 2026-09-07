<?php

namespace App\Filament\Resources\OvertimeRecords\Schemas;

use App\Models\OvertimeRecord;
use App\Support\Format;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

/** F-10 — detail record beserta audit trail sebagai timeline. */
class OvertimeRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lembur')
                ->columns(2)
                ->schema([
                    TextEntry::make('overtime_date')
                        ->label('Tanggal')
                        ->formatStateUsing(fn ($state) => Format::tanggalPanjang($state)),

                    TextEntry::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(fn ($state, OvertimeRecord $r) => Format::jam((string) $state).'–'.Format::jam((string) $r->end_time)),

                    TextEntry::make('duration_raw_minutes')
                        ->label('Durasi mentah')
                        ->formatStateUsing(fn ($state) => Format::durasi((int) $state)),

                    TextEntry::make('duration_effective_minutes')
                        ->label('Durasi efektif')
                        ->formatStateUsing(fn ($state, OvertimeRecord $r) => Format::durasi((int) $state)
                            .($r->rounding_applied ? ' (dibulatkan)' : '')),

                    TextEntry::make('tier')->label('Tier')->badge(),
                    TextEntry::make('status')->label('Status')->badge(),

                    TextEntry::make('meal_allowance_amount')
                        ->label('Uang makan')
                        ->formatStateUsing(fn ($state) => Format::rupiah((int) $state))
                        ->helperText('Estimasi, bukan perhitungan payroll resmi.'),

                    TextEntry::make('leave_credit_minutes')
                        ->label('Cuti pengganti')
                        ->formatStateUsing(fn ($state) => $state > 0 ? Format::saldo((int) $state) : '—'),

                    TextEntry::make('payrollPeriod.label')->label('Periode payroll'),

                    TextEntry::make('leaveBalance.expires_at')
                        ->label('Saldo berlaku sampai')
                        ->formatStateUsing(fn ($state) => $state ? Format::tanggalPanjang($state) : '—')
                        ->placeholder('—'),

                    TextEntry::make('work_description')->label('Deskripsi')->columnSpanFull(),

                    TextEntry::make('evidence_url')
                        ->label('Evidence')
                        ->url(fn (OvertimeRecord $r) => $r->evidence_url, shouldOpenInNewTab: true)
                        ->columnSpanFull(),

                    TextEntry::make('notes')->label('Catatan')->placeholder('—')->columnSpanFull(),
                ]),

            // F-10 — siapa, kapan, field apa, nilai lama, nilai baru.
            Section::make('Riwayat perubahan')
                ->description('Disimpan minimal 1 tahun sesuai SOP §10.')
                ->schema([
                    RepeatableEntry::make('activities')
                        ->label('')
                        ->state(fn (OvertimeRecord $record) => static::auditTrail($record))
                        ->schema([
                            TextEntry::make('when')->label('Waktu'),
                            TextEntry::make('who')->label('Oleh'),
                            TextEntry::make('what')->label('Perubahan')->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->placeholder('Belum ada perubahan tercatat.'),
                ]),
        ]);
    }

    /** @return array<int, array{when: string, who: string, what: string}> */
    public static function auditTrail(OvertimeRecord $record): array
    {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            // Dua perubahan dalam detik yang sama akan berurutan sembarang bila
            // hanya diurut waktu; id memberi urutan yang pasti.
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->map(function (Activity $activity) {
                // activitylog v5 memindahkan nilai lama/baru ke kolom khusus
                // `attribute_changes`; `properties` disisakan untuk data custom.
                $changesBag = $activity->attribute_changes ?? $activity->properties ?? [];
                $old = $changesBag['old'] ?? [];
                $new = $changesBag['attributes'] ?? [];

                $changes = collect($new)
                    ->map(fn ($value, $field) => sprintf(
                        '%s: %s → %s',
                        $field,
                        static::stringify($old[$field] ?? null),
                        static::stringify($value),
                    ))
                    ->values()
                    ->all();

                return [
                    'when' => $activity->created_at
                        ->timezone(config('app.display_timezone'))
                        ->translatedFormat('j M Y, H:i'),
                    'who' => $activity->causer?->name ?? 'Sistem',
                    'what' => $changes === []
                        ? ucfirst((string) $activity->event)
                        : implode(' · ', $changes),
                ];
            })
            ->all();
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
