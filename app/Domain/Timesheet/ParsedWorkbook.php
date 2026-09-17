<?php

namespace App\Domain\Timesheet;

use Carbon\CarbonImmutable;

/** Hasil membaca satu workbook timesheet. Tidak menyentuh database maupun jaringan. */
final readonly class ParsedWorkbook
{
    /**
     * @param  array<int, ParsedEntry>  $entries
     * @param  array<int, string>  $issues  hal yang perlu dilihat manusia, tetapi tidak menggagalkan
     */
    public function __construct(
        public ?int $customerId,
        public ?int $projectId,
        public array $entries = [],
        public array $issues = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return array<int, ParsedEntry> */
    public function postable(): array
    {
        return array_values(array_filter($this->entries, fn (ParsedEntry $e) => $e->isPostable()));
    }

    /** @return array<int, ParsedEntry> */
    public function forSheet(string $sheet): array
    {
        return array_values(array_filter($this->entries, fn (ParsedEntry $e) => $e->sheet === $sheet));
    }

    public function rangeStart(): ?CarbonImmutable
    {
        return $this->reduceDate(fn (CarbonImmutable $a, CarbonImmutable $b) => $a->lt($b) ? $a : $b, 'beginAt');
    }

    /** Sudah memperhitungkan slot yang tutup jam 24 dan melewati tengah malam. */
    public function rangeEnd(): ?CarbonImmutable
    {
        return $this->reduceDate(fn (CarbonImmutable $a, CarbonImmutable $b) => $a->gt($b) ? $a : $b, 'endAt');
    }

    public function totalMinutes(): int
    {
        return array_sum(array_map(fn (ParsedEntry $e) => $e->durationMinutes(), $this->entries));
    }

    private function reduceDate(callable $pick, string $property): ?CarbonImmutable
    {
        $found = null;

        foreach ($this->entries as $entry) {
            $found = $found === null ? $entry->{$property} : $pick($found, $entry->{$property});
        }

        return $found;
    }
}
