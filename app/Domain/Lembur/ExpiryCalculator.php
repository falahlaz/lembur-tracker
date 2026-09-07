<?php

namespace App\Domain\Lembur;

use App\Models\OvertimeRule;
use Carbon\CarbonInterface;

/**
 * BR-13 — masa berlaku batch saldo.
 *
 * Batch hangus 1 bulan kalender setelah tanggal lembur, dihitung terhadap
 * TANGGAL CUTI DIAMBIL. Batch masih hidup pada tanggal hangusnya sendiri
 * ("berlaku sampai DAN TERMASUK 5 April").
 *
 * Clamping akhir bulan memakai addMonthsNoOverflow: 31 Januari → 28 Februari
 * (29 di tahun kabisat), 31 Maret → 30 April. Tanpa NoOverflow, Carbon akan
 * melempar 31 Januari ke 3 Maret dan saldo hidup lebih lama dari SOP.
 *
 * Masa berlaku TIDAK diperpanjang bila jatuh pada weekend atau hari libur.
 */
class ExpiryCalculator
{
    public function expiryFor(CarbonInterface $overtimeDate, OvertimeRule $rule): CarbonInterface
    {
        return $overtimeDate->copy()
            ->startOfDay()
            ->addMonthsNoOverflow($rule->expiry_months);
    }

    /** BR-16 — batch masih terpakai bila tanggal cuti <= tanggal hangus. */
    public function isAliveOn(CarbonInterface $expiresAt, CarbonInterface $claimDate): bool
    {
        return $expiresAt->startOfDay()->gte($claimDate->copy()->startOfDay());
    }
}
