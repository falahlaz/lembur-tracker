<?php

namespace App\Domain\Lembur;

use App\Models\LeaveBalance;

/** Satu potongan alokasi FIFO — dipakai preview panel maupun penulisan sebenarnya. */
final readonly class AllocationSlice
{
    public function __construct(
        public LeaveBalance $balance,
        public int $minutes,
        public int $balanceRemainingAfter,
    ) {}

    public function exhaustsBatch(): bool
    {
        return $this->balanceRemainingAfter === 0;
    }
}
