<?php

namespace Tests\Feature\Domain;

use App\Enums\BalanceStatus;
use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Events\LeaveClaimNeedsReview;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-23 — dampak lembur yang ditolak terhadap saldo yang sudah dipakai klaim. */
class BalanceReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function br_23_batch_yang_belum_tersentuh_boleh_berubah_bebas(): void
    {
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '09:00', '19:00');   // 8 jam

        $this->assertSame(480, LeaveBalance::query()->where('user_id', $user->id)->sole()->earned_minutes);

        // Diperpendek jadi 5 jam → turun ke tier 1. Belum ada klaim, jadi aman diubah.
        $record->update(['end_time' => '14:00']);

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(240, $batch->earned_minutes);
        $this->assertSame(BalanceStatus::Active, $batch->status);
    }

    #[Test]
    public function br_23_batch_yang_belum_tersentuh_dihapus_saat_hak_hilang(): void
    {
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '09:00', '19:00');

        // Diperpendek jadi 2 jam → tidak lagi memenuhi tier apa pun.
        $record->update(['end_time' => '11:00']);

        $this->assertSame(0, LeaveBalance::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $record->refresh()->meal_allowance_amount);
    }

    #[Test]
    public function br_23_lembur_ditolak_membuat_batch_void_dan_klaim_perlu_ditinjau(): void
    {
        Event::fake([LeaveClaimNeedsReview::class]);

        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '09:00', '19:00');   // 8 jam
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);  // memakai 8 jam

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(480, $batch->held_minutes);

        // Lembur sumbernya ditolak SETELAH saldonya terpakai.
        $record->update(['status' => OvertimeStatus::Rejected]);

        // Batch dibatalkan...
        $this->assertSame(BalanceStatus::Void, $batch->refresh()->status);
        // ...klaim TIDAK dibatalkan diam-diam, hanya ditandai untuk ditinjau.
        $claim->refresh();
        $this->assertTrue($claim->needs_review);
        $this->assertTrue($claim->status->isActive());
        // ...dan user diberi tahu.
        Event::assertDispatched(LeaveClaimNeedsReview::class);
    }

    #[Test]
    public function br_23_menghapus_record_yang_saldonya_terpakai_juga_memicu_peninjauan(): void
    {
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '09:00', '19:00');
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $record->delete();   // soft delete

        $this->assertSame(BalanceStatus::Void, LeaveBalance::query()->where('user_id', $user->id)->sole()->status);
        $this->assertTrue($claim->refresh()->needs_review);
    }

    #[Test]
    public function br_23_hak_yang_menyusut_di_bawah_yang_terpakai_memicu_void(): void
    {
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '09:00', '19:00');   // 8 jam
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);  // pakai 8 jam

        // Dikoreksi jadi 5 jam → hak turun ke 4 jam, padahal 8 jam sudah ditahan klaim.
        $record->update(['end_time' => '14:00']);

        $this->assertSame(BalanceStatus::Void, LeaveBalance::query()->where('user_id', $user->id)->sole()->status);
        $this->assertTrue($claim->refresh()->needs_review);
    }

    #[Test]
    public function br_23_hak_yang_membesar_tidak_mengganggu_klaim(): void
    {
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-10', '19:00', '23:30');   // 4j30m → tier 1
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::LateArrival);

        // Diperpanjang jadi 9 jam → naik ke tier 2. Hak membesar, tidak ada yang dirugikan.
        $record->update(['end_time' => '04:00']);

        $batch = LeaveBalance::query()->where('user_id', $user->id)->sole();
        $this->assertSame(480, $batch->earned_minutes);
        $this->assertSame(240, $batch->held_minutes);
        $this->assertSame(240, $batch->remainingMinutes());
        $this->assertFalse($claim->refresh()->needs_review);
    }
}
