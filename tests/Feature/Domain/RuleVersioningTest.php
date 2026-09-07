<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\RuleResolver;
use App\Enums\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-24 — aturan berversi; revisi SOP tidak menulis ulang sejarah. */
class RuleVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-07-10');
    }

    #[Test]
    public function br_24_aturan_dipilih_berdasarkan_tanggal_lembur(): void
    {
        $lama = $this->baselineRule([
            'effective_from' => '2020-01-01',
            'effective_to' => '2026-05-31',
        ]);
        $baru = $this->baselineRule([
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'tier1_meal_amount' => 75_000,
            'tier2_meal_amount' => 150_000,
        ]);

        $resolver = app(RuleResolver::class);

        $this->assertSame($lama->id, $resolver->forDate(Date::parse('2026-05-31'))->id);
        $this->assertSame($baru->id, $resolver->forDate(Date::parse('2026-06-01'))->id);
    }

    #[Test]
    public function br_24_record_lama_tetap_memakai_nominal_lama(): void
    {
        $lama = $this->baselineRule(['effective_from' => '2020-01-01', 'effective_to' => '2026-05-31']);
        $baru = $this->baselineRule([
            'effective_from' => '2026-06-01',
            'tier1_meal_amount' => 75_000,
        ]);

        $user = $this->employee();

        $sebelum = $this->logOvertime($user, '2026-05-20', '19:00', '23:30');
        $sesudah = $this->logOvertime($user, '2026-06-20', '19:00', '23:30');

        // Nominal mengikuti aturan yang berlaku saat lembur itu terjadi.
        $this->assertSame(50_000, $sebelum->meal_allowance_amount);
        $this->assertSame(75_000, $sesudah->meal_allowance_amount);

        // Dan setiap record mengunci versi yang dipakainya.
        $this->assertSame($lama->id, $sebelum->rule_version_id);
        $this->assertSame($baru->id, $sesudah->rule_version_id);
    }

    #[Test]
    public function br_24_threshold_jam_ikut_berversi(): void
    {
        $this->baselineRule(['effective_from' => '2020-01-01', 'effective_to' => '2026-05-31']);
        $this->baselineRule([
            'effective_from' => '2026-06-01',
            'tier1_min_minutes' => 180,   // SOP direvisi: cukup 3 jam
        ]);

        $user = $this->employee();

        $sebelum = $this->logOvertime($user, '2026-05-20', '19:00', '22:00');   // 3 jam
        $sesudah = $this->logOvertime($user, '2026-06-20', '19:00', '22:00');   // 3 jam

        $this->assertSame(Tier::None, $sebelum->tier);
        $this->assertSame(Tier::Tier1, $sesudah->tier);
    }

    #[Test]
    public function br_24_cut_off_dan_masa_berlaku_ikut_berversi(): void
    {
        $this->baselineRule([
            'effective_from' => '2026-06-01',
            'cutoff_day' => 25,
            'expiry_months' => 2,
        ]);

        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-06-20', '09:00', '19:00');

        // Cut-off 25: lembur 20 Juni masih masuk periode yang berakhir 24 Juni.
        $this->assertSame('2026-06-24', $record->payrollPeriod->period_end->toDateString());
        // Masa berlaku 2 bulan.
        $this->assertSame('2026-08-20', $record->leaveBalance->expires_at->toDateString());
    }
}
