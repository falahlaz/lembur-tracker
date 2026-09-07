<?php

namespace App\Domain\Lembur;

use App\Enums\ClaimStatus;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use App\Models\LeaveClaimAllocation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-14 s/d BR-16 — alokasi FIFO.
 *
 * Klaim mengonsumsi batch yang PALING DULU HANGUS; bila tanggal hangusnya sama,
 * yang tanggal lemburnya lebih awal dipakai duluan. Klaim boleh mengambil
 * sebagian batch dan boleh menggabungkan beberapa batch — sisa batch tetap
 * hidup sampai tanggal hangusnya sendiri.
 *
 * Yang divalidasi adalah saldo yang masih aktif pada TANGGAL CUTI DIAMBIL
 * (BR-16), bukan saldo saat pengajuan dibuat.
 */
class LeaveAllocator
{
    public function __construct(private readonly ExpiryCalculator $expiry) {}

    /** Menyusun rencana alokasi tanpa menulis apa pun. */
    public function plan(User $user, CarbonInterface $claimDate, int $requiredMinutes, ?int $ignoreClaimId = null): AllocationPlan
    {
        $batches = $this->allocatableBatches($user, $claimDate);

        // Saat mengedit klaim, hold miliknya sendiri dikembalikan dulu ke kolam
        // supaya klaim tidak dinilai kekurangan saldo gara-gara menahan saldonya sendiri.
        $selfHeld = $ignoreClaimId === null
            ? []
            : LeaveClaimAllocation::query()
                ->where('leave_claim_id', $ignoreClaimId)
                ->pluck('allocated_minutes', 'leave_balance_id')
                ->all();

        $available = 0;
        foreach ($batches as $batch) {
            $available += $batch->remainingMinutes() + (int) ($selfHeld[$batch->id] ?? 0);
        }

        $slices = [];
        $needed = $requiredMinutes;

        foreach ($batches as $batch) {
            if ($needed <= 0) {
                break;
            }

            $free = $batch->remainingMinutes() + (int) ($selfHeld[$batch->id] ?? 0);
            if ($free <= 0) {
                continue;
            }

            $take = min($free, $needed);
            $needed -= $take;

            $slices[] = new AllocationSlice(
                balance: $batch,
                minutes: $take,
                balanceRemainingAfter: $free - $take,
            );
        }

        return new AllocationPlan($slices, $requiredMinutes, $available);
    }

    /**
     * BR-20 — menahan (hold) saldo untuk klaim. Dipanggil saat klaim masuk status
     * `submitted`; saldo tetap ditahan sampai `taken` (dipotong permanen) atau
     * dilepas oleh `rejected`/`cancelled`.
     */
    public function hold(LeaveClaim $claim): AllocationPlan
    {
        return DB::transaction(function () use ($claim) {
            $this->release($claim);

            $plan = $this->plan($claim->user, $claim->claim_date, $claim->minutes_required);

            if (! $plan->isSatisfiable()) {
                throw new RuntimeException('Saldo tidak cukup untuk menahan klaim ini.');
            }

            foreach ($plan->slices as $slice) {
                LeaveClaimAllocation::query()->create([
                    'leave_claim_id' => $claim->id,
                    'leave_balance_id' => $slice->balance->id,
                    'allocated_minutes' => $slice->minutes,
                ]);

                $balance = $slice->balance->fresh();
                $balance->held_minutes += $slice->minutes;
                $balance->syncStatus();
                $balance->save();
            }

            return $plan;
        });
    }

    /**
     * BR-20 — melepas hold. Saldo kembali ke batch ASALNYA dengan tanggal hangus
     * yang TIDAK berubah; klaim yang ditolak tidak memperpanjang umur saldo.
     */
    public function release(LeaveClaim $claim): void
    {
        DB::transaction(function () use ($claim) {
            foreach ($claim->allocations()->with('balance')->get() as $allocation) {
                $balance = $allocation->balance;

                if ($balance !== null) {
                    $balance->held_minutes = max(0, $balance->held_minutes - $allocation->allocated_minutes);
                    $balance->syncStatus();
                    $balance->save();
                }

                $allocation->delete();
            }
        });
    }

    /**
     * §9.3 — tanggal cuti sudah lewat: hold berubah jadi potongan permanen.
     * Alokasi TIDAK dihapus, karena ledger-nya yang membuat klaim bisa
     * ditelusuri kembali ke tanggal lembur asalnya (G-7).
     */
    public function settle(LeaveClaim $claim): void
    {
        DB::transaction(function () use ($claim) {
            foreach ($claim->allocations()->with('balance')->get() as $allocation) {
                $balance = $allocation->balance;

                if ($balance === null) {
                    continue;
                }

                $balance->held_minutes = max(0, $balance->held_minutes - $allocation->allocated_minutes);
                $balance->consumed_minutes += $allocation->allocated_minutes;
                $balance->syncStatus();
                $balance->save();
            }

            $claim->status = ClaimStatus::Taken;
            $claim->save();
        });
    }

    /** Total saldo yang masih bisa dipakai pada sebuah tanggal cuti. */
    public function availableMinutesOn(User $user, CarbonInterface $claimDate): int
    {
        return $this->allocatableBatches($user, $claimDate)
            ->sum(fn (LeaveBalance $b) => $b->remainingMinutes());
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, LeaveBalance> */
    private function allocatableBatches(User $user, CarbonInterface $claimDate)
    {
        return LeaveBalance::query()
            ->where('user_id', $user->id)
            ->allocatable()
            // BR-16 — masa berlaku diuji terhadap tanggal cuti, bukan hari ini.
            ->whereDate('expires_at', '>=', $claimDate->toDateString())
            ->fifo()
            ->get();
    }
}
