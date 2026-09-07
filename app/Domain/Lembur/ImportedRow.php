<?php

namespace App\Domain\Lembur;

/** Satu baris hasil parsing berkas impor, beserta hasil hitungan dan masalahnya. */
final class ImportedRow
{
    /** @param  array<string>  $errors */
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?string $date = null,
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?string $description = null,
        public readonly ?string $evidenceUrl = null,
        public readonly ?string $status = null,
        public readonly ?string $notes = null,
        public array $errors = [],
        public ?EntitlementPreview $preview = null,
        public bool $alreadyExpired = false,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
