<?php

namespace App\Rules;

use App\Domain\Lembur\ClaimValidator;
use App\Enums\ClaimType;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Date;

/**
 * BR-22 — menjalankan validasi klaim dalam urutan tetap dan melaporkan kegagalan
 * PERTAMA. Dipasang pada field tanggal supaya pesannya muncul inline di dekat
 * penyebabnya, bukan sebagai toast yang menghilang.
 */
class ClaimPassesValidation implements ValidationRule
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?string $claimType,
        private readonly ?int $ignoreClaimId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || blank($value) || blank($this->claimType)) {
            return;
        }

        $type = ClaimType::tryFrom($this->claimType);

        if ($type === null) {
            return;
        }

        $result = app(ClaimValidator::class)->validate(
            user: $this->user,
            claimDate: Date::parse($value),
            type: $type,
            ignoreClaimId: $this->ignoreClaimId,
        );

        if ($result->failed()) {
            $fail($result->message);
        }
    }
}
