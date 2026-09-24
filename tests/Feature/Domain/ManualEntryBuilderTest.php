<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\Exceptions\InvalidManualInput;
use App\Domain\Timesheet\ManualEntryBuilder;
use App\Domain\Timesheet\ParsedEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualEntryBuilderTest extends TestCase
{
    private function baris(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '2026-09-22',
            'mulai' => '09:00',
            'selesai' => '18:00',
            'activity_id' => 8,
            'deskripsi' => 'Development fitur A',
            'lembur' => false,
        ], $overrides);
    }

    /** @param array<int, ParsedEntry> $entries */
    private function jam(array $entries): array
    {
        return array_map(fn (ParsedEntry $e) => $e->slotLabel, $entries);
    }

    #[Test]
    public function satu_hari_penuh_dipecah_per_dua_jam_dari_jam_mulai(): void
    {
        $book = (new ManualEntryBuilder)->build([$this->baris()], 105, [8 => '31_DEV_FEATURE']);

        $this->assertSame(
            ['09:00–11:00', '11:00–13:00', '13:00–15:00', '15:00–17:00', '17:00–18:00'],
            $this->jam($book->entries),
        );
        $this->assertSame(540, $book->totalMinutes());
        $this->assertSame('31_DEV_FEATURE', $book->entries[0]->activityName);
        $this->assertSame('Baris 1 (2/5)', $book->entries[1]->cellRef);
        $this->assertSame(105, $book->projectId);

        foreach ($book->entries as $entry) {
            $this->assertLessThanOrEqual(ManualEntryBuilder::MAX_MINUTES, $entry->durationMinutes());
        }
    }

    #[Test]
    public function sisa_ganjil_menjadi_potongan_terakhir_yang_lebih_pendek(): void
    {
        $book = (new ManualEntryBuilder)->build([$this->baris(['selesai' => '12:30:00', 'mulai' => '09:00:00'])], 105);

        $this->assertSame(['09:00–11:00', '11:00–12:30'], $this->jam($book->entries));
        $this->assertSame('Baris 1', (new ManualEntryBuilder)->build([$this->baris(['selesai' => '10:00'])], 105)->entries[0]->cellRef);
    }

    #[Test]
    public function payload_kimai_memakai_zona_kimai_dengan_offset_tanpa_titik_dua(): void
    {
        $book = (new ManualEntryBuilder)->build([$this->baris(['selesai' => '10:00'])], 105);

        $this->assertSame([
            'begin' => '2026-09-22T09:00:00+0700',
            'end' => '2026-09-22T10:00:00+0700',
            'project' => 105,
            'activity' => 8,
            'description' => 'Development fitur A',
        ], $book->entries[0]->toKimaiPayload(105));
    }

    #[Test]
    public function selesai_jam_nol_berarti_tengah_malam_dan_lembur_membawa_tag(): void
    {
        $book = (new ManualEntryBuilder)->build(
            [$this->baris(['mulai' => '19:00', 'selesai' => '00:00', 'lembur' => true])],
            105,
        );

        $this->assertSame(['19:00–21:00', '21:00–23:00', '23:00–00:00'], $this->jam($book->entries));

        $akhir = $book->entries[array_key_last($book->entries)];
        $this->assertSame('2026-09-23T00:00:00+0700', $akhir->toKimaiPayload(105)['end']);
        $this->assertSame('2026-09-22', $akhir->workDate->toDateString());
        $this->assertSame('Overtime', $akhir->sheet);
        $this->assertSame('Overtime', $akhir->toKimaiPayload(105)['tags']);
    }

    #[Test]
    public function beberapa_hari_diurutkan_menurut_waktu(): void
    {
        $book = (new ManualEntryBuilder)->build([
            $this->baris(['tanggal' => '2026-09-23', 'selesai' => '11:00']),
            $this->baris(['tanggal' => '2026-09-22', 'selesai' => '11:00']),
        ], 105);

        $this->assertSame('2026-09-22', $book->entries[0]->workDate->toDateString());
        $this->assertSame('2026-09-23', $book->entries[1]->workDate->toDateString());
    }

    #[Test]
    public function baris_yang_bertumpukan_di_tanggal_sama_ditolak(): void
    {
        try {
            (new ManualEntryBuilder)->build([
                $this->baris(['selesai' => '12:00']),
                $this->baris(['mulai' => '11:00', 'selesai' => '13:00']),
                // Tanggal lain dengan jam yang sama tidak bentrok.
                $this->baris(['tanggal' => '2026-09-23', 'selesai' => '12:00']),
            ], 105);
            $this->fail('Tumpang-tindih mestinya ditolak.');
        } catch (InvalidManualInput $e) {
            $this->assertCount(1, $e->problems);
            $this->assertStringContainsString('Baris 2 bertumpukan jamnya dengan baris 1', $e->problems[0]);
        }
    }

    #[Test]
    public function isian_yang_tidak_lengkap_disebut_per_baris(): void
    {
        try {
            (new ManualEntryBuilder)->build([
                $this->baris(['selesai' => '08:00']),
                $this->baris(['activity_id' => null, 'deskripsi' => ' ']),
            ], 105);
            $this->fail('Isian tidak lengkap mestinya ditolak.');
        } catch (InvalidManualInput $e) {
            $this->assertSame([
                'Baris 1: jam selesai harus setelah jam mulai.',
                'Baris 2: activity belum dipilih.',
                'Baris 2: deskripsi pekerjaan belum diisi.',
            ], $e->problems);
        }
    }

    #[Test]
    public function jumlah_entri_dibatasi_seperti_upload_workbook(): void
    {
        config(['kimai.upload_max_entries' => 4]);

        $this->expectException(InvalidManualInput::class);

        (new ManualEntryBuilder)->build([$this->baris()], 105);
    }

    #[Test]
    public function jumlah_potongan_untuk_ringkasan(): void
    {
        $this->assertSame(0, ManualEntryBuilder::pieces(0));
        $this->assertSame(1, ManualEntryBuilder::pieces(120));
        $this->assertSame(2, ManualEntryBuilder::pieces(121));
        $this->assertSame(5, ManualEntryBuilder::pieces(540));
    }
}
