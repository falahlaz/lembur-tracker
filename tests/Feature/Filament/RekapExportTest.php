<?php

namespace Tests\Feature\Filament;

use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Exports\RekapLemburExport;
use App\Filament\Pages\Rekap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-08 — export Excel tiga sheet. */
class RekapExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function halaman_rekap_terbuka(): void
    {
        $this->actingAs($this->employee());

        $this->get(Rekap::getUrl())
            ->assertOk()
            ->assertSee('Rekap & Export')
            ->assertSee('Lembur, Klaim, dan Ringkasan')
            ->assertSee('bukan perhitungan payroll resmi');
    }

    #[Test]
    public function nama_berkas_mengikuti_pola_yang_ditentukan(): void
    {
        $user = $this->employee();
        $user->update(['name' => 'Falah Lazuardi']);

        $export = new RekapLemburExport(
            $user,
            Date::parse('2026-03-19'),
            Date::parse('2026-04-18'),
            'April 2026',
        );

        $this->assertSame('Rekap_Lembur_FalahLazuardi_April2026.xlsx', $export->fileName());
    }

    #[Test]
    public function workbook_benar_benar_bisa_ditulis_dan_dibaca_ulang(): void
    {
        // Menulis dengan writer sungguhan, bukan fake — inilah yang menangkap
        // nilai sel yang tidak bisa dirender oleh PhpSpreadsheet.
        $user = $this->employee();
        $this->logOvertime($user, '2026-03-19', '19:00', '23:30');
        $this->submitClaim($user, '2026-04-01', ClaimType::LateArrival);

        $export = new RekapLemburExport(
            $user,
            Date::parse('2026-03-19'),
            Date::parse('2026-04-18'),
            'April 2026',
        );

        Storage::fake('local');

        Excel::store($export, $export->fileName(), 'local');
        Storage::disk('local')->assertExists($export->fileName());

        // Dibaca ulang lewat PhpSpreadsheet: membuktikan berkasnya benar-benar
        // valid, bukan sekadar berhasil ditulis.
        $book = IOFactory::load(Storage::disk('local')->path($export->fileName()));

        $this->assertSame(3, $book->getSheetCount());
        $this->assertSame(['Lembur', 'Klaim', 'Ringkasan'], $book->getSheetNames());

        $this->assertSame('Tanggal', $book->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Dibulatkan', $book->getSheet(0)->getCell('F1')->getValue());
        $this->assertSame('19 Maret 2026', $book->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame('Tanggal cuti', $book->getSheet(1)->getCell('A1')->getValue());
        $this->assertSame('Rincian', $book->getSheet(2)->getCell('A1')->getValue());

    }

    #[Test]
    public function workbook_berisi_tiga_sheet_dengan_kolom_pembulatan(): void
    {
        $user = $this->employee(rounding: true);
        $this->logOvertime($user, '2026-03-19', '19:00', '22:40');   // 3j40m → dibulatkan 4 jam
        $this->submitClaim($user, '2026-04-01', ClaimType::LateArrival);

        $export = new RekapLemburExport(
            $user,
            \Illuminate\Support\Facades\Date::parse('2026-03-19'),
            \Illuminate\Support\Facades\Date::parse('2026-04-18'),
            'April 2026',
        );

        $sheets = $export->sheets();
        $this->assertCount(3, $sheets);
        $this->assertSame(['Lembur', 'Klaim', 'Ringkasan'], array_map(fn ($s) => $s->title(), $sheets));

        // BR-04 — mentah, efektif, dan flag pembulatan semuanya ikut terekspor.
        $lembur = $sheets[0]->collection()->first();
        $this->assertSame(220, $lembur[3]);      // durasi mentah
        $this->assertSame(240, $lembur[4]);      // durasi efektif
        $this->assertSame('Ya', $lembur[5]);     // dibulatkan

        // Klaim mencantumkan asal saldonya (G-7).
        $klaim = $sheets[1]->collection()->first();
        $this->assertStringContainsString('19 Mar', $klaim[8]);

        $ringkasan = $sheets[2]->collection();
        $this->assertSame(['Karyawan', $user->name], $ringkasan[0]);
        $this->assertContains(['Pembulatan durasi', 'Nyala'], $ringkasan->all());
    }
}
