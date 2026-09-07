<?php

namespace App\Domain\Lembur;

use App\Enums\BalanceStatus;
use App\Enums\ClaimStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use Illuminate\Support\Facades\DB;

/**
 * §9.3 — pemeliharaan harian saldo.
 *
 * Dipisahkan dari command supaya bisa diuji tanpa menjalankan scheduler, dan
 * supaya urutannya eksplisit: klaim yang jatuh tempo di-settle LEBIH DULU,
 * baru sisa saldo ditandai hangus. Kalau dibalik, saldo yang sebenarnya sudah
 * terpakai sah pada hari terakhirnya akan ikut terhitung sebagai hangus.
 */
class BalanceMaintenance
{
    /** @return array{settled: int, expired: int} */
    public function run(): array
    {
        return DB::transaction(fn () => [
            'settled' => $this->settleDueClaims(),
            'expired' => $this->markExpiredBatches(),
        ]);
    }

    /**
     * Klaim `disetujui` yang tanggal cutinya sudah lewat menjadi `diambil`,
     * dan hold-nya berubah jadi potongan permanen.
     */
    public function settleDueClaims(): int
    {
        $due = LeaveClaim::query()
            ->where('status', ClaimStatus::Approved->value)
            ->whereDate('claim_date', '<', today()->toDateString())
            ->get();

        $allocator = app(LeaveAllocator::class);

        foreach ($due as $claim) {
            $allocator->settle($claim);
        }

        return $due->count();
    }

    /** BR-17 — batch yang lewat masa berlaku dan masih bersisa ditandai hangus. */
    public function markExpiredBatches(): int
    {
        $stale = LeaveBalance::query()
            ->whereIn('status', [BalanceStatus::Active->value, BalanceStatus::PartiallyUsed->value])
            ->whereDate('expires_at', '<', today()->toDateString())
            ->get()
            ->filter(fn (LeaveBalance $b) => $b->remainingMinutes() > 0 && $b->held_minutes === 0);

        foreach ($stale as $balance) {
            $balance->status = BalanceStatus::Expired;
            $balance->save();
        }

        return $stale->count();
    }
}
