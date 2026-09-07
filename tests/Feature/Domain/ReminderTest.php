<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\BalanceMaintenance;
use App\Domain\Lembur\ReminderDispatcher;
use App\Enums\BalanceStatus;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Mail\CutOffMendekatMail;
use App\Mail\KlaimPerluDitinjauMail;
use App\Mail\RingkasanPeriodeMail;
use App\Mail\SaldoAkanHangusMail;
use App\Models\LeaveBalance;
use App\Models\LeaveClaim;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-07 + §9.3 — reminder dan pemeliharaan harian. */
class ReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baselineRule();
    }

    #[Test]
    public function reminder_h7_dan_h2_terkirim_tepat_pada_harinya(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-20');
        $user = $this->employee();

        // Hangus 27 Mar → H-7 hari ini.
        $this->logOvertime($user, '2026-02-27', '19:00', '23:30');

        $sent = app(ReminderDispatcher::class)->sendExpiryReminders();

        $this->assertSame(1, $sent);
        Mail::assertSent(SaldoAkanHangusMail::class, fn ($mail) => $mail->daysLeft === 7);
        $this->assertTrue(NotificationLog::alreadySent(
            $user->id,
            NotificationLog::EXPIRY_H7,
            LeaveBalance::query()->sole()->id,
        ));
    }

    #[Test]
    public function reminder_tidak_pernah_terkirim_dua_kali(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-20');
        $user = $this->employee();
        $this->logOvertime($user, '2026-02-27', '19:00', '23:30');

        $dispatcher = app(ReminderDispatcher::class);

        $this->assertSame(1, $dispatcher->sendExpiryReminders());
        // Scheduler jalan dua kali tidak boleh jadi dua email.
        $this->assertSame(0, $dispatcher->sendExpiryReminders());
        Mail::assertSentCount(1);
    }

    #[Test]
    public function reminder_dapat_dimatikan_per_user(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-20');
        $user = $this->employee();
        $user->update(['notification_prefs' => [NotificationLog::EXPIRY_H7 => false]]);

        $this->logOvertime($user, '2026-02-27', '19:00', '23:30');

        $this->assertSame(0, app(ReminderDispatcher::class)->sendExpiryReminders());
        Mail::assertNothingSent();
    }

    #[Test]
    public function reminder_cutoff_hanya_untuk_yang_punya_lembur_belum_disetujui(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-17');

        $punya = $this->employee();
        $this->logOvertime($punya, '2026-03-10', '19:00', '23:30', OvertimeStatus::Recorded);

        $beres = $this->employee();
        $this->logOvertime($beres, '2026-03-11', '19:00', '23:30', OvertimeStatus::Approved);

        $this->assertSame(1, app(ReminderDispatcher::class)->sendCutoffReminders());

        // Tidak ada email "kamu tidak perlu melakukan apa-apa".
        Mail::assertSent(CutOffMendekatMail::class, 1);
        Mail::assertSent(CutOffMendekatMail::class, fn (CutOffMendekatMail $m) => $m->hasTo($punya->email));
        Mail::assertNotSent(CutOffMendekatMail::class, fn (CutOffMendekatMail $m) => $m->hasTo($beres->email));
    }

    #[Test]
    public function ringkasan_periode_merekap_periode_yang_baru_ditutup(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-19');
        $user = $this->employee();

        // Periode yang ditutup 18 Maret.
        $this->logOvertime($user, '2026-03-10', '09:00', '19:00', OvertimeStatus::Approved);

        $this->assertSame(1, app(ReminderDispatcher::class)->sendPeriodSummaries());

        Mail::assertSent(RingkasanPeriodeMail::class, function ($mail) {
            return $mail->period->label === 'Maret 2026'
                && $mail->estimate->maximum === 100_000
                && $mail->leaveMinutesEarned === 480;
        });
    }

    #[Test]
    public function job_harian_menuntaskan_klaim_yang_tanggalnya_sudah_lewat(): void
    {
        $this->freezeDate('2026-03-20');
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $claim = $this->submitClaim($user, '2026-03-25', ClaimType::FullDay);
        $claim->update(['status' => ClaimStatus::Approved]);

        // Hari berganti melewati tanggal cuti.
        $this->freezeDate('2026-03-26');
        $result = app(BalanceMaintenance::class)->run();

        $this->assertSame(1, $result['settled']);
        $this->assertSame(ClaimStatus::Taken, $claim->refresh()->status);

        $batch = LeaveBalance::query()->sole();
        $this->assertSame(0, $batch->held_minutes);
        $this->assertSame(480, $batch->consumed_minutes);
        $this->assertSame(BalanceStatus::Used, $batch->status);
    }

    #[Test]
    public function job_harian_menandai_batch_yang_lewat_masa_berlaku(): void
    {
        $this->freezeDate('2026-03-20');
        $user = $this->employee();
        $this->logOvertime($user, '2026-02-15', '19:00', '23:30');   // hangus 15 Mar

        $result = app(BalanceMaintenance::class)->run();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(BalanceStatus::Expired, LeaveBalance::query()->sole()->status);
    }

    #[Test]
    public function job_harian_tidak_menghanguskan_saldo_yang_masih_ditahan_klaim(): void
    {
        // Klaim di hari terakhir masa berlaku baru di-settle keesokan harinya;
        // saldonya tidak boleh keburu dihitung hangus di sela itu.
        $this->freezeDate('2026-03-20');
        $user = $this->employee();
        $this->logOvertime($user, '2026-02-25', '09:00', '19:00');   // hangus 25 Mar
        $claim = $this->submitClaim($user, '2026-03-25', ClaimType::FullDay);
        $claim->update(['status' => ClaimStatus::Approved]);

        $this->freezeDate('2026-03-26');
        $result = app(BalanceMaintenance::class)->run();

        $this->assertSame(1, $result['settled']);
        $this->assertSame(0, $result['expired']);
        $this->assertSame(BalanceStatus::Used, LeaveBalance::query()->sole()->status);
    }

    #[Test]
    public function br_23_mengirim_notifikasi_klaim_perlu_ditinjau(): void
    {
        Mail::fake();
        $this->freezeDate('2026-03-20');
        $user = $this->employee();

        $record = $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $record->update(['status' => OvertimeStatus::Rejected]);

        Mail::assertSent(KlaimPerluDitinjauMail::class);
        $this->assertTrue(LeaveClaim::query()->sole()->needs_review);
    }
}
