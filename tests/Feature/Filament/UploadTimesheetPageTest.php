<?php

namespace Tests\Feature\Filament;

use App\Enums\UploadStatus;
use App\Filament\Pages\UploadTimesheet;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadTimesheetPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-08-20');
        $this->baselineRule();
        $this->fakeKimai([]);

        // Saat test, Livewire memakai disk sementara sendiri ('tmp-for-tests')
        // yang tidak ada di config/filesystems.php.
        Storage::fake(FileUploadConfiguration::disk());
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function berkas(): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Daily');
        $sheet->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
        $sheet->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
        $sheet->setCellValue('B4', 46252);

        $rich = new RichText;
        $rich->createTextRun('Activity ID: 25')->getFont()->setBold(true);
        $rich->createText("\n\nDaily Meeting");
        $sheet->setCellValue('A5', '9 AM - 10 AM')->setCellValue('B5', $rich);

        $path = tempnam(sys_get_temp_dir(), 'page_').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
        $this->temps[] = $path;

        return $this->berkasPalsu($path, 'Timesheet Agustus.xlsx');
    }

    /**
     * Livewire mengubah set() atas berkas menjadi simulasi unggah penuh, dan jalur
     * itu menuntut Illuminate\Http\Testing\File — bukan UploadedFile mentah,
     * bukan pula TemporaryUploadedFile yang sudah jadi.
     */
    private function berkasPalsu(string $path, string $originalName): File
    {
        return UploadedFile::fake()->createWithContent($originalName, file_get_contents($path));
    }

    #[Test]
    public function karyawan_biasa_bisa_membuka_halaman_ini(): void
    {
        // Sengaja berbeda dari Import Historis yang admin-only: entri masuk Kimai
        // sebagai pemilik token, jadi ini memang pekerjaan masing-masing orang.
        $this->actingAs($this->kimaiUser());

        $this->assertTrue(UploadTimesheet::canAccess());
        $this->get(UploadTimesheet::getUrl())->assertOk();
    }

    #[Test]
    public function halaman_tertutup_untuk_yang_belum_menghubungkan_kimai(): void
    {
        $this->actingAs($this->employee());

        $this->assertFalse(UploadTimesheet::canAccess());
    }

    #[Test]
    public function memeriksa_berkas_membuat_draf_tanpa_mengirim_apa_pun(): void
    {
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->set('data.project_id', 105)
            ->call('analyse')
            ->assertHasNoErrors();

        $upload = TimesheetUpload::query()->firstOrFail();

        $this->assertSame(UploadStatus::Draft, $upload->status);
        $this->assertSame('Timesheet Agustus.xlsx', $upload->original_filename);
        $this->assertSame(1, $upload->count_parsed);
        $this->assertSame([], $this->kimaiPostBodies());
    }

    #[Test]
    public function mengirim_hanya_menaruh_job_ke_queue(): void
    {
        // F-12 punya semangat yang sama: request web tidak pernah menunggu Kimai.
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->set('data.project_id', 105)
            ->call('analyse')
            ->call('commit');

        Queue::assertPushed(PostTimesheetUpload::class);
        $this->assertSame(UploadStatus::Queued, TimesheetUpload::query()->firstOrFail()->status);
    }

    #[Test]
    public function project_id_terisi_dari_workbook_dan_bisa_diubah(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse')
            ->assertSet('data.project_id', 105)
            ->set('data.project_id', 118)
            ->call('commit');

        $this->assertSame(118, TimesheetUpload::query()->firstOrFail()->project_id);
    }

    #[Test]
    public function draf_milik_orang_lain_tidak_bisa_dikirim(): void
    {
        Queue::fake();

        $orangLain = $this->kimaiUser();
        $punyaOrangLain = TimesheetUpload::create([
            'user_id' => $orangLain->id,
            'original_filename' => 'punya-orang-lain.xlsx',
            'project_id' => 105,
            'status' => UploadStatus::Draft->value,
        ]);

        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('uploadId', $punyaOrangLain->id)
            ->call('commit');

        Queue::assertNotPushed(PostTimesheetUpload::class);
        $this->assertSame(UploadStatus::Draft, $punyaOrangLain->refresh()->status);
    }

    #[Test]
    public function commit_tanpa_draf_tidak_melakukan_apa_apa(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)->call('commit');

        Queue::assertNotPushed(PostTimesheetUpload::class);
    }

    #[Test]
    public function berkas_yang_tidak_bisa_dibaca_dilaporkan_bukan_meledak(): void
    {
        $this->actingAs($this->kimaiUser());

        $path = tempnam(sys_get_temp_dir(), 'rusak_').'.xlsx';
        file_put_contents($path, 'jelas bukan workbook');
        $this->temps[] = $path;

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkasPalsu($path, 'rusak.xlsx'))
            ->call('analyse')
            ->assertHasNoErrors();

        $this->assertSame(0, TimesheetUpload::query()->count());
    }

    #[Test]
    public function draf_yang_ditinggalkan_dipulihkan_saat_halaman_dibuka_lagi(): void
    {
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        $upload = TimesheetUpload::query()->firstOrFail();

        // Pratinjaunya sudah menghabiskan satu perjalanan ke Kimai; menutup tab
        // tidak boleh membuangnya.
        Livewire::test(UploadTimesheet::class)->assertSet('uploadId', $upload->id);
    }

    #[Test]
    public function membatalkan_draf_mengosongkan_pratinjau(): void
    {
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse')
            ->call('batalkan')
            ->assertSet('uploadId', null);

        $this->assertSame(UploadStatus::Cancelled, TimesheetUpload::query()->firstOrFail()->status);
    }
}
