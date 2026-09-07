<?php

namespace App\Exports;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Illuminate\Support\Collection;

/** Sheet Lembur — memuat durasi mentah, efektif, dan flag pembulatan (BR-04). */
class LemburSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
    ) {}

    public function title(): string
    {
        return 'Lembur';
    }

    public function headings(): array
    {
        return [
            'Tanggal', 'Jam mulai', 'Jam selesai',
            'Durasi mentah (menit)', 'Durasi efektif (menit)', 'Dibulatkan',
            'Tier', 'Uang makan (Rp)', 'Saldo diperoleh (menit)',
            'Status', 'Periode payroll', 'Deskripsi', 'Evidence', 'Catatan',
        ];
    }

    public function collection(): Collection
    {
        return OvertimeRecord::query()
            ->with('payrollPeriod')
            ->where('user_id', $this->user->id)
            ->whereBetween('overtime_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('overtime_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (OvertimeRecord $r) => [
                Format::tanggalPanjang($r->overtime_date),
                Format::jam((string) $r->start_time),
                Format::jam((string) $r->end_time),
                $r->duration_raw_minutes,
                $r->duration_effective_minutes,
                // Jejak audit BR-04 — perbedaan hak antar user harus selalu terlihat.
                $r->rounding_applied ? 'Ya' : 'Tidak',
                $r->tier->getLabel(),
                $r->meal_allowance_amount,
                $r->leave_credit_minutes,
                $r->status->getLabel(),
                $r->payrollPeriod?->label ?? '—',
                $r->work_description,
                $r->evidence_url,
                $r->notes ?? '',
            ]);
    }
}
