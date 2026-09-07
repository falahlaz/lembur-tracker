<?php

namespace App\Domain\Lembur;

use App\Enums\BalanceStatus;
use App\Events\LeaveClaimNeedsReview;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use App\Models\OvertimeRecord;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Menjaga batch saldo tetap sinkron dengan hak yang dihitung ulang — dan menegakkan
 * BR-23 saat keduanya bertabrakan.
 *
 * Prinsipnya: batch yang BELUM TERSENTUH boleh diubah bebas; batch yang saldonya
 * sudah ditahan atau dipotong klaim TIDAK boleh berubah diam-diam. Data yang sudah
 * dikomunikasikan ke atasan tidak boleh bergeser tanpa sepengetahuan user, jadi
 * sistem membatalkan batch (`void`), menandai klaim `needs_review`, dan memberi
 * tahu — bukan membatalkan klaimnya sendiri.
 */
class BalanceReconciler
{
    /**
     * @param  OvertimeRecord|null  $holder  record pemegang hak (paling awal di tanggal itu)
     * @param  array<int>  $recordIds  seluruh record di tanggal tersebut
     */
    public function sync(
        User $user,
        CarbonInterface $date,
        ?OvertimeRecord $holder,
        int $earnedMinutes,
        CarbonInterface $expiresAt,
        array $recordIds,
    ): void {
        $existing = LeaveBalance::query()
            ->where('user_id', $user->id)
            ->whereIn('overtime_record_id', $recordIds)
            ->first();

        // Hak hilang seluruhnya — tanggal itu tidak lagi memenuhi tier.
        if ($holder === null || $earnedMinutes <= 0) {
            if ($existing !== null) {
                $this->retireOrDelete($existing);
            }

            return;
        }

        if ($existing === null) {
            LeaveBalance::query()->create([
                'user_id' => $user->id,
                'overtime_record_id' => $holder->id,
                'earned_minutes' => $earnedMinutes,
                'consumed_minutes' => 0,
                'held_minutes' => 0,
                'earned_date' => $date->toDateString(),
                'expires_at' => $expiresAt->toDateString(),
                'status' => BalanceStatus::Active,
            ]);

            return;
        }

        $locked = $existing->consumed_minutes + $existing->held_minutes;

        // BR-23 — hak menyusut di bawah yang sudah terpakai: jangan diam-diam.
        if ($earnedMinutes < $locked) {
            $this->void($existing);

            return;
        }

        // Pemegang hak bisa berpindah bila record yang lebih awal ditambahkan
        // belakangan. Batch di-arahkan ulang, alokasi yang sudah ada tetap utuh.
        $existing->overtime_record_id = $holder->id;
        $existing->earned_minutes = $earnedMinutes;
        $existing->earned_date = $date->toDateString();
        $existing->expires_at = $expiresAt->toDateString();

        if ($existing->status === BalanceStatus::Void) {
            $existing->status = BalanceStatus::Active;
        }
        $existing->syncStatus();
        $existing->save();
    }

    /** Seluruh record di tanggal itu terhapus — batchnya ikut dibereskan. */
    public function dropOrphanedFor(User $user, CarbonInterface $date): void
    {
        LeaveBalance::query()
            ->where('user_id', $user->id)
            ->whereDate('earned_date', $date->toDateString())
            ->get()
            ->each(fn (LeaveBalance $balance) => $this->retireOrDelete($balance));
    }

    /** Batch perawan boleh hilang tanpa jejak; batch terpakai harus di-void. */
    private function retireOrDelete(LeaveBalance $balance): void
    {
        if ($balance->consumed_minutes + $balance->held_minutes > 0) {
            $this->void($balance);

            return;
        }

        $balance->delete();
    }

    /** BR-23 — batch dibatalkan, klaim terdampak ditandai untuk ditinjau user. */
    private function void(LeaveBalance $balance): void
    {
        $balance->status = BalanceStatus::Void;
        $balance->save();

        $claims = LeaveClaim::query()
            ->whereIn('id', $balance->allocations()->pluck('leave_claim_id'))
            ->get();

        foreach ($claims as $claim) {
            if ($claim->needs_review) {
                continue;
            }

            $claim->needs_review = true;
            $claim->save();

            event(new LeaveClaimNeedsReview($claim, $balance));
        }
    }
}
