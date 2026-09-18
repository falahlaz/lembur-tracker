<?php

namespace Tests\Feature\Filament;

use App\Domain\Timesheet\TimesheetWorkbookParser;
use App\Domain\Timesheet\WorkbookReader;
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
            ->call('kirim');

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
            ->call('kirim');

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
            ->call('kirim');

        Queue::assertNotPushed(PostTimesheetUpload::class);
        $this->assertSame(UploadStatus::Draft, $punyaOrangLain->refresh()->status);
    }

    #[Test]
    public function mengirim_tanpa_draf_memberi_tahu_bukan_diam(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)->call('kirim');

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

    #[Test]
    public function project_dipilih_lewat_nama_bukan_angka(): void
    {
        $this->actingAs($this->kimaiUser());

        $opsi = Livewire::test(UploadTimesheet::class)->instance()->opsiProject();

        $this->assertSame('C5385 - MyTelkomsel (Telkomsel)', $opsi[105] ?? null);
        $this->assertArrayHasKey(118, $opsi);
    }

    #[Test]
    public function halaman_tetap_terbuka_saat_daftar_project_gagal_dimuat(): void
    {
        // Pratinjau dan pengiriman masih berguna dengan id yang diketik manual;
        // halaman tidak boleh mati hanya karena daftarnya gagal.
        $this->kimaiGetStatus = 503;
        $this->actingAs($this->kimaiUser());

        $this->get(UploadTimesheet::getUrl())->assertOk();

        $page = Livewire::test(UploadTimesheet::class)->instance();

        $this->assertFalse($page->katalogTersedia());
        $this->assertNotNull($page->katalogError);
        // Tanpa cermin yang terisi, inilah cabang "dua-duanya kosong".
        $this->assertFalse($page->katalogDariLokal());
    }

    #[Test]
    public function daftar_project_jatuh_ke_data_lokal_saat_kimai_mati(): void
    {
        // Kalau admin sudah pernah menyinkronkan katalog, matinya Kimai tidak lagi
        // memaksa orang kembali mengetik id project dengan tangan.
        $this->seedKimaiCatalog();
        $this->kimaiGetStatus = 503;
        $this->actingAs($this->kimaiUser());

        $page = Livewire::test(UploadTimesheet::class)->instance();

        $this->assertTrue($page->katalogTersedia());
        $this->assertTrue($page->katalogDariLokal());
        $this->assertNotNull($page->katalogError);
        $this->assertSame('C5385 - MyTelkomsel (Telkomsel)', $page->opsiProject()[105]);
        // Waktunya ikut disebut, supaya "agak lama" bisa dinilai sendiri oleh pembacanya.
        $this->assertNotNull($page->terakhirKatalogDisinkronkanTeks());
        $this->assertStringContainsString('terakhir disinkronkan', $page->keteranganSinkronCermin());
    }

    #[Test]
    public function peringatan_data_lokal_menyebut_kapan_terakhir_disinkronkan(): void
    {
        $this->seedKimaiCatalog();
        $this->kimaiGetStatus = 503;
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->assertSee('Kimai sedang tidak terjangkau')
            ->assertSee('terakhir disinkronkan')
            // Bukan peringatan "ketik id manual": Select-nya justru masih jalan.
            ->assertDontSee('Project diisi dengan ID manual');
    }

    #[Test]
    public function project_dari_workbook_jadi_pilihan_awal(): void
    {
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse')
            ->assertSet('data.project_id', 105);
    }

    #[Test]
    public function mengirim_draf_yang_sudah_dibatalkan_memberi_tahu_bukan_diam(): void
    {
        // Menirukan tab kedua: UploadDrafter::draft() membatalkan SELURUH draf milik
        // orang yang sama, sementara tab pertama masih merender pratinjau lamanya
        // lengkap dengan tombol Kirim yang aktif.
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        $page = Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        TimesheetUpload::query()->update(['status' => UploadStatus::Cancelled->value]);

        $page->call('kirim')
            ->assertNotified('Draf ini sudah dibatalkan')
            // Pratinjau hantunya ikut dilepas, supaya tombolnya tidak tinggal di layar.
            ->assertSet('uploadId', null);

        Queue::assertNotPushed(PostTimesheetUpload::class);
    }

    #[Test]
    public function mengirim_draf_yang_sudah_terhapus_memberi_tahu_bukan_diam(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        $page = Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        TimesheetUpload::query()->delete();

        $page->call('kirim')
            ->assertNotified('Draf ini sudah tidak ada')
            ->assertSet('uploadId', null);

        Queue::assertNotPushed(PostTimesheetUpload::class);
    }

    #[Test]
    public function project_id_kosong_jatuh_ke_project_workbook_bukan_nol(): void
    {
        // Select yang dikosongkan mengirim string kosong, bukan null, dan (int) ''
        // = 0. Dengan `??` saja project 0 akan lolos ke Kimai dan membuat SETIAP
        // entri ditolak satu per satu.
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse')
            ->set('data.project_id', '')
            ->call('kirim');

        $this->assertSame(105, TimesheetUpload::query()->firstOrFail()->project_id);
    }

    #[Test]
    public function tanpa_project_sama_sekali_pengiriman_ditolak_dengan_suara(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        $page = Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        TimesheetUpload::query()->update(['project_id' => null]);

        $page->set('data.project_id', '')
            ->call('kirim')
            ->assertNotified('Project Kimai belum dipilih');

        Queue::assertNotPushed(PostTimesheetUpload::class);
        $this->assertSame(UploadStatus::Draft, TimesheetUpload::query()->firstOrFail()->status);
    }

    #[Test]
    public function up_10_upload_yang_nyangkut_dipulihkan_saat_halaman_dibuka_lagi(): void
    {
        // Job yang hilang dulu mengunci halaman ini selamanya: pratinjaunya lenyap
        // (mount hanya memulihkan draf) sementara sedangBerjalan() tetap true,
        // sehingga tombol Kirim mati permanen dan Batalkan tidak ikut dirender.
        $user = $this->kimaiUser();
        $this->actingAs($user);

        $page = Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        $upload = TimesheetUpload::query()->firstOrFail();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();
        $upload->created_at = now()->subMinutes(30);
        $upload->save();

        $segar = Livewire::test(UploadTimesheet::class);

        $segar->assertSet('uploadId', $upload->id);
        $this->assertFalse($segar->instance()->sedangBerjalan());
        $this->assertSame(UploadStatus::Failed, $upload->refresh()->status);
        // Tombolnya kembali — sebagai "Lanjutkan", karena entrinya masih utuh.
        $segar->assertSee('Lanjutkan 1 entri ke Kimai');

        unset($page);
    }

    #[Test]
    public function up_10_upload_yang_nyangkut_bisa_dibuang_dari_halaman(): void
    {
        $this->actingAs($this->kimaiUser());

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        $upload = TimesheetUpload::query()->firstOrFail();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();
        $upload->created_at = now()->subMinutes(30);
        $upload->save();

        Livewire::test(UploadTimesheet::class)
            ->call('batalkan')
            ->assertSet('uploadId', null);

        $this->assertSame(UploadStatus::Cancelled, $upload->refresh()->status);
    }

    #[Test]
    public function upload_yang_benar_benar_berjalan_tidak_bisa_dibuang(): void
    {
        $user = $this->kimaiUser();
        $this->actingAs($user);

        Livewire::test(UploadTimesheet::class)
            ->set('data.berkas', $this->berkas())
            ->call('analyse');

        $upload = TimesheetUpload::query()->firstOrFail();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        Livewire::test(UploadTimesheet::class)
            ->call('batalkan')
            ->assertNotified('Upload masih berjalan')
            ->assertSet('uploadId', $upload->id);

        $this->assertSame(UploadStatus::Queued, $upload->refresh()->status);
    }

    #[Test]
    public function template_yang_diunduh_bisa_dibaca_balik_oleh_parser(): void
    {
        // Template yang tidak bisa dibaca aplikasinya sendiri lebih buruk daripada
        // tidak ada template.
        $this->actingAs($this->kimaiUser());

        // Dipanggil langsung di instance-nya: lewat Livewire, unduhan dibungkus
        // effect dan byte-nya tidak bisa diambil balik.
        $response = Livewire::test(UploadTimesheet::class)->instance()->downloadTemplate();

        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $this->temps[] = $path;
        file_put_contents($path, $bytes);

        $reader = new WorkbookReader;

        $this->assertSame(['Daily', 'Overtime'], $reader->sheetNames($path));

        $book = (new TimesheetWorkbookParser)->parse(
            $reader->read($path, ['Daily', 'Overtime']),
            $reader->sheetNames($path),
        );

        $this->assertSame([], $book->issues);
        $this->assertCount(2, $book->entries, 'Satu sel contoh per sheet.');
        $this->assertSame('12_PROJECT_MEETING', $book->entries[0]->activityName);
    }
}
