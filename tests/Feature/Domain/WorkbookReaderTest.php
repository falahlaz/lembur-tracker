<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\TimesheetWorkbookParser;
use App\Domain\Timesheet\WorkbookReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Satu-satunya berkas uji yang menyentuh .xlsx sungguhan.
 *
 * Workbook-nya DIBANGUN di sini, bukan di-commit sebagai fixture biner. Alasannya
 * bukan sekadar ukuran repo: sel timesheet adalah rich text ber-newline, dan yang
 * perlu dibuktikan justru bahwa PhpSpreadsheet meratakannya jadi string biasa.
 * Fixture biner membuktikan itu satu kali lalu membeku; builder ini ikut diperiksa
 * ulang setiap kali versi PhpSpreadsheet naik — dan bisa dibaca reviewer tanpa Excel.
 */
class WorkbookReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** Rich text, persis bentuk yang ditulis template: "Activity ID: N" tebal lalu badan deskripsi. */
    private function sel(int $activityId, string $deskripsi): RichText
    {
        $rich = new RichText;
        $rich->createTextRun("Activity ID: {$activityId}")->getFont()->setBold(true);
        $rich->createText("\n\n".$deskripsi);

        return $rich;
    }

    /** Membangun workbook bergaya template dan mengembalikan path-nya. */
    private function workbook(bool $denganOvertime = true): string
    {
        $book = new Spreadsheet;

        $daily = $book->getActiveSheet();
        $daily->setTitle('Daily');
        $daily->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
        $daily->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
        $daily->setCellValue('B4', 46252)->setCellValue('C4', 46253);   // 18 & 19 Agustus 2026
        $daily->setCellValue('A5', '9 AM - 10 AM');
        $daily->setCellValue('B5', $this->sel(25, 'Daily Meeting'));
        $daily->setCellValue('C5', $this->sel(25, 'Daily Meeting'));
        $daily->setCellValue('A6', '10 AM - 12 AM');
        $daily->setCellValue('B6', $this->sel(8, "Sprint 8 - MTA-1867\n1. Review AI generated code"));

        if ($denganOvertime) {
            $ot = $book->createSheet();
            $ot->setTitle('Overtime');
            $ot->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
            $ot->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
            $ot->setCellValue('B4', 46252);
            // Dua belas baris slot — sengaja sampai baris 16, jangkauan yang tidak
            // pernah dilihat importer lama.
            foreach ([
                '12 AM - 2 AM', '2 AM - 4 AM', '4 AM - 5 AM', '5 AM - 6 AM',
                '9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM',
                '5 PM - 6 PM', '6 PM - 8 PM', '8 PM - 10 PM', '10 PM - 12 PM',
            ] as $i => $label) {
                $ot->setCellValue('A'.(5 + $i), $label);
            }

            $ot->setCellValue('B16', $this->sel(9, 'Deploy rilis 9.4.0'));   // '10 PM - 12 PM'
        }

        $path = tempnam(sys_get_temp_dir(), 'timesheet_').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();

        $this->temps[] = $path;

        return $path;
    }

    #[Test]
    public function nama_sheet_terbaca_apa_adanya(): void
    {
        $this->assertSame(['Daily', 'Overtime'], (new WorkbookReader)->sheetNames($this->workbook()));
    }

    #[Test]
    public function rich_text_diratakan_jadi_satu_string_dengan_newline_utuh(): void
    {
        // Kalau PhpSpreadsheet suatu saat mengembalikan objek RichText alih-alih
        // string, di sinilah ketahuannya — bukan di produksi.
        $grid = (new WorkbookReader)->read($this->workbook(), ['Daily', 'Overtime']);

        $nilai = $grid['Daily'][6]['B'];

        $this->assertIsString($nilai);
        $this->assertSame("Activity ID: 8\n\nSprint 8 - MTA-1867\n1. Review AI generated code", $nilai);
    }

    #[Test]
    public function tanggal_dikembalikan_sebagai_serial_bukan_teks_terformat(): void
    {
        $grid = (new WorkbookReader)->read($this->workbook(), ['Daily', 'Overtime']);

        $this->assertEqualsWithDelta(46252, $grid['Daily'][4]['B'], 0.001);
    }

    #[Test]
    public function baris_dan_kolom_memakai_penomoran_asli_workbook(): void
    {
        // Dibutuhkan supaya pesan kesalahan bisa menunjuk sel yang benar.
        $grid = (new WorkbookReader)->read($this->workbook(), ['Daily', 'Overtime']);

        $this->assertSame('Customer ID', $grid['Daily'][1]['A']);
        $this->assertSame('9 AM - 10 AM', $grid['Daily'][5]['A']);
        $this->assertSame('10 PM - 12 PM', $grid['Overtime'][16]['A']);
    }

    #[Test]
    public function workbook_sungguhan_terbaca_utuh_lewat_parser(): void
    {
        // Jalur lengkap: berkas → reader → parser. Ini yang membuktikan kedua
        // bagian benar-benar nyambung, bukan hanya benar sendiri-sendiri.
        $path = $this->workbook();
        $reader = new WorkbookReader;

        $book = (new TimesheetWorkbookParser)->parse(
            $reader->read($path, ['Daily', 'Overtime']),
            $reader->sheetNames($path),
        );

        $this->assertSame(105, $book->projectId);
        $this->assertSame(112, $book->customerId);
        $this->assertCount(3, $book->forSheet('Daily'));
        $this->assertCount(1, $book->forSheet('Overtime'));
        $this->assertSame([], $book->issues);

        $ot = $book->forSheet('Overtime')[0];
        $this->assertSame('2026-08-18 22:00:00', $ot->beginAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-19 00:00:00', $ot->endAt->format('Y-m-d H:i:s'));
        $this->assertSame('Overtime', $ot->tag);
    }

    #[Test]
    public function sheet_overtime_yang_hilang_dilaporkan_dan_daily_tetap_terbaca(): void
    {
        // Sengaja TIDAK memakai SkipsUnknownSheets milik maatwebsite, yang akan
        // membuang sheet hilang tanpa suara.
        $path = $this->workbook(denganOvertime: false);
        $reader = new WorkbookReader;

        $book = (new TimesheetWorkbookParser)->parse(
            $reader->read($path, ['Daily', 'Overtime']),
            $reader->sheetNames($path),
        );

        $this->assertCount(3, $book->forSheet('Daily'));
        $this->assertCount(1, $book->issues);
        $this->assertStringContainsString("Sheet 'Overtime' tidak ditemukan", $book->issues[0]);
    }

    #[Test]
    public function berkas_yang_bukan_excel_ditolak_dengan_pesan_yang_bisa_dibaca(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bukan_').'.xlsx';
        file_put_contents($path, 'ini jelas bukan workbook');
        $this->temps[] = $path;

        $this->expectException(InvalidWorkbook::class);
        (new WorkbookReader)->read($path, ['Daily', 'Overtime']);
    }

    #[Test]
    public function workbook_tanpa_satu_pun_sheet_yang_dicari_ditolak(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Rekap');
        $path = tempnam(sys_get_temp_dir(), 'kosong_').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
        $this->temps[] = $path;

        $this->expectException(InvalidWorkbook::class);
        $this->expectExceptionMessageMatches('/Rekap/');
        (new WorkbookReader)->read($path, ['Daily', 'Overtime']);
    }
}
