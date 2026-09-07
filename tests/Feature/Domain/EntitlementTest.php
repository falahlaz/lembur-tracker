<?php

namespace Tests\Feature\Domain;

use App\Enums\OvertimeStatus;
use App\Enums\Tier;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-02, BR-05, BR-06, BR-07, BR-08 — perolehan hak dari record lembur. */
class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baselineRule();
    }

    #[Test]
    public function br_05_tier_ditentukan_dari_durasi_efektif(): void
    {
        $user = $this->employee();

        $kurang = $this->logOvertime($user, '2026-03-05', '19:00', '22:00');   // 3 jam
        $this->assertSame(Tier::None, $kurang->tier);
        $this->assertSame(0, $kurang->meal_allowance_amount);
        $this->assertSame(0, $kurang->leave_credit_minutes);

        $tier1 = $this->logOvertime($user, '2026-03-06', '19:00', '23:30');    // 4j30m
        $this->assertSame(Tier::Tier1, $tier1->tier);
        $this->assertSame(50_000, $tier1->meal_allowance_amount);
        $this->assertSame(240, $tier1->leave_credit_minutes);

        $tier2 = $this->logOvertime($user, '2026-03-07', '10:00', '18:30');    // 8j30m
        $this->assertSame(Tier::Tier2, $tier2->tier);
        $this->assertSame(100_000, $tier2->meal_allowance_amount);
        $this->assertSame(480, $tier2->leave_credit_minutes);
    }

    #[Test]
    public function br_06_hak_di_capped_tidak_bertingkat(): void
    {
        // Lembur 12 jam tetap Rp100.000 + 8 jam — tidak ada kelipatan di atas tier 2.
        $user = $this->employee();
        $record = $this->logOvertime($user, '2026-03-05', '08:00', '20:00');

        $this->assertSame(720, $record->duration_effective_minutes);
        $this->assertSame(Tier::Tier2, $record->tier);
        $this->assertSame(100_000, $record->meal_allowance_amount);
        $this->assertSame(480, $record->leave_credit_minutes);
    }

    #[Test]
    public function br_02_durasi_beberapa_sesi_dalam_satu_tanggal_dijumlahkan(): void
    {
        // 07:00–09:00 (2j) + 18:00–21:00 (3j) = 5 jam → memenuhi tier 4 jam,
        // meskipun tidak ada satu sesi pun yang sendirian mencapai 4 jam.
        $user = $this->employee();

        $pagi = $this->logOvertime($user, '2026-03-05', '07:00', '09:00');
        $sore = $this->logOvertime($user, '2026-03-05', '18:00', '21:00');

        $this->assertSame(Tier::Tier1, $pagi->refresh()->tier);
        $this->assertSame(Tier::Tier1, $sore->refresh()->tier);
    }

    #[Test]
    public function br_07_satu_tanggal_menghasilkan_paling_banyak_satu_uang_makan(): void
    {
        $user = $this->employee();

        $pagi = $this->logOvertime($user, '2026-03-05', '07:00', '09:00');
        $sore = $this->logOvertime($user, '2026-03-05', '18:00', '21:00');

        // Entitlement menempel pada record PALING AWAL; record lain bernilai nol.
        $this->assertSame(50_000, $pagi->refresh()->meal_allowance_amount);
        $this->assertSame(240, $pagi->leave_credit_minutes);
        $this->assertSame(0, $sore->refresh()->meal_allowance_amount);
        $this->assertSame(0, $sore->leave_credit_minutes);

        // Dan hanya satu batch saldo untuk tanggal itu (BR-12).
        $this->assertSame(1, LeaveBalance::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function br_07_pemegang_hak_berpindah_bila_sesi_lebih_awal_ditambahkan(): void
    {
        $user = $this->employee();

        $sore = $this->logOvertime($user, '2026-03-05', '18:00', '23:00');   // 5 jam, tier 1
        $this->assertSame(50_000, $sore->refresh()->meal_allowance_amount);

        // Sesi pagi ditambahkan belakangan tetapi jam mulainya lebih awal.
        $pagi = $this->logOvertime($user, '2026-03-05', '06:00', '09:00');
        // Total harian jadi 3j + 5j = 8 jam → naik ke tier 2, dan hak berpindah
        // ke sesi pagi karena jam mulainya paling awal.
        $this->assertSame(100_000, $pagi->refresh()->meal_allowance_amount);
        $this->assertSame(480, $pagi->leave_credit_minutes);
        $this->assertSame(0, $sore->refresh()->meal_allowance_amount);
        $this->assertSame(0, $sore->leave_credit_minutes);

        // Batchnya tetap satu — di-arahkan ulang ke pemegang hak yang baru,
        // bukan dibuat ulang, sehingga alokasi yang sudah ada tidak hilang.
        $balances = LeaveBalance::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $balances);
        $this->assertSame($pagi->id, $balances->first()->overtime_record_id);
        $this->assertSame(480, $balances->first()->earned_minutes);
    }

    #[Test]
    public function br_08_hari_kerja_dan_hari_libur_diperlakukan_sama(): void
    {
        // 7 Maret 2026 jatuh Sabtu; 5 Maret 2026 hari Kamis. Tidak ada pengali.
        $user = $this->employee();

        $kamis = $this->logOvertime($user, '2026-03-05', '19:00', '23:30');
        $sabtu = $this->logOvertime($user, '2026-03-07', '09:00', '13:30');

        $this->assertTrue(\Illuminate\Support\Carbon::parse('2026-03-07')->isSaturday());
        $this->assertSame($kamis->meal_allowance_amount, $sabtu->meal_allowance_amount);
        $this->assertSame($kamis->leave_credit_minutes, $sabtu->leave_credit_minutes);
    }

    #[Test]
    public function br_04_pembulatan_per_user_mengubah_hak_untuk_durasi_yang_sama(): void
    {
        // R-2 — risiko yang disadari: dua orang, durasi identik, hak berbeda.
        $tanpa = $this->employee(rounding: false);
        $dengan = $this->employee(rounding: true);

        $a = $this->logOvertime($tanpa, '2026-03-05', '19:00', '22:40');    // 3j40m
        $b = $this->logOvertime($dengan, '2026-03-05', '19:00', '22:40');

        $this->assertSame(220, $a->duration_effective_minutes);
        $this->assertSame(Tier::None, $a->tier);
        $this->assertFalse($a->rounding_applied);

        $this->assertSame(240, $b->duration_effective_minutes);
        $this->assertSame(Tier::Tier1, $b->tier);
        $this->assertTrue($b->rounding_applied);

        // Durasi mentah tetap tersimpan di keduanya — jejak audit BR-04.
        $this->assertSame(220, $a->duration_raw_minutes);
        $this->assertSame(220, $b->duration_raw_minutes);
    }

    #[Test]
    public function br_23_record_ditolak_tidak_menyumbang_ke_total_harian(): void
    {
        $user = $this->employee();

        $pagi = $this->logOvertime($user, '2026-03-05', '07:00', '09:00');
        $sore = $this->logOvertime($user, '2026-03-05', '18:00', '21:00', OvertimeStatus::Rejected);

        // Hanya 2 jam yang terhitung → belum mencapai tier.
        $this->assertSame(Tier::None, $pagi->refresh()->tier);
        $this->assertSame(0, $pagi->meal_allowance_amount);
        $this->assertSame(0, LeaveBalance::query()->where('user_id', $user->id)->count());
    }
}
