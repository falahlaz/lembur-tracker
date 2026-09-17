<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\ParsedEntry;
use App\Domain\Timesheet\TimesheetWorkbookParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Format workbook timesheet. Seluruh berkas ini lewat seam array-in — tidak ada
 * satu pun .xlsx di sini. Jalur baca berkasnya diuji terpisah di WorkbookReaderTest.
 */
class TimesheetParserTest extends TestCase
{
    private TimesheetWorkbookParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TimesheetWorkbookParser;
    }

    /** Serial Excel untuk 2026-08-18. */
    private const SERIAL_18_AGUSTUS = 46252;

    private function sel(int $activityId, string $deskripsi): string
    {
        return "Activity ID: {$activityId}\n\n{$deskripsi}";
    }

    /**
     * Membangun satu sheet dalam bentuk yang dihasilkan pembaca: nomor baris →
     * huruf kolom → nilai.
     *
     * @param  array<int, string>  $slots  label kolom A, mulai baris 5
     * @param  array<string, array<int, ?string>>  $isi  huruf kolom → indeks slot → teks sel
     */
    private function sheet(array $slots, array $isi = [], int $serialAwal = self::SERIAL_18_AGUSTUS, ?int $project = 105, ?int $customer = 112): array
    {
        $rows = [];

        if ($customer !== null) {
            $rows[1] = ['A' => 'Customer ID', 'B' => $customer];
        }

        if ($project !== null) {
            $rows[2] = ['A' => 'Project ID', 'B' => $project];
        }

        $baris4 = [];
        $kolom = array_keys($isi) ?: ['B'];

        foreach (array_values($kolom) as $i => $huruf) {
            $baris4[$huruf] = $serialAwal + $i;
        }

        $rows[4] = $baris4;

        foreach ($slots as $i => $label) {
            $baris = ['A' => $label];

            foreach ($isi as $huruf => $perSlot) {
                if (($perSlot[$i] ?? null) !== null) {
                    $baris[$huruf] = $perSlot[$i];
                }
            }

            $rows[5 + $i] = $baris;
        }

        return $rows;
    }

    private const SLOT_DAILY = ['9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM', '5 PM - 6 PM'];

    private const SLOT_OVERTIME = [
        '12 AM - 2 AM', '2 AM - 4 AM', '4 AM - 5 AM', '5 AM - 6 AM',
        '9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM',
        '5 PM - 6 PM', '6 PM - 8 PM', '8 PM - 10 PM', '10 PM - 12 PM',
    ];

    #[Test]
    public function sheet_overtime_membaca_slot_di_bawah_baris_sembilan(): void
    {
        // Regresi langsung untuk cacat importer lama: ia mengunci baris 5–9 untuk
        // kedua sheet, jadi baris 10–16 sheet Overtime — termasuk seluruh slot
        // malam yang justru paling sering dipakai — tidak pernah terbaca.
        $book = $this->parser->parse([
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, [
                'B' => [
                    9 => $this->sel(9, 'Deploy malam'),   // baris 14, '6 PM - 8 PM'
                    10 => $this->sel(9, 'Deploy malam'),  // baris 15, '8 PM - 10 PM'
                    11 => $this->sel(9, 'Deploy malam'),  // baris 16, '10 PM - 12 PM'
                ],
            ]),
        ]);

        $this->assertCount(3, $book->entries);

        $jam = array_map(fn (ParsedEntry $e) => $e->beginAt->format('H:i').'-'.$e->endAt->format('H:i'), $book->entries);
        $this->assertSame(['18:00-20:00', '20:00-22:00', '22:00-00:00'], $jam);
    }

    #[Test]
    public function dua_sheet_punya_jumlah_baris_slot_berbeda_dan_keduanya_terbaca_penuh(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, [
                'B' => array_fill(0, 5, $this->sel(25, 'Daily Meeting')),
            ]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, [
                'B' => [9 => $this->sel(9, 'Deploy')],
            ]),
        ]);

        $this->assertCount(5, $book->forSheet('Daily'));
        $this->assertCount(1, $book->forSheet('Overtime'));
        $this->assertSame([], $book->issues);
    }

    #[Test]
    public function entri_overtime_diberi_tag_dan_entri_daily_tidak(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting')]]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [9 => $this->sel(9, 'Deploy')]]),
        ]);

        $this->assertNull($book->forSheet('Daily')[0]->tag, 'Entri Daily tidak boleh bertag sama sekali.');
        $this->assertSame('Overtime', $book->forSheet('Overtime')[0]->tag);
    }

    #[Test]
    public function tag_overtime_mengikuti_konfigurasi_sync(): void
    {
        // Kalau tag yang diposting berbeda dari yang ditarik sync, entri lembur
        // masuk ke Kimai tetapi tidak pernah menjadi catatan lembur di sini.
        config(['kimai.tags' => ['OT']]);

        $book = $this->parser->parse([
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [9 => $this->sel(9, 'Deploy')]]),
        ]);

        $this->assertSame('OT', $book->forSheet('Overtime')[0]->tag);
    }

    #[Test]
    public function penanda_activity_dibuang_dari_deskripsi_tetapi_newline_isinya_bertahan(): void
    {
        $teks = "Activity ID: 8\n\nSprint 8 - MTA-1867\n1. Review AI generated code\n2. Perbaiki service-payment";

        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $teks]]),
        ]);

        $entry = $book->entries[0];

        $this->assertSame(8, $entry->activityId);
        $this->assertSame(
            "Sprint 8 - MTA-1867\n1. Review AI generated code\n2. Perbaiki service-payment",
            $entry->description,
        );
    }

    #[Test]
    public function sel_kosong_dilewati_tanpa_keluhan(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting'), 2 => '   ']]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME),
        ]);

        $this->assertCount(1, $book->entries);
        $this->assertSame([], $book->issues);
    }

    #[Test]
    public function sel_berisi_teks_tanpa_penanda_activity_dilaporkan_bukan_hilang_diam_diam(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => 'Cuti tahunan']]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME),
        ]);

        $this->assertSame([], $book->entries);
        $this->assertCount(1, $book->issues);
        $this->assertStringContainsString("tanpa 'Activity ID'", $book->issues[0]);
    }

    #[Test]
    public function label_jam_tak_dikenali_dilaporkan(): void
    {
        // Persis keadaan yang membuat importer lama kehilangan entri tanpa suara.
        $book = $this->parser->parse([
            'Daily' => $this->sheet(['9 AM - 10 AM', 'Jam Fleksibel'], [
                'B' => [0 => $this->sel(25, 'Daily Meeting'), 1 => $this->sel(8, 'Ngoding')],
            ]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME),
        ]);

        $this->assertCount(1, $book->entries);
        $this->assertCount(1, $book->issues);
        $this->assertStringContainsString("'Jam Fleksibel' tidak dikenali", $book->issues[0]);
    }

    #[Test]
    public function sheet_yang_hilang_dilaporkan_dan_sheet_lainnya_tetap_terbaca(): void
    {
        $book = $this->parser->parse(
            ['Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting')]])],
            availableSheets: ['Daily', 'Sheet3'],
        );

        $this->assertCount(1, $book->entries);
        $this->assertCount(1, $book->issues);
        $this->assertStringContainsString("Sheet 'Overtime' tidak ditemukan", $book->issues[0]);
        $this->assertStringContainsString('Daily, Sheet3', $book->issues[0]);
    }

    #[Test]
    public function project_id_dibaca_dari_labelnya_bukan_dari_posisi_sel(): void
    {
        // Catatan importer lama "sel Project ID terbaca 112, itu salah" sebenarnya
        // salah baca — 112 adalah Customer ID. Di sini barisnya sengaja dibalik
        // untuk membuktikan yang dibaca memang labelnya.
        $rows = [
            1 => ['A' => 'Project ID', 'B' => 105],
            2 => ['A' => 'Customer ID', 'B' => 112],
            4 => ['B' => self::SERIAL_18_AGUSTUS],
            5 => ['A' => '9 AM - 10 AM', 'B' => $this->sel(25, 'Daily Meeting')],
        ];

        $book = $this->parser->parse(['Daily' => $rows]);

        $this->assertSame(105, $book->projectId);
        $this->assertSame(112, $book->customerId);
    }

    #[Test]
    public function serial_excel_di_baris_tanggal_menjadi_tanggal_yang_benar(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting')]]),
        ]);

        $this->assertSame('2026-08-18', $book->entries[0]->workDate->toDateString());
        $this->assertSame('2026-08-18 09:00:00', $book->entries[0]->beginAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function baris_tanggal_dicari_bukan_diasumsikan_baris_empat(): void
    {
        $rows = [
            1 => ['A' => 'Project ID', 'B' => 105],
            6 => ['B' => self::SERIAL_18_AGUSTUS, 'C' => self::SERIAL_18_AGUSTUS + 1],
            7 => ['A' => '9 AM - 10 AM', 'B' => $this->sel(25, 'Daily Meeting'), 'C' => $this->sel(25, 'Daily Meeting')],
        ];

        $book = $this->parser->parse(['Daily' => $rows]);

        $this->assertCount(2, $book->entries);
        $this->assertSame('2026-08-19', $book->entries[1]->workDate->toDateString());
    }

    #[Test]
    public function slot_yang_tutup_tengah_malam_berakhir_di_tanggal_berikutnya(): void
    {
        $book = $this->parser->parse([
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [11 => $this->sel(9, 'Deploy')]]),
        ]);

        $entry = $book->entries[0];

        $this->assertSame('2026-08-18', $entry->workDate->toDateString(), 'Tanggal kerja tetap tanggal kolomnya.');
        $this->assertSame('2026-08-18 22:00:00', $entry->beginAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-19 00:00:00', $entry->endAt->format('Y-m-d H:i:s'));
        $this->assertSame(120, $entry->durationMinutes());
    }

    #[Test]
    public function slot_yang_sama_di_daily_dan_overtime_terdeteksi_bentrok(): void
    {
        // Kedua sheet berbagi lima label yang sama persis. Tanpa pemeriksaan ini,
        // jam kerja biasa ikut terkirim bertag Overtime dan berubah jadi catatan
        // lembur palsu setelah disync.
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting')]]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [4 => $this->sel(9, 'Deploy')]]),
        ]);

        $this->assertCount(2, $book->entries);
        $this->assertCount(1, $book->postable(), 'Hanya satu yang boleh dikirim.');

        $ditolak = $book->forSheet('Overtime')[0];
        $this->assertNotNull($ditolak->skipReason);
        $this->assertFalse($ditolak->overridable, 'Yang keliru adalah berkasnya, bukan Kimai.');
        $this->assertStringContainsString('Daily!B5', $ditolak->skipReason);
    }

    #[Test]
    public function rentang_workbook_mencakup_slot_yang_melewati_tengah_malam(): void
    {
        $book = $this->parser->parse([
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [11 => $this->sel(9, 'Deploy')]]),
        ]);

        $this->assertSame('2026-08-18 22:00:00', $book->rangeStart()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-19 00:00:00', $book->rangeEnd()->format('Y-m-d H:i:s'));
        $this->assertSame(120, $book->totalMinutes());
    }

    #[Test]
    public function payload_post_hanya_memuat_field_yang_diizinkan(): void
    {
        $book = $this->parser->parse([
            'Daily' => $this->sheet(self::SLOT_DAILY, ['B' => [0 => $this->sel(25, 'Daily Meeting')]]),
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [9 => $this->sel(9, 'Deploy')]]),
        ]);

        $daily = $book->forSheet('Daily')[0]->toKimaiPayload(105);

        $this->assertSame(['begin', 'end', 'project', 'activity', 'description'], array_keys($daily));
        $this->assertArrayNotHasKey('billable', $daily, 'billable ditolak "This form should not contain extra fields".');
        $this->assertArrayNotHasKey('tags', $daily, 'tags kosong ditolak "This value is not valid."');

        $overtime = $book->forSheet('Overtime')[0]->toKimaiPayload(105);

        $this->assertSame(['begin', 'end', 'project', 'activity', 'description', 'tags'], array_keys($overtime));
        $this->assertIsString($overtime['tags'], 'tags harus STRING dipisah koma, bukan array.');
        $this->assertSame('Overtime', $overtime['tags']);
    }

    #[Test]
    public function payload_menulis_offset_tanpa_titik_dua(): void
    {
        // toIso8601String() memberi "+07:00"; yang terbukti diterima Kimai "+0700".
        $book = $this->parser->parse([
            'Overtime' => $this->sheet(self::SLOT_OVERTIME, ['B' => [11 => $this->sel(9, 'Deploy')]]),
        ]);

        $payload = $book->entries[0]->toKimaiPayload(105);

        $this->assertSame('2026-08-18T22:00:00+0700', $payload['begin']);
        $this->assertSame('2026-08-19T00:00:00+0700', $payload['end']);
    }
}
