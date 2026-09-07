<?php

namespace App\Events;

use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * BR-23 — lembur sumber dibatalkan setelah saldonya terpakai. Listener di M6
 * mengirim notifikasi berisi klaim mana saja yang perlu diperbaiki user.
 */
class LeaveClaimNeedsReview
{
    use Dispatchable;

    public function __construct(
        public readonly LeaveClaim $claim,
        public readonly LeaveBalance $balance,
    ) {}
}
