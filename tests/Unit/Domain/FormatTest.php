<?php

namespace Tests\Unit\Domain;

use App\Support\Format;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Design Brief §5.3 — satu sumber kebenaran format; kalau ada dua, keduanya akan berbeda. */
class FormatTest extends TestCase
{
    #[Test]
    public function rupiah_memakai_titik_ribuan_tanpa_desimal(): void
    {
        $this->assertSame('Rp50.000', Format::rupiah(50_000));
        $this->assertSame('Rp100.000', Format::rupiah(100_000));
        $this->assertSame('Rp0', Format::rupiah(0));
        $this->assertSame('Rp1.250.000', Format::rupiah(1_250_000));
    }

    #[Test]
    public function durasi_ditulis_dengan_kata_penuh_dan_ringkas(): void
    {
        $this->assertSame('4 jam 30 menit', Format::durasi(270));
        $this->assertSame('4 jam', Format::durasi(240));
        $this->assertSame('45 menit', Format::durasi(45));

        $this->assertSame('4j 30m', Format::durasiRingkas(270));
        $this->assertSame('8j', Format::durasiRingkas(480));
    }

    #[Test]
    public function saldo_diterjemahkan_ke_bahasa_manusia(): void
    {
        // P-2 — sistem menyimpan menit, tetapi bicara dalam hari dan datang siang.
        $this->assertSame('1 hari', Format::saldoManusiawi(480));
        $this->assertSame('datang siang 4 jam', Format::saldoManusiawi(240));
        $this->assertSame('1 hari + datang siang 4 jam', Format::saldoManusiawi(720));
        $this->assertSame('2 hari', Format::saldoManusiawi(960));
        $this->assertSame('belum ada saldo', Format::saldoManusiawi(0));

        $this->assertSame('12 jam (1 hari + datang siang 4 jam)', Format::saldo(720));
    }

    #[Test]
    public function sisa_waktu_ditulis_relatif(): void
    {
        $this->assertSame('3 hari lagi', Format::sisaWaktu(3));
        $this->assertSame('besok', Format::sisaWaktu(1));
        $this->assertSame('hari ini', Format::sisaWaktu(0));
        $this->assertSame('sudah lewat 2 hari', Format::sisaWaktu(-2));
    }
}
