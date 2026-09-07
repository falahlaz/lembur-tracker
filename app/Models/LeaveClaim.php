<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

#[Fillable([
    'user_id', 'claim_date', 'claim_type', 'arrival_time',
    'minutes_required', 'quota_weight', 'status', 'needs_review',
    'submitted_at', 'notes',
])]
class LeaveClaim extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'claim_date' => 'immutable_date:Y-m-d',
            'claim_type' => ClaimType::class,
            'status' => ClaimStatus::class,
            'needs_review' => 'boolean',
            'submitted_at' => 'datetime',
            'minutes_required' => 'integer',
            'quota_weight' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'claim_date', 'claim_type', 'arrival_time',
                'minutes_required', 'status', 'needs_review', 'notes',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** BR-15 — ledger: dari batch mana saja klaim ini mengambil, dan berapa. */
    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveClaimAllocation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ClaimStatus::Submitted->value,
            ClaimStatus::Approved->value,
            ClaimStatus::Taken->value,
        ]);
    }

    /** BR-19 — kuota dihitung per BULAN KALENDER tanggal cuti, bukan periode payroll. */
    public function scopeInCalendarMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('claim_date', $year)->whereMonth('claim_date', $month);
    }
}
