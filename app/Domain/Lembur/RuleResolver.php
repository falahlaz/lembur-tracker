<?php

namespace App\Domain\Lembur;

use App\Models\OvertimeRule;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * BR-24 — mengembalikan versi aturan yang berlaku pada sebuah tanggal.
 *
 * Yang menentukan adalah TANGGAL LEMBUR, bukan tanggal hari ini. Inilah yang
 * membuat revisi SOP tidak menulis ulang sejarah: record lama tetap dihitung
 * dengan aturan yang berlaku saat lembur itu terjadi.
 */
class RuleResolver
{
    /** @var array<string, OvertimeRule> */
    private array $cache = [];

    public function forDate(CarbonInterface $date): OvertimeRule
    {
        $key = $date->toDateString();

        return $this->cache[$key] ??= OvertimeRule::query()
            ->whereDate('effective_from', '<=', $key)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $key))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()
            ?? throw new RuntimeException("Tidak ada versi aturan lembur yang berlaku pada {$key}.");
    }

    /** Aturan yang berlaku hari ini — dipakai form dan dashboard. */
    public function current(): OvertimeRule
    {
        return $this->forDate(today());
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
