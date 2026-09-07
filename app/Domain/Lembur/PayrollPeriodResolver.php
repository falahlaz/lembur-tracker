<?php

namespace App\Domain\Lembur;

use App\Models\OvertimeRule;
use App\Models\PayrollPeriod;
use Carbon\CarbonInterface;

/**
 * BR-09 — periode payroll berjalan dari tanggal 19 bulan sebelumnya sampai
 * tanggal 18 bulan berjalan, dan diberi label mengikuti bulan period_end.
 *
 *   lembur 18 Maret → 19 Feb – 18 Mar → "Maret"
 *   lembur 19 Maret → 19 Mar – 18 Apr → "April"
 */
class PayrollPeriodResolver
{
    public function __construct(private readonly RuleResolver $rules) {}

    /** @return array{start: Carbon, end: Carbon, label: string} */
    public function boundsFor(CarbonInterface $date, ?OvertimeRule $rule = null): array
    {
        $rule = $rule ?? $this->rules->forDate($date);
        $cutoff = (int) $rule->cutoff_day;

        $day = $this->cutoffDayIn($date, $cutoff);

        if ($date->day >= $day) {
            $start = $this->cutoffDateIn($date, $cutoff);
            $end = $this->cutoffDateIn($date->copy()->addMonthNoOverflow(), $cutoff)->subDay();
        } else {
            $start = $this->cutoffDateIn($date->copy()->subMonthNoOverflow(), $cutoff);
            $end = $this->cutoffDateIn($date, $cutoff)->subDay();
        }

        return [
            'start' => $start,
            'end' => $end,
            'label' => ucfirst($end->translatedFormat('F Y')),
        ];
    }

    /** Mengambil (atau membuat) baris payroll_periods untuk tanggal tersebut. */
    public function resolve(CarbonInterface $date, ?OvertimeRule $rule = null): PayrollPeriod
    {
        $bounds = $this->boundsFor($date, $rule);

        return PayrollPeriod::query()->firstOrCreate(
            [
                'period_start' => $bounds['start']->toDateString(),
                'period_end' => $bounds['end']->toDateString(),
            ],
            ['label' => $bounds['label']],
        );
    }

    public function current(?CarbonInterface $today = null): PayrollPeriod
    {
        return $this->resolve($today ?? today());
    }

    /**
     * BR-11 — record berisiko melewati cut-off: lembur dicatat setelah periode
     * yang menaunginya sudah ditutup. Tidak memblokir input; keputusan akhir
     * ada di HRD, sistem hanya memasang peringatan.
     */
    public function isPastCutoff(CarbonInterface $overtimeDate, ?CarbonInterface $recordedOn = null): bool
    {
        $recordedOn = ($recordedOn ?? today())->copy()->startOfDay();

        return $recordedOn->gt($this->boundsFor($overtimeDate)['end']);
    }

    /** Sisa hari sampai cut-off periode berjalan — dipakai timeline dashboard. */
    public function daysUntilCutoff(?CarbonInterface $today = null): int
    {
        $today = ($today ?? today())->copy()->startOfDay();

        return (int) $today->diffInDays($this->boundsFor($today)['end'], false);
    }

    /** Cut-off di-clamp bila bulannya lebih pendek (mis. cutoff 31 di bulan Februari). */
    private function cutoffDayIn(CarbonInterface $date, int $cutoff): int
    {
        return min($cutoff, $date->daysInMonth);
    }

    private function cutoffDateIn(CarbonInterface $month, int $cutoff): CarbonInterface
    {
        return $month->copy()->startOfDay()->setDay($this->cutoffDayIn($month, $cutoff));
    }
}
