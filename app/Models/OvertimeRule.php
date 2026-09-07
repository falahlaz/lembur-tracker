<?php

namespace App\Models;

use App\Enums\Tier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** BR-24 — satu baris = satu versi aturan yang berlaku pada rentang tanggal tertentu. */
#[Fillable([
    'effective_from', 'effective_to',
    'tier1_min_minutes', 'tier1_meal_amount', 'tier1_leave_minutes',
    'tier2_min_minutes', 'tier2_meal_amount', 'tier2_leave_minutes',
    'cutoff_day', 'expiry_months', 'monthly_claim_quota_days',
    'work_start_time', 'work_end_time', 'notes',
])]
class OvertimeRule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'effective_to' => 'immutable_date:Y-m-d',
            'monthly_claim_quota_days' => 'float',
        ];
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class, 'rule_version_id');
    }

    /** BR-05/BR-06 — capped, tidak bertingkat: di atas tier 2 tidak ada kelipatan. */
    public function tierFor(int $effectiveMinutes): Tier
    {
        return match (true) {
            $effectiveMinutes >= $this->tier2_min_minutes => Tier::Tier2,
            $effectiveMinutes >= $this->tier1_min_minutes => Tier::Tier1,
            default => Tier::None,
        };
    }

    public function mealAmountFor(Tier $tier): int
    {
        return match ($tier) {
            Tier::None => 0,
            Tier::Tier1 => (int) $this->tier1_meal_amount,
            Tier::Tier2 => (int) $this->tier2_meal_amount,
        };
    }

    public function leaveMinutesFor(Tier $tier): int
    {
        return match ($tier) {
            Tier::None => 0,
            Tier::Tier1 => (int) $this->tier1_leave_minutes,
            Tier::Tier2 => (int) $this->tier2_leave_minutes,
        };
    }

    /** BR-18 — menit yang dikonsumsi tiap bentuk klaim, bersumber dari tier yang sama. */
    public function minutesForFullDay(): int
    {
        return (int) $this->tier2_leave_minutes;
    }

    public function minutesForLateArrival(): int
    {
        return (int) $this->tier1_leave_minutes;
    }
}
