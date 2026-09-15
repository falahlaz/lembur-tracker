<?php

namespace App\Filament\Resources\OvertimeRecords\Schemas;

use App\Models\OvertimeRecord;
use App\Support\AuditTrail;
use App\Support\Format;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                    ViewEntry::make('activities')
                        ->label('')
                        ->view('filament.infolists.audit-trail')
                        ->state(fn (OvertimeRecord $record) => AuditTrail::for($record)),
                ]),
        ]);
    }

    /**
     * Dipertahankan sebagai satu-satunya pintu masuk audit trail record ini;
     * isinya ada di App\Support\AuditTrail.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function auditTrail(OvertimeRecord $record): array
    {
        return AuditTrail::for($record);
    }
}
