<?php

namespace Tests\Unit\Domain;

use App\Domain\Timesheet\SlotLabel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlotLabelTest extends TestCase
{
    /**
     * Keduabelas label yang benar-benar ada di template, Daily dan Overtime.
     * Tabel ini versi yang bisa dijalankan dari dokumentasi formatnya.
     *
     * @return array<string, array{string, int, int}>
     */
    public static function labelTemplate(): array
    {
        return [
            // Daily
            '9 AM - 10 AM' => ['9 AM - 10 AM', 9, 10],
            '10 AM - 12 AM' => ['10 AM - 12 AM', 10, 12],
            '1 PM - 3 PM' => ['1 PM - 3 PM', 13, 15],
            '3 PM - 5 PM' => ['3 PM - 5 PM', 15, 17],
            '5 PM - 6 PM' => ['5 PM - 6 PM', 17, 18],
            // Overtime — enam di antaranya tidak pernah terbaca importer lama.
            '12 AM - 2 AM' => ['12 AM - 2 AM', 0, 2],
            '2 AM - 4 AM' => ['2 AM - 4 AM', 2, 4],
            '4 AM - 5 AM' => ['4 AM - 5 AM', 4, 5],
            '5 AM - 6 AM' => ['5 AM - 6 AM', 5, 6],
            '6 PM - 8 PM' => ['6 PM - 8 PM', 18, 20],
            '8 PM - 10 PM' => ['8 PM - 10 PM', 20, 22],
            '10 PM - 12 PM' => ['10 PM - 12 PM', 22, 24],
        ];
    }

    #[Test]
    #[DataProvider('labelTemplate')]
    public function seluruh_label_template_terbaca_dengan_jam_yang_benar(string $raw, int $start, int $end): void
    {
        $slot = SlotLabel::parse($raw);

        $this->assertNotNull($slot, "Label '{$raw}' seharusnya terbaca.");
        $this->assertSame($start, $slot->startHour);
        $this->assertSame($end, $slot->endHour);
    }

    #[Test]
    #[DataProvider('labelTemplate')]
    public function tidak_ada_slot_template_yang_melebihi_dua_jam(string $raw): void
    {
        // Kimai membatasi satu timesheet maksimal 2 jam. Kalau template suatu saat
        // memuat slot yang lebih panjang, entrinya akan ditolak server satu per
        // satu — lebih baik ketahuan di sini.
        $this->assertLessThanOrEqual(120, SlotLabel::parse($raw)->durationMinutes());
    }

    #[Test]
    public function angka_12_dibaca_terbalik_sesuai_template(): void
    {
        // Inti dari seluruh kelas ini. Template menulis "12 AM" untuk tengah hari
        // dan "12 PM" untuk tengah malam; dibaca apa adanya, keduanya salah.
        $siang = SlotLabel::parse('10 AM - 12 AM');
        $this->assertSame(10, $siang->startHour);
        $this->assertSame(12, $siang->endHour, '"12 AM" di akhir slot berarti tengah hari.');

        $malam = SlotLabel::parse('10 PM - 12 PM');
        $this->assertSame(22, $malam->startHour);
        $this->assertSame(24, $malam->endHour, '"12 PM" di akhir slot berarti tengah malam.');
    }

    #[Test]
    public function slot_yang_berakhir_jam_24_jatuh_di_tengah_malam_hari_berikutnya(): void
    {
        $slot = SlotLabel::parse('10 PM - 12 PM');
        $tanggal = CarbonImmutable::parse('2026-08-18', config('kimai.timezone'));

        $this->assertSame('2026-08-18 22:00:00', $slot->beginAt($tanggal)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-19 00:00:00', $slot->endAt($tanggal)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function jam_disusun_di_zona_kimai_bukan_zona_aplikasi(): void
    {
        // Zona aplikasi UTC; menyusun slot di sana menggeser semuanya tujuh jam.
        $slot = SlotLabel::parse('9 AM - 10 AM');
        $tanggal = CarbonImmutable::parse('2026-08-18', 'UTC');

        $this->assertSame('+07:00', $slot->beginAt($tanggal)->format('P'));
        $this->assertSame('2026-08-18 09:00:00', $slot->beginAt($tanggal)->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function rentang_yang_benar_benar_melewati_tengah_malam_ditolak(): void
    {
        // Perbaikan "+12" menyandikan pembalikan angka 12, bukan aturan umum lewat
        // tengah malam. "11 PM - 1 AM" jadi 23:00–13:00 kalau dipaksakan — durasi
        // negatif. Menolaknya membuat template yang berubah kelihatan, bukan
        // diam-diam mengirim entri ngawur.
        $this->assertNull(SlotLabel::parse('11 PM - 1 AM'));
        $this->assertNull(SlotLabel::parse('9 PM - 2 AM'));
    }

    #[Test]
    public function teks_yang_bukan_slot_jam_dikembalikan_null(): void
    {
        foreach ([null, '', '   ', 'Total', 'Customer ID', '9 AM', '9 - 10', '25 AM - 30 PM', '0 AM - 2 AM'] as $bukan) {
            $this->assertNull(SlotLabel::parse($bukan), var_export($bukan, true).' bukan slot jam.');
        }
    }

    #[Test]
    public function tanda_pisah_dan_spasi_yang_berbeda_tetap_terbaca(): void
    {
        // Workbook-nya diedit bergantian di Excel, Numbers, dan Google Sheets;
        // ketiganya menulis tanda pisah dan spasi yang berbeda.
        foreach (['9 AM - 10 AM', '9AM-10AM', "9 AM \u{2013} 10 AM", "9 AM \u{2014} 10 AM", "9\u{00A0}AM - 10 AM", '  9 am - 10 am  '] as $varian) {
            $slot = SlotLabel::parse($varian);

            $this->assertNotNull($slot, var_export($varian, true).' seharusnya terbaca.');
            $this->assertSame(9, $slot->startHour);
            $this->assertSame(10, $slot->endHour);
        }
    }
}
