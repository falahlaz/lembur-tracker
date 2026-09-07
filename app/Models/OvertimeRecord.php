<?php

namespace App\Models;

use App\Enums\OvertimeStatus;
use App\Enums\Tier;
use App\Observers\OvertimeRecordObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

#[ObservedBy(OvertimeRecordObserver::class)]
#[Fillable([
    'user_id', 'rule_version_id', 'payroll_period_id',
    'overtime_date', 'start_time', 'end_time',
    'work_description', 'evidence_url', 'status', 'notes', 'created_by_id',
])]
class OvertimeRecord extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Kolom hasil hitungan sengaja TIDAK fillable. Satu-satunya yang boleh
     * menulisnya adalah OvertimeDayCalculator, supaya BR-02/BR-07 tidak bisa
     * dilewati lewat mass assignment dari form.
     */
    protected function casts(): array
    {
        return [
            'overtime_date' => 'immutable_date:Y-m-d',
            'rounding_applied' => 'boolean',
            'tier' => Tier::class,
            'status' => OvertimeStatus::class,
            'meal_allowance_amount' => 'integer',
            'leave_credit_minutes' => 'integer',
            'duration_raw_minutes' => 'integer',
            'duration_effective_minutes' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'overtime_date', 'start_time', 'end_time', 'status',
                'duration_raw_minutes', 'duration_effective_minutes',
                'tier', 'meal_allowance_amount', 'leave_credit_minutes',
                'work_description', 'evidence_url', 'notes',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(OvertimeRule::class, 'rule_version_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** BR-07 — paling banyak satu batch per record, dan hanya pada record pemegang hak. */
    public function leaveBalance(): HasOne
    {
        return $this->hasOne(LeaveBalance::class);
    }

    /** OQ-2 — dicatat admin atas nama user lain. */
    public function wasCreatedOnBehalf(): bool
    {
        return $this->created_by_id !== null && $this->created_by_id !== $this->user_id;
    }

    public function scopeForDate(Builder $query, int $userId, mixed $date): Builder
    {
        return $query->where('user_id', $userId)->whereDate('overtime_date', $date);
    }

    public function scopeCountsTowardEstimate(Builder $query): Builder
    {
        return $query->where('status', '!=', OvertimeStatus::Rejected->value);
    }
}
