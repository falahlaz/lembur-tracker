<?php

namespace App\Exports;

use App\Models\LeaveClaim;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Sheet Klaim — termasuk asal saldonya, supaya bisa ditelusuri (G-7). */
class KlaimSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
    ) {}

    public function title(): string
    {
        return 'Klaim';
    }

    public function headings(): array
    {
        return [
            'Tanggal cuti', 'Bentuk', 'Jam masuk', 'Jam terpakai (menit)',
            'Bobot kuota', 'Status', 'Perlu ditinjau', 'Diajukan pada',
            'Asal saldo', 'Catatan',
        ];
    }

    public function collection(): Collection
    {
        return LeaveClaim::query()
            ->with('allocations.balance')
            ->where('user_id', $this->user->id)
            ->whereBetween('claim_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('claim_date')
            ->get()
            ->map(function (LeaveClaim $c) {
                $sources = $c->allocations
                    ->map(fn ($a) => $a->balance
                        ? Format::tanggalRingkas($a->balance->earned_date).' ('.Format::durasiRingkas($a->allocated_minutes).')'
                        : '—')
                    ->implode(', ');

                return [
                    Format::tanggalPanjang($c->claim_date),
                    $c->claim_type->getLabel(),
                    $c->arrival_time ? Format::jam((string) $c->arrival_time) : '—',
                    $c->minutes_required,
                    $c->quota_weight,
                    $c->status->getLabel(),
                    $c->needs_review ? 'Ya' : 'Tidak',
                    $c->submitted_at?->timezone(config('app.display_timezone'))->translatedFormat('j M Y, H:i') ?? '—',
                    $sources ?: '—',
                    $c->notes ?? '',
                ];
            });
    }
}
