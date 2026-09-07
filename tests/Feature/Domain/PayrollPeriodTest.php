<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\MealAllowanceEstimator;
use App\Domain\Lembur\PayrollPeriodResolver;
use App\Enums\OvertimeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-09, BR-10, BR-11 — periode payroll dan cut-off tanggal 19. */
class PayrollPeriodTest extends TestCase
{
    use RefreshDatabase;

    private PayrollPeriodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baselineRule();
        $this->resolver = app(PayrollPeriodResolver::class);
    }

    #[Test]
    public function br_09_cut_off_tanggal_19_memisahkan_dua_periode(): void
    {
        // Lembur 18 Maret masih masuk periode Maret (19 Feb – 18 Mar)...
        $maret = $this->resolver->boundsFor(Carbon::parse('2026-03-18'));
        $this->assertSame('2026-02-19', $maret['start']->toDateString());
        $this->assertSame('2026-03-18', $maret['end']->toDateString());
        $this->assertSame('Maret 2026', $maret['label']);

        // ...sedangkan lembur 19 Maret sudah masuk periode April.
        $april = $this->resolver->boundsFor(Carbon::parse('2026-03-19'));
        $this->assertSame('2026-03-19', $april['start']->toDateString());
        $this->assertSame('2026-04-18', $april['end']->toDateString());
        $this->assertSame('April 2026', $april['label']);
    }

    #[Test]
    public function br_09_periode_menyeberang_pergantian_tahun(): void
    {
        $bounds = $this->resolver->boundsFor(Carbon::parse('2026-12-20'));

        $this->assertSame('2026-12-19', $bounds['start']->toDateString());
        $this->assertSame('2027-01-18', $bounds['end']->toDateString());
        $this->assertSame('Januari 2027', $bounds['label']);
    }

    #[Test]
    public function br_09_record_lembur_terikat_ke_periode_yang_benar(): void
    {
        $user = $this->employee();

        $sebelum = $this->logOvertime($user, '2026-03-18', '19:00', '23:30');
        $sesudah = $this->logOvertime($user, '2026-03-19', '19:00', '23:30');

        $this->assertSame('Maret 2026', $sebelum->payrollPeriod->label);
        $this->assertSame('April 2026', $sesudah->payrollPeriod->label);
    }

    #[Test]
    public function br_10_estimasi_maksimal_dan_sudah_pasti_dibedakan(): void
    {
        $user = $this->employee();

        // Tiga tanggal berbeda, masing-masing tier 1 (Rp50.000).
        $this->logOvertime($user, '2026-03-02', '19:00', '23:30', OvertimeStatus::Recorded);
        $this->logOvertime($user, '2026-03-03', '19:00', '23:30', OvertimeStatus::Submitted);
        $this->logOvertime($user, '2026-03-04', '19:00', '23:30', OvertimeStatus::Approved);
        // Yang ditolak tidak masuk hitungan mana pun.
        $this->logOvertime($user, '2026-03-06', '19:00', '23:30', OvertimeStatus::Rejected);

        $period = $this->resolver->resolve(Carbon::parse('2026-03-04'));
        $estimate = app(MealAllowanceEstimator::class)->forPeriod($user, $period);

        $this->assertSame(150_000, $estimate->maximum);   // dicatat + diajukan + disetujui
        $this->assertSame(50_000, $estimate->certain);    // hanya yang disetujui
        $this->assertSame(100_000, $estimate->pending());
        $this->assertSame(3, $estimate->recordCount);
    }

    #[Test]
    public function br_11_record_yang_melewati_cut_off_ditandai_berisiko(): void
    {
        // Lembur 5 Maret masuk periode yang ditutup 18 Maret.
        $lembur = Carbon::parse('2026-03-05');

        // Dicatat 10 Maret — masih di dalam periode, aman.
        $this->assertFalse($this->resolver->isPastCutoff($lembur, Carbon::parse('2026-03-10')));
        // Dicatat tepat di hari cut-off — masih aman.
        $this->assertFalse($this->resolver->isPastCutoff($lembur, Carbon::parse('2026-03-18')));
        // Dicatat 20 Maret — periodenya sudah lewat, pasang peringatan.
        $this->assertTrue($this->resolver->isPastCutoff($lembur, Carbon::parse('2026-03-20')));
    }

    #[Test]
    public function br_11_sisa_hari_menuju_cut_off_dihitung_untuk_timeline(): void
    {
        // Timeline dashboard menjawab "masih sempat nggak saya catat lembur minggu lalu?"
        $this->assertSame(8, $this->resolver->daysUntilCutoff(Carbon::parse('2026-09-10')));
        $this->assertSame(0, $this->resolver->daysUntilCutoff(Carbon::parse('2026-09-18')));
    }
}
