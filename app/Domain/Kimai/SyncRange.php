<?php

namespace App\Domain\Kimai;

use Carbon\CarbonImmutable;

/** Rentang yang akan ditarik, beserta alasan batas bawahnya dipilih. */
final readonly class SyncRange
{
    public function __construct(
        public CarbonImmutable $begin,
        public CarbonImmutable $end,
    ) {}

    public function days(): int
    {
        return (int) $this->begin->diffInDays($this->end);
    }
}
