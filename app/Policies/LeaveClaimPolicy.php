<?php

namespace App\Policies;

use App\Enums\ClaimStatus;
use App\Models\LeaveClaim;
use App\Models\User;

class LeaveClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveClaim $claim): bool
    {
        return $this->owns($user, $claim);
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, LeaveClaim $claim): bool
    {
        // Klaim yang sudah diambil adalah sejarah — tidak diubah lagi.
        return $this->owns($user, $claim) && $claim->status !== ClaimStatus::Taken;
    }

    public function delete(User $user, LeaveClaim $claim): bool
    {
        return $this->owns($user, $claim) && $claim->status === ClaimStatus::Draft;
    }

    public function forceDelete(User $user, LeaveClaim $claim): bool
    {
        return false;
    }

    private function owns(User $user, LeaveClaim $claim): bool
    {
        return $user->isAdmin() || $claim->user_id === $user->id;
    }
}
