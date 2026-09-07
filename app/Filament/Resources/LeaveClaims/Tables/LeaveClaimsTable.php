<?php

namespace App\Filament\Resources\LeaveClaims\Tables;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\LeaveClaim;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** F-06 — history klaim; setiap baris bisa dibuka jadi ledger alokasi (G-7). */
class LeaveClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('claim_date', 'desc')
            ->columns([
                TextColumn::make('claim_date')
                    ->label('Tanggal cuti')
                    ->formatStateUsing(fn ($state) => Format::tanggalPanjang($state))
                    ->sortable(),

                TextColumn::make('claim_type')
                    ->label('Bentuk')
                    ->badge()
                    ->color('gray')
                    ->description(fn (LeaveClaim $c) => $c->claim_type === ClaimType::LateArrival
                        ? 'masuk '.Format::jam((string) $c->arrival_time)
                        : null),

                TextColumn::make('minutes_required')
                    ->label('Jam terpakai')
                    ->formatStateUsing(fn ($state) => Format::durasiRingkas((int) $state))
                    ->alignEnd(),

                TextColumn::make('quota_weight')
                    ->label('Bobot kuota')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 1, ',', '.'), '0'), ','))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // BR-23 — klaim yang saldo sumbernya dibatalkan ditandai di sini,
                    // tepat di sebelah status yang menjadi konteksnya.
                    ->icon(fn (LeaveClaim $c) => $c->needs_review ? 'heroicon-m-exclamation-triangle' : null)
                    ->tooltip(fn (LeaveClaim $c) => $c->needs_review
                        ? 'Lembur sumbernya dibatalkan — klaim ini perlu ditinjau.'
                        : null)
                    ->description(fn (LeaveClaim $c) => $c->needs_review ? 'Perlu ditinjau' : null),

                TextColumn::make('submitted_at')
                    ->label('Diajukan')
                    ->dateTime('j M Y, H:i')
                    ->timezone(config('app.display_timezone'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ClaimStatus::class),

                SelectFilter::make('claim_type')
                    ->label('Bentuk')
                    ->options(ClaimType::class),
            ])
            ->recordActions([
                // Menutup jurang antara sistem ini dan proses email yang sesungguhnya.
                Action::make('salinEmail')
                    ->label('Salin teks email')
                    ->icon('heroicon-m-envelope')
                    ->color('gray')
                    ->modalHeading('Teks pengajuan cuti pengganti')
                    ->modalDescription('Berisi tepat tiga hal yang diwajibkan SOP §9.2. Salin dan tempel ke Outlook.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->schema(fn (LeaveClaim $record) => [
                        Textarea::make('teks')
                            ->label('')
                            ->rows(14)
                            ->default(fn () => static::emailBody($record))
                            ->extraInputAttributes(['onclick' => 'this.select()'])
                            ->helperText('Klik teksnya untuk memilih semua.'),
                    ]),

                EditAction::make(),
            ])
            ->emptyStateHeading('Belum ada klaim')
            ->emptyStateDescription('Kalau punya saldo aktif, kamu bisa ajukan libur pengganti dari sini.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    /** SOP §9.2 mewajibkan tiga hal: tanggal overtime, total jam, dan evidence. */
    public static function emailBody(LeaveClaim $claim): string
    {
        $lines = [
            'Yth. Bapak/Ibu,',
            '',
            sprintf(
                'Saya bermaksud mengajukan cuti pengganti pada %s (%s).',
                Format::tanggalPanjang($claim->claim_date),
                mb_strtolower($claim->claim_type->getLabel()),
            ),
            '',
            'Cuti ini berasal dari lembur berikut:',
        ];

        foreach ($claim->allocations()->with('balance.overtimeRecord')->get() as $allocation) {
            $record = $allocation->balance?->overtimeRecord;

            if ($record === null) {
                continue;
            }

            $lines[] = sprintf(
                '- %s · %s · evidence: %s',
                Format::tanggalPanjang($record->overtime_date),
                Format::durasi($allocation->allocated_minutes),
                $record->evidence_url,
            );
        }

        $lines[] = '';
        $lines[] = 'Total jam yang digunakan: '.Format::durasi($claim->minutes_required).'.';
        $lines[] = '';
        $lines[] = 'Terima kasih atas perhatiannya.';
        $lines[] = $claim->user?->name ?? '';

        return implode("\n", $lines);
    }
}
