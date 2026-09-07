<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\LeaveAllocator;
use App\Enums\BalanceStatus;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-14, BR-15, BR-16, BR-17 — konsumsi FIFO dan masa berlaku saldo. */
class AllocationTest extends TestCase
{
    use RefreshDatabase;

    private LeaveAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
        $this->allocator = app(LeaveAllocator::class);
    }

    #[Test]
    public function br_14_klaim_mengonsumsi_batch_yang_paling_dulu_hangus(): void
    {
        $user = $this->employee();

        // Dicatat terbalik: yang lebih baru dulu, untuk memastikan urutan
        // ditentukan tanggal hangus — bukan urutan pembuatan baris.
        $this->logOvertime($user, '2026-03-12', '19:00', '23:30');   // hangus 12 Apr
        $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // hangus  5 Apr

        $plan = $this->allocator->plan($user, Carbon::parse('2026-04-01'), 240);

        $this->assertCount(1, $plan->slices);
        $this->assertSame('2026-04-05', $plan->slices[0]->balance->expires_at->toDateString());
    }

    #[Test]
    public function br_14_tanggal_hangus_sama_dipecah_oleh_tanggal_lembur(): void
    {
        $this->freezeDate('2026-02-10');
        $user = $this->employee();

        // 31 Jan dan 30 Jan sama-sama clamp ke 28 Feb (BR-13) → tie di expires_at.
        $this->logOvertime($user, '2026-01-31', '19:00', '23:30');
        $this->logOvertime($user, '2026-01-30', '19:00', '23:30');

        $plan = $this->allocator->plan($user, Carbon::parse('2026-02-20'), 240);

        $this->assertSame('2026-02-28', $plan->slices[0]->balance->expires_at->toDateString());
        // Yang tanggal lemburnya lebih awal dipakai duluan.
        $this->assertSame('2026-01-30', $plan->slices[0]->balance->earned_date->toDateString());
    }

    #[Test]
    public function br_15_klaim_boleh_menggabungkan_beberapa_batch(): void
    {
        $user = $this->employee();

        $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // 4 jam
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam

        // Libur sehari penuh butuh 8 jam → 4 dari batch pertama, 4 dari kedua.
        $plan = $this->allocator->plan($user, Carbon::parse('2026-04-01'), 480);

        $this->assertCount(2, $plan->slices);
        $this->assertSame(240, $plan->slices[0]->minutes);
        $this->assertTrue($plan->slices[0]->exhaustsBatch());
        $this->assertSame(240, $plan->slices[1]->minutes);
        // Sisa batch kedua tetap hidup sampai tanggal hangusnya sendiri.
        $this->assertSame(240, $plan->slices[1]->balanceRemainingAfter);
        $this->assertFalse($plan->slices[1]->exhaustsBatch());
        $this->assertSame(240, $plan->remainingAfterMinutes());
    }

    #[Test]
    public function br_15_klaim_parsial_menyisakan_batch_tetap_hidup(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // batch 8 jam

        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::LateArrival);   // pakai 4 jam

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(240, $batch->held_minutes);
        $this->assertSame(0, $batch->consumed_minutes);
        $this->assertSame(240, $batch->remainingMinutes());
        $this->assertSame(BalanceStatus::PartiallyUsed, $batch->status);

        // Ledger mencatat asal saldonya (G-7).
        $this->assertCount(1, $claim->allocations);
        $this->assertSame(240, $claim->allocations->first()->allocated_minutes);
        $this->assertSame($batch->id, $claim->allocations->first()->leave_balance_id);
    }

    #[Test]
    public function br_16_saldo_divalidasi_pada_tanggal_cuti_bukan_hari_ini(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // hangus 5 Apr

        // Cuti 4 April — batch masih hidup.
        $this->assertTrue(
            $this->allocator->plan($user, Carbon::parse('2026-04-04'), 240)->isSatisfiable()
        );
        // Cuti 5 April — hari terakhir, masih boleh.
        $this->assertTrue(
            $this->allocator->plan($user, Carbon::parse('2026-04-05'), 240)->isSatisfiable()
        );
        // Cuti 20 April — batch sudah hangus pada tanggal itu, walau HARI INI masih hidup.
        $rencana = $this->allocator->plan($user, Carbon::parse('2026-04-20'), 240);
        $this->assertFalse($rencana->isSatisfiable());
        $this->assertSame(0, $rencana->availableMinutes);
        $this->assertSame(240, $rencana->shortfallMinutes());
    }

    #[Test]
    public function br_17_batch_hangus_tidak_dihapus_dan_tetap_terlihat(): void
    {
        // Hari ini 10 Maret; batch dari lembur 5 Januari sudah lewat 5 Februari.
        $this->freezeDate('2026-03-10');
        $user = $this->employee();
        $this->logOvertime($user, '2026-01-05', '19:00', '23:30');

        $balance = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame('2026-02-05', $balance->expires_at->toDateString());

        $balance->syncStatus();
        $balance->save();

        // Berubah status, bukan terhapus — angka hak yang terbuang harus tetap terlihat.
        $this->assertSame(BalanceStatus::Expired, $balance->status);
        $this->assertTrue(LeaveBalance::query()->whereKey($balance->id)->exists());
        $this->assertFalse($balance->status->isAllocatable());
        $this->assertSame(240, $balance->remainingMinutes());
    }

    #[Test]
    public function br_20_melepas_hold_mengembalikan_saldo_tanpa_mengubah_tanggal_hangus(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam

        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(480, $batch->held_minutes);
        $expiryBefore = $batch->expires_at->toDateString();

        $this->allocator->release($claim);
        $claim->update(['status' => ClaimStatus::Rejected]);

        $batch->refresh();
        $this->assertSame(0, $batch->held_minutes);
        $this->assertSame(480, $batch->remainingMinutes());
        // Tanggal hangus tidak bergeser — klaim yang ditolak tidak memperpanjang umur saldo.
        $this->assertSame($expiryBefore, $batch->expires_at->toDateString());
        $this->assertSame(BalanceStatus::Active, $batch->status);
    }

    #[Test]
    public function br_20_settle_mengubah_hold_jadi_potongan_permanen(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');

        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        $this->allocator->settle($claim);

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(0, $batch->held_minutes);
        $this->assertSame(480, $batch->consumed_minutes);
        $this->assertSame(BalanceStatus::Used, $batch->status);
        $this->assertSame(ClaimStatus::Taken, $claim->refresh()->status);

        // Ledger alokasi TIDAK dihapus — jejak ke tanggal lembur asal harus tetap ada.
        $this->assertCount(1, $claim->allocations);
    }
}
