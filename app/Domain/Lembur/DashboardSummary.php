<?php

namespace App\Domain\Lembur;

use App\Enums\BalanceStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use App\Models\OvertimeRecord;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * F-03 — mengumpulkan seluruh angka dashboard dalam satu tempat.
 *
 * Semua widget membaca dari sini, bukan menghitung sendiri-sendiri: kalau tiap
 * widget punya query-nya sendiri, cepat atau lambat stat tile dan tabel di
 * bawahnya akan menampilkan dua angka berbeda untuk hal yang sama.
 */
class DashboardSummary
{
    public function __construct(
        private readonly PayrollPeriodResolver $periods,
        private readonly MealAllowanceEstimator $estimator,
        private readonly ClaimValidator $claims,
    ) {}

    public function currentPeriod(): PayrollPeriod
    {
        return $this->periods->current();
    }

    /** @return Collection<int, LeaveBalance> */
    public function activeBalances(User $user): Collection
    {
        return LeaveBalance::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [BalanceStatus::Active->value, BalanceStatus::PartiallyUsed->value])
            ->orderBy('expires_at')
            ->orderBy('earned_date')
            ->get()
            ->filter(fn (LeaveBalance $b) => $b->remainingMinutes() > 0)
            ->values();
    }

    public function activeMinutes(User $user): int
    {
        return (int) $this->activeBalances($user)->sum(fn (LeaveBalance $b) => $b->remainingMinutes());
    }

    /**
     * P-4 — batch yang hangus dalam <= 7 hari. Ini yang memicu banner, dan
     * satu-satunya alasan banner itu ada: peringatan harus datang sebelum
     * terlambat, bukan sesudah.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function expiringSoon(User $user, int $withinDays = 7): Collection
    {
        return $this->activeBalances($user)
            ->filter(fn (LeaveBalance $b) => $b->daysUntilExpiry() <= $withinDays)
            ->values();
    }

    public function mealEstimate(User $user): MealAllowanceEstimate
    {
        return $this->estimator->forPeriod($user, $this->currentPeriod());
    }

    /** @return array{used: float, limit: float} */
    public function quota(User $user): array
    {
        return [
            'used' => $this->claims->quotaUsedIn($user, today()),
            'limit' => $this->claims->quotaLimitFor(today()),
        ];
    }

    /** @return array{count: int, minutes: int} */
    public function overtimeThisPeriod(User $user): array
    {
        $period = $this->currentPeriod();

        $records = OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereBetween('overtime_date', [
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ])
            ->countsTowardEstimate()
            ->get();

        return [
            'count' => $records->count(),
            'minutes' => (int) $records->sum('duration_effective_minutes'),
        ];
    }

    /** F-07 — lembur yang belum disetujui menjelang cut-off. */
    public function unapprovedThisPeriod(User $user): int
    {
        $period = $this->currentPeriod();

        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereBetween('overtime_date', [
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ])
            ->whereNotIn('status', ['approved', 'rejected'])
            ->count();
    }

    /**
     * Aktivitas terakhir — lembur dan klaim digabung, diurutkan waktu pembuatan.
     *
     * @return Collection<int, array{type: string, at: mixed, label: string, url: ?string}>
     */
    public function recentActivity(User $user, int $limit = 5): Collection
    {
        $overtime = OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (OvertimeRecord $r) => [
                'type' => 'lembur',
                'at' => $r->created_at,
                'model' => $r,
            ]);

        $claims = LeaveClaim::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (LeaveClaim $c) => [
                'type' => 'klaim',
                'at' => $c->created_at,
                'model' => $c,
            ]);

        return $overtime->concat($claims)
            ->sortByDesc('at')
            ->take($limit)
            ->values();
    }
}
