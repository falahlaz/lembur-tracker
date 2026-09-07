<?php

namespace App\Exports;

use App\Enums\BalanceStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Sheet Ringkasan — angka yang biasanya ditanyakan, sudah dijumlahkan. */
class RingkasanSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
        private readonly string $periodLabel,
    ) {}

    public function title(): string
    {
        return 'Ringkasan';
    }

    public function headings(): array
    {
        return ['Rincian', 'Nilai'];
    }

    public function collection(): Collection
    {
        $records = OvertimeRecord::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('overtime_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->get();

        $counted = $records->filter(fn (OvertimeRecord $r) => $r->status->countsTowardEstimate());
        $approved = $counted->filter(fn (OvertimeRecord $r) => $r->status->isCertain());

        $claims = LeaveClaim::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('claim_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->active()
            ->get();

        $expired = LeaveBalance::query()
            ->where('user_id', $this->user->id)
            ->where('status', BalanceStatus::Expired->value)
            ->whereBetween('expires_at', [$this->from->toDateString(), $this->to->toDateString()])
            ->get();

        return collect([
            ['Karyawan', $this->user->name],
            ['Periode', $this->periodLabel],
            ['Rentang tanggal', Format::tanggalPanjang($this->from).' – '.Format::tanggalPanjang($this->to)],
            ['Pembulatan durasi', $this->user->rounding_enabled ? 'Nyala' : 'Mati'],
            ['', ''],
            ['Jumlah lembur', $counted->count()],
            ['Total jam mentah', Format::durasi((int) $counted->sum('duration_raw_minutes'))],
            ['Total jam efektif', Format::durasi((int) $counted->sum('duration_effective_minutes'))],
            ['Record yang dibulatkan', $counted->where('rounding_applied', true)->count()],
            ['', ''],
            ['Estimasi uang makan (Rp)', (int) $counted->sum('meal_allowance_amount')],
            ['Sudah disetujui (Rp)', (int) $approved->sum('meal_allowance_amount')],
            ['Cuti pengganti diperoleh', Format::durasi((int) $counted->sum('leave_credit_minutes'))],
            ['', ''],
            ['Jumlah klaim aktif', $claims->count()],
            ['Jam cuti terpakai', Format::durasi((int) $claims->sum('minutes_required'))],
            ['Bobot kuota terpakai', (float) $claims->sum('quota_weight')],
            ['', ''],
            // Angka yang jadi alasan sistem ini dibangun.
            ['Saldo hangus di rentang ini', Format::durasi(
                (int) $expired->sum(fn (LeaveBalance $b) => $b->remainingMinutes())
            )],
            ['', ''],
            ['Catatan', 'Angka rupiah bersifat estimasi berdasarkan catatan pribadi, bukan perhitungan payroll resmi.'],
        ]);
    }
}
