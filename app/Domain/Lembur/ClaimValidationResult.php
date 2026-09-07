<?php

namespace App\Domain\Lembur;

/** Kegagalan validasi klaim, beserta ID aturan yang melanggarnya. */
final readonly class ClaimValidationResult
{
    private function __construct(
        public bool $passed,
        public ?string $rule = null,
        public ?string $message = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $rule, string $message): self
    {
        return new self(false, $rule, $message);
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }
}
