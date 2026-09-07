<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** BR-15 — satu baris per potongan batch, agar klaim bisa ditelusuri ke tanggal lemburnya. */
#[Fillable(['leave_claim_id', 'leave_balance_id', 'allocated_minutes'])]
class LeaveClaimAllocation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['allocated_minutes' => 'integer'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(LeaveClaim::class, 'leave_claim_id');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class, 'leave_balance_id');
    }
}
