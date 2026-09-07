<?php

namespace Tests\Unit\Domain;

use App\Domain\Lembur\DurationCalculator as D;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DurationCalculatorTest extends TestCase
{
    #[Test]
    public function br_01_jam_di_luar_09_18_terhitung_lembur(): void
    {
        // Jam kerja normal 09:00–18:00; sesi sore dan sesi pagi sama-sama lembur.
        $this->assertSame(270, D::rawMinutes('18:00', '22:30'));
        $this->assertSame(120, D::rawMinutes('07:00', '09:00'));
    }

    #[Test]
    public function br_03_lembur_melewati_tengah_malam_dihitung_pada_tanggal_mulai(): void
    {
        // 21:00 → 02:00 adalah 5 jam, diikatkan ke tanggal jam mulai.
        $this->assertSame(300, D::rawMinutes('21:00', '02:00'));
        $this->assertTrue(D::crossesMidnight('21:00', '02:00'));
        $this->assertFalse(D::crossesMidnight('19:00', '23:30'));
    }

    #[Test]
    public function br_04_pembulatan_mati_memakai_durasi_apa_adanya(): void
    {
        // 3j50m tetap 230 menit → tidak memenuhi tier 4 jam.
        $this->assertSame(230, D::effectiveMinutes(230, false));
        $this->assertFalse(D::roundingChangedAnything(230, false));
    }

    #[Test]
    public function br_04_pembulatan_nyala_membulatkan_ke_jam_terdekat(): void
    {
        $this->assertSame(240, D::effectiveMinutes(211, true));   // 3j31m → 4 jam
        $this->assertSame(180, D::effectiveMinutes(209, true));   // 3j29m → 3 jam
        $this->assertSame(480, D::effectiveMinutes(455, true));   // 7j35m → 8 jam
        $this->assertSame(240, D::effectiveMinutes(240, true));   // pas 4 jam, tidak berubah
        $this->assertFalse(D::roundingChangedAnything(240, true));
        $this->assertTrue(D::roundingChangedAnything(211, true));
    }

    #[Test]
    public function sesi_yang_tumpang_tindih_terdeteksi(): void
    {
        // F-02 menolak dua record dengan rentang jam bertabrakan di tanggal yang sama.
        $this->assertTrue(D::overlaps('19:00', '23:00', '20:00', '21:00'));
        $this->assertFalse(D::overlaps('07:00', '09:00', '18:00', '21:00'));
        // Bersentuhan di ujung bukan tumpang tindih.
        $this->assertFalse(D::overlaps('07:00', '09:00', '09:00', '11:00'));
    }
}
