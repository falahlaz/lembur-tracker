<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\ClaimValidator;
use App\Enums\ClaimStatus;
use App\Enums\ClaimType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** BR-18 s/d BR-22 — bentuk klaim, kuota, siklus status, dan urutan validasi. */
class ClaimTest extends TestCase
{
    use RefreshDatabase;

    private ClaimValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
        $this->validator = app(ClaimValidator::class);
    }

    /** Memberi user saldo sebanyak $days hari penuh, tersebar di tanggal berbeda. */
    private function grantFullDays(\App\Models\User $user, int $days): void
    {
        for ($i = 0; $i < $days; $i++) {
            $this->logOvertime($user, Carbon::parse('2026-03-10')->addDays($i)->toDateString(), '09:00', '19:00');
        }
    }

    #[Test]
    public function br_18_dua_bentuk_klaim_punya_konsumsi_dan_bobot_berbeda(): void
    {
        $date = Carbon::parse('2026-04-01');

        $this->assertSame(480, $this->validator->minutesRequired($date, ClaimType::FullDay));
        $this->assertSame(240, $this->validator->minutesRequired($date, ClaimType::LateArrival));

        $this->assertSame(1.0, ClaimType::FullDay->quotaWeight());
        $this->assertSame(0.5, ClaimType::LateArrival->quotaWeight());

        $this->assertFalse(ClaimType::FullDay->requiresArrivalTime());
        $this->assertTrue(ClaimType::LateArrival->requiresArrivalTime());
    }

    #[Test]
    public function br_19_kuota_tiga_hari_per_bulan_kalender(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 4);

        // 3 klaim sehari penuh = bobot 3,0 → tepat di batas.
        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-02', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-03', ClaimType::FullDay);

        $this->assertSame(3.0, $this->validator->quotaUsedIn($user, Carbon::parse('2026-04-10')));

        $hasil = $this->validator->checkQuota($user, Carbon::parse('2026-04-06'), ClaimType::FullDay);
        $this->assertTrue($hasil->failed());
        $this->assertSame('BR-19', $hasil->rule);
        $this->assertStringContainsString('Kuota klaim April sudah penuh', $hasil->message);
        $this->assertStringContainsString('3 dari 3 hari', $hasil->message);
    }

    #[Test]
    public function br_19_datang_siang_berbobot_setengah(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 4);

        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);        // 1,0
        $this->submitClaim($user, '2026-04-02', ClaimType::LateArrival);    // 0,5

        $this->assertSame(1.5, $this->validator->quotaUsedIn($user, Carbon::parse('2026-04-05')));
        // Masih muat 1,5 lagi.
        $this->assertFalse($this->validator->checkQuota($user, Carbon::parse('2026-04-06'), ClaimType::FullDay)->failed());
    }

    #[Test]
    public function br_19_kuota_dihitung_per_bulan_kalender_bukan_periode_payroll(): void
    {
        // Lembur awal April → saldonya hidup sampai awal Mei, jadi klaim akhir
        // April di bawah ini murni menguji kuota, bukan masa berlaku.
        $this->freezeDate('2026-04-05');
        $user = $this->employee();
        for ($i = 0; $i < 5; $i++) {
            $this->logOvertime($user, Carbon::parse('2026-04-01')->addDays($i)->toDateString(), '09:00', '19:00');
        }

        // 20 dan 25 April berada di periode payroll Mei, tetapi bulan kalender April.
        $this->submitClaim($user, '2026-04-20', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-25', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-06', ClaimType::FullDay);

        $this->assertSame(3.0, $this->validator->quotaUsedIn($user, Carbon::parse('2026-04-28')));
        $this->assertTrue($this->validator->checkQuota($user, Carbon::parse('2026-04-30'), ClaimType::FullDay)->failed());

        // Bulan Mei mulai dari nol lagi — kuota tidak terbawa.
        $this->assertSame(0.0, $this->validator->quotaUsedIn($user, Carbon::parse('2026-05-04')));
        $this->assertFalse($this->validator->checkQuota($user, Carbon::parse('2026-05-04'), ClaimType::FullDay)->failed());
    }

    #[Test]
    public function br_21_dua_klaim_aktif_tidak_boleh_di_tanggal_yang_sama(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 3);

        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        $hasil = $this->validator->checkOverlap($user, Carbon::parse('2026-04-01'));
        $this->assertTrue($hasil->failed());
        $this->assertSame('BR-21', $hasil->rule);
        $this->assertSame('Sudah ada klaim di tanggal ini.', $hasil->message);

        // Tanggal lain bebas.
        $this->assertFalse($this->validator->checkOverlap($user, Carbon::parse('2026-04-02'))->failed());
    }

    #[Test]
    public function br_21_klaim_yang_dibatalkan_membebaskan_tanggalnya(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 3);

        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        $claim->update(['status' => ClaimStatus::Cancelled]);

        $this->assertFalse($this->validator->checkOverlap($user, Carbon::parse('2026-04-01'))->failed());
    }

    #[Test]
    public function br_22_urutan_validasi_mendahulukan_bentrok_tanggal(): void
    {
        // Kasus yang jadi alasan BR-22 ada: tanggal bentrok DAN saldo habis.
        // Pesan yang muncul harus soal tanggal — menambah saldo tidak akan
        // menyelesaikan apa pun, jadi "saldo tidak cukup" menyesatkan.
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-10', '09:00', '19:00');   // hanya 8 jam

        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay); // saldo terpakai habis

        $hasil = $this->validator->validate($user, Carbon::parse('2026-04-01'), ClaimType::FullDay);

        $this->assertTrue($hasil->failed());
        $this->assertSame('BR-21', $hasil->rule);
        $this->assertStringNotContainsString('Saldo', $hasil->message);
    }

    #[Test]
    public function br_22_kuota_diperiksa_sebelum_saldo(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 3);

        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-02', ClaimType::FullDay);
        $this->submitClaim($user, '2026-04-03', ClaimType::FullDay);

        // Kuota penuh DAN saldo habis; yang dilaporkan adalah kuota.
        $hasil = $this->validator->validate($user, Carbon::parse('2026-04-07'), ClaimType::FullDay);

        $this->assertTrue($hasil->failed());
        $this->assertSame('BR-19', $hasil->rule);
    }

    #[Test]
    public function br_22_saldo_dilaporkan_saat_tanggal_dan_kuota_aman(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-10', '19:00', '23:30');   // hanya 4 jam

        $hasil = $this->validator->validate($user, Carbon::parse('2026-04-05'), ClaimType::FullDay);

        $this->assertTrue($hasil->failed());
        $this->assertSame('BR-16', $hasil->rule);
        $this->assertStringContainsString('Saldo kamu 4 jam', $hasil->message);
        $this->assertStringContainsString('kurang 4 jam', $hasil->message);
        $this->assertStringContainsString('datang lebih siang', $hasil->message);
    }

    #[Test]
    public function br_20_klaim_yang_valid_lolos_seluruh_pemeriksaan(): void
    {
        $user = $this->employee();
        $this->grantFullDays($user, 1);

        $hasil = $this->validator->validate($user, Carbon::parse('2026-04-05'), ClaimType::FullDay);

        $this->assertFalse($hasil->failed());
        $this->assertNull($hasil->rule);
    }

    #[Test]
    public function br_20_status_menentukan_penahanan_saldo_dan_kuota(): void
    {
        $this->assertFalse(ClaimStatus::Draft->holdsBalance());        // draft belum menahan
        $this->assertTrue(ClaimStatus::Submitted->holdsBalance());     // sejak diajukan, ditahan
        $this->assertTrue(ClaimStatus::Approved->holdsBalance());
        $this->assertFalse(ClaimStatus::Taken->holdsBalance());        // sudah dipotong permanen

        $this->assertTrue(ClaimStatus::Rejected->releasesBalance());
        $this->assertTrue(ClaimStatus::Cancelled->releasesBalance());

        $this->assertFalse(ClaimStatus::Draft->consumesQuota());
        $this->assertTrue(ClaimStatus::Taken->consumesQuota());
    }
}
