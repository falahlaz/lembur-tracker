<?php

namespace App\Exports;

use App\Models\User;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * F-08 — rekap satu workbook, tiga sheet.
 *
 * Dipakai maatwebsite/excel dan bukan Exporter bawaan Filament karena yang
 * terakhir hanya menghasilkan satu sheet per export, sementara spesifikasinya
 * meminta Lembur, Klaim dan Ringkasan berada dalam SATU berkas.
 */
class RekapLemburExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
        private readonly string $periodLabel,
    ) {}

    public function sheets(): array
    {
        return [
            new LemburSheet($this->user, $this->from, $this->to),
            new KlaimSheet($this->user, $this->from, $this->to),
            new RingkasanSheet($this->user, $this->from, $this->to, $this->periodLabel),
        ];
    }

    /** Nama berkas: Rekap_Lembur_[Nama]_[Periode].xlsx */
    public function fileName(): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '', $this->user->name);
        $period = preg_replace('/[^A-Za-z0-9]+/', '', $this->periodLabel);

        return "Rekap_Lembur_{$name}_{$period}.xlsx";
    }
}
