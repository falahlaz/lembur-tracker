<?php

namespace App\Domain\Lembur;

use App\Enums\OvertimeStatus;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\User;

/** Hasil §9.4 — dua angka, dan perbedaannya penting. */
final readonly class MealAllowanceEstimate
{
    public function __construct(
        public int $maximum,
        public int $certain,
        public int $recordCount,
        public int $totalMinutes,
    ) {}

    /** Bagian yang masih menunggu approval. */
    public function pending(): int
    {
        return max(0, $this->maximum - $this->certain);
    }
}

/**
 * BR-10 / §9.4 — estimasi uang makan per periode payroll.
 *
 * Dua angka, bukan satu: "estimasi maksimal" mencakup record yang baru dicatat
 * maupun diajukan, "sudah pasti" hanya yang sudah disetujui. Hierarki ukurannya
 * di dashboard yang membedakan mana harapan dan mana kepastian (Design Brief §4.1).
 *
 * R-1 — angka ini estimasi, bukan perhitungan payroll resmi.
 */
class MealAllowanceEstimator
{
    public function forPeriod(User $user, PayrollPeriod $period): MealAllowanceEstimate
    {
        $records = OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereBetween('overtime_date', [
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ])
            ->get();

        $counted = $records->filter(
            fn (OvertimeRecord $r) => $r->status->countsTowardEstimate()
        );

        return new MealAllowanceEstimate(
            maximum: (int) $counted->sum('meal_allowance_amount'),
            certain: (int) $counted->filter(fn ($r) => $r->status->isCertain())->sum('meal_allowance_amount'),
            recordCount: $counted->count(),
            totalMinutes: (int) $counted->sum('duration_effective_minutes'),
        );
    }
}
