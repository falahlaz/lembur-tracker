<?php

namespace Tests\Feature\Domain;

use App\Domain\Lembur\HistoricalImporter;
use App\Domain\Lembur\ImportedRow;
use App\Enums\BalanceStatus;
use App\Enums\OvertimeStatus;
use App\Models\LeaveBalance;
use App\Models\OvertimeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** OQ-4 — impor lembur historis. */
class HistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
        $this->importer = app(HistoricalImporter::class);
    }

    private function sheet(array ...$rows): array
    {
        return array_merge([HistoricalImporter::HEADERS], $rows);
    }

    #[Test]
    public function parse_menghitung_hak_tanpa_menulis_apa_pun(): void
    {
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['2026-03-05', '19:00', '23:30', 'Hotfix payment gateway', 'https://one.test/1', 'disetujui', ''],
        ));

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertTrue($row->isValid());
        $this->assertSame(270, $row->preview->effectiveMinutes);
        $this->assertSame(50_000, $row->preview->mealAmount);

        // Belum ada apa pun yang tersimpan.
        $this->assertSame(0, OvertimeRecord::query()->count());
    }

    #[Test]
    public function baris_bermasalah_dilaporkan_dengan_alasannya(): void
    {
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['', '19:00', '23:30', 'Deskripsi cukup panjang', 'https://one.test/1', '', ''],
            ['2026-03-05', '19:00', '23:30', 'pendek', 'https://one.test/2', '', ''],
            ['2026-03-06', '19:00', '23:30', 'Deskripsi cukup panjang', 'bukan-url', '', ''],
            ['2027-01-01', '19:00', '23:30', 'Deskripsi cukup panjang', 'https://one.test/3', '', ''],
        ));

        $this->assertStringContainsString('Tanggal kosong', $rows[0]->errors[0]);
        $this->assertStringContainsString('minimal 10 karakter', $rows[1]->errors[0]);
        $this->assertStringContainsString('URL evidence tidak valid', $rows[2]->errors[0]);
        $this->assertStringContainsString('masa depan', $rows[3]->errors[0]);
    }

    #[Test]
    public function duplikat_di_dalam_berkas_dan_terhadap_database_terdeteksi(): void
    {
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-10', '19:00', '23:30');

        $rows = $this->importer->parse($user, $this->sheet(
            ['2026-03-05', '19:00', '23:30', 'Deskripsi cukup panjang', 'https://one.test/1', '', ''],
            ['2026-03-05', '19:00', '22:00', 'Deskripsi cukup panjang', 'https://one.test/2', '', ''],
            ['2026-03-10', '19:00', '23:30', 'Deskripsi cukup panjang', 'https://one.test/3', '', ''],
        ));

        $this->assertTrue($rows[0]->isValid());
        $this->assertStringContainsString('Duplikat dengan baris 2', $rows[1]->errors[0]);
        $this->assertStringContainsString('Sudah ada lembur tercatat', $rows[2]->errors[0]);
    }

    #[Test]
    public function commit_menyimpan_hanya_baris_yang_lolos(): void
    {
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['2026-03-05', '19:00', '23:30', 'Deskripsi cukup panjang', 'https://one.test/1', '', ''],
            ['2026-03-06', '19:00', '23:30', 'pendek', 'https://one.test/2', '', ''],
        ));

        $result = $this->importer->commit($user, $rows);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, OvertimeRecord::query()->count());
        // Status kosong dianggap sudah disetujui.
        $this->assertSame(OvertimeStatus::Approved, OvertimeRecord::query()->sole()->status);
    }

    #[Test]
    public function saldo_lama_langsung_berstatus_hangus(): void
    {
        // Lembur Januari → hangus Februari, sudah lewat pada 20 Maret.
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['2026-01-10', '09:00', '19:00', 'Lembur historis Januari', 'https://one.test/1', '', ''],
        ));

        $this->assertTrue($rows->first()->alreadyExpired);

        $this->importer->commit($user, $rows);

        $balance = LeaveBalance::query()->sole();
        $this->assertSame('2026-02-10', $balance->expires_at->toDateString());
        // Tidak sempat tampil sebagai saldo aktif palsu.
        $this->assertSame(BalanceStatus::Expired, $balance->status);
    }

    #[Test]
    public function format_tanggal_dan_jam_yang_beragam_tetap_terbaca(): void
    {
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['15/01/2026', '19.00', '23.30', 'Format tanggal Indonesia', 'https://one.test/1', '', ''],
            ['2026-01-16', '7:05', '9:30', 'Jam satu digit', 'https://one.test/2', '', ''],
        ));

        $this->assertSame('2026-01-15', $rows[0]->date);
        $this->assertSame('19:00', $rows[0]->startTime);
        $this->assertSame('23:30', $rows[0]->endTime);

        $this->assertSame('07:05', $rows[1]->startTime);
        $this->assertSame('09:30', $rows[1]->endTime);
    }

    #[Test]
    public function lembur_lewat_tengah_malam_ikut_terhitung_benar(): void
    {
        $user = $this->employee();

        $rows = $this->importer->parse($user, $this->sheet(
            ['2026-03-05', '21:00', '02:00', 'Deploy rilis bulanan', 'https://one.test/1', '', ''],
        ));

        // BR-03 — 5 jam, diikatkan ke tanggal mulai.
        $this->assertSame(300, $rows->first()->preview->effectiveMinutes);
        $this->assertTrue($rows->first()->preview->crossesMidnight);
    }
}
