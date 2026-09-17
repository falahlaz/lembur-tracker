<?php

namespace App\Domain\Timesheet;

/** Hasil pemeriksaan duplikat — termasuk kemungkinan ia tidak sempat dijalankan. */
final readonly class DuplicateCheckResult
{
    private function __construct(
        /** False berarti Kimai tidak terjangkau; pratinjau TIDAK boleh terlihat lulus. */
        public bool $checked,
        public ?string $unavailableReason,
        public int $kimaiConflicts,
        public int $localConflicts,
    ) {}

    public static function checked(int $kimaiConflicts, int $localConflicts): self
    {
        return new self(true, null, $kimaiConflicts, $localConflicts);
    }

    public static function unavailable(string $reason, int $localConflicts): self
    {
        return new self(false, $reason, 0, $localConflicts);
    }

    public function total(): int
    {
        return $this->kimaiConflicts + $this->localConflicts;
    }
}
