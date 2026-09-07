<?php

namespace App\Domain\Lembur;

use App\Enums\BalanceStatus;
use App\Enums\Tier;
use App\Models\LeaveBalance;
use App\Models\OvertimeRecord;
use App\Models\OvertimeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * BR-01 s/d BR-08 — jantung sistem.
 *
 * Hak lembur melekat pada TANGGAL, bukan pada record (BR-02 + BR-07). Karena itu
 * menyimpan, mengubah, atau menghapus satu record wajib menghitung ulang SELURUH
 * record di tanggal tersebut. `recalculate()` adalah satu-satunya pintu masuk;
 * tidak ada tempat lain yang boleh menulis kolom hasil hitungan.
 *
 * Pembagian nilai dalam satu tanggal:
 *   - `tier` diisi sama di semua record — tier adalah sifat tanggal, dan kolom ini
 *     dipakai filter di history (F-05).
 *   - `meal_allowance_amount` dan `leave_credit_minutes` HANYA di record paling awal
 *     (BR-07: satu tanggal menghasilkan paling banyak satu uang makan). Record lain nol.
 */
class OvertimeDayCalculator
{
    /** Mencegah observer memanggil dirinya sendiri saat kalkulator menulis. */
    private static bool $recalculating = false;

    public function __construct(
        private readonly RuleResolver $rules,
        private readonly ExpiryCalculator $expiry,
        private readonly PayrollPeriodResolver $periods,
        private readonly BalanceReconciler $balances,
    ) {}

    public static function isRecalculating(): bool
    {
        return self::$recalculating;
    }

    /** Menghitung ulang satu tanggal penuh untuk satu user. */
    public function recalculate(User $user, CarbonInterface $date): void
    {
        if (self::$recalculating) {
            return;
        }

        self::$recalculating = true;

        try {
            DB::transaction(function () use ($user, $date) {
                $rule = $this->rules->forDate($date);
                $records = $this->recordsOn($user, $date);

                if ($records->isEmpty()) {
                    $this->balances->dropOrphanedFor($user, $date);

                    return;
                }

                $period = $this->periods->resolve($date, $rule);

                // BR-23 — record yang ditolak tidak menyumbang ke total harian.
                $counted = $records->filter(fn (OvertimeRecord $r) => $r->status->grantsEntitlement());

                $dailyTotal = 0;
                foreach ($records as $record) {
                    $raw = DurationCalculator::rawMinutes(
                        (string) $record->start_time,
                        (string) $record->end_time,
                    );
                    $effective = DurationCalculator::effectiveMinutes($raw, $user->rounding_enabled);

                    $record->duration_raw_minutes = $raw;
                    $record->duration_effective_minutes = $effective;
                    $record->rounding_applied = $effective !== $raw;
                    $record->rule_version_id = $rule->id;
                    $record->payroll_period_id = $period->id;

                    if ($counted->contains(fn (OvertimeRecord $r) => $r->is($record))) {
                        $dailyTotal += $effective;
                    }
                }

                $tier = $rule->tierFor($dailyTotal);
                $holder = $counted->first();   // record paling awal — sudah terurut

                foreach ($records as $record) {
                    $grants = $holder !== null && $record->is($holder) && $tier !== Tier::None;

                    $record->tier = $record->status->grantsEntitlement() ? $tier : Tier::None;
                    $record->meal_allowance_amount = $grants ? $rule->mealAmountFor($tier) : 0;
                    $record->leave_credit_minutes = $grants ? $rule->leaveMinutesFor($tier) : 0;
                    $record->saveQuietly();
                }

                $this->balances->sync(
                    user: $user,
                    date: $date,
                    holder: $tier === Tier::None ? null : $holder,
                    earnedMinutes: $tier === Tier::None ? 0 : $rule->leaveMinutesFor($tier),
                    expiresAt: $this->expiry->expiryFor($date, $rule),
                    recordIds: $records->pluck('id')->all(),
                );
            });
        } finally {
            self::$recalculating = false;
        }
    }

    /**
     * Hitungan tanpa menulis apa pun — untuk preview live di form (P-3).
     * `$excludeRecordId` dipakai saat mengedit, agar record yang sedang diubah
     * tidak dihitung dua kali terhadap totalnya sendiri.
     */
    public function preview(
        User $user,
        CarbonInterface $date,
        string $startTime,
        string $endTime,
        ?int $excludeRecordId = null,
    ): EntitlementPreview {
        $rule = $this->rules->forDate($date);

        $raw = DurationCalculator::rawMinutes($startTime, $endTime);
        $effective = DurationCalculator::effectiveMinutes($raw, $user->rounding_enabled);

        // BR-02 — total harian menjumlahkan sesi lain di tanggal yang sama.
        $othersTotal = (int) $this->recordsOn($user, $date)
            ->when($excludeRecordId, fn ($c) => $c->reject(fn ($r) => $r->id === $excludeRecordId))
            ->filter(fn (OvertimeRecord $r) => $r->status->grantsEntitlement())
            ->sum('duration_effective_minutes');

        $dailyTotal = $effective + $othersTotal;
        $tier = $rule->tierFor($dailyTotal);

        return new EntitlementPreview(
            rawMinutes: $raw,
            effectiveMinutes: $effective,
            roundingApplied: $effective !== $raw,
            dailyTotalMinutes: $dailyTotal,
            tier: $tier,
            mealAmount: $rule->mealAmountFor($tier),
            leaveMinutes: $rule->leaveMinutesFor($tier),
            expiresAt: $tier === Tier::None ? null : $this->expiry->expiryFor($date, $rule),
            payrollLabel: $this->periods->boundsFor($date, $rule)['label'],
            pastCutoff: $this->periods->isPastCutoff($date),
            crossesMidnight: DurationCalculator::crossesMidnight($startTime, $endTime),
        );
    }

    /** BR-02 — semua record di satu tanggal, terurut dari yang paling awal. */
    public function recordsOn(User $user, CarbonInterface $date): Collection
    {
        return OvertimeRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('overtime_date', $date->toDateString())
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    public function ruleFor(CarbonInterface $date): OvertimeRule
    {
        return $this->rules->forDate($date);
    }
}
