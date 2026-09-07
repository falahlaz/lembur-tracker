<?php

namespace App\Domain\Lembur;

/**
 * BR-15 — rencana alokasi lengkap. Dihasilkan tanpa menulis apa pun, supaya
 * panel "SALDO YANG AKAN DIPAKAI" (Design Brief §4.4) menampilkan hal yang
 * persis sama dengan yang nanti tersimpan. Tanpa ini FIFO terasa seperti sihir
 * dan user tidak percaya angkanya.
 */
final readonly class AllocationPlan
{
    /** @param  array<AllocationSlice>  $slices */
    public function __construct(
        public array $slices,
        public int $requiredMinutes,
        public int $availableMinutes,
    ) {}

    public function isSatisfiable(): bool
    {
        return $this->availableMinutes >= $this->requiredMinutes;
    }

    public function shortfallMinutes(): int
    {
        return max(0, $this->requiredMinutes - $this->availableMinutes);
    }

    public function remainingAfterMinutes(): int
    {
        return max(0, $this->availableMinutes - $this->requiredMinutes);
    }
}
