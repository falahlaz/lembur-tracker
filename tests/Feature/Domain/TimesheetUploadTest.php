<?php

namespace Tests\Feature\Domain;

use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use App\Domain\Timesheet\KimaiCatalog;
use App\Domain\Timesheet\UploadDrafter;
use App\Domain\Timesheet\UploadPoster;
use App\Domain\Timesheet\UploadRecovery;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Jobs\SyncKimaiTimesheets;
use App\Models\TimesheetUpload;
use App\Models\TimesheetUploadEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Jalur lengkap: berkas → draf → kirim ke Kimai, beserta seluruh pagarnya. */
class TimesheetUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<int, string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-08-20');
        $this->baselineRule();
        $this->user = $this->kimaiUser();
        $this->fakeKimai([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function sel(int $activityId, string $deskripsi): RichText
    {
        $rich = new RichText;
        $rich->createTextRun("Activity ID: {$activityId}")->getFont()->setBold(true);
        $rich->createText("\n\n".$deskripsi);

        return $rich;
    }

    /**
     * Workbook kecil bergaya template: satu tanggal (18 Agustus 2026), dua entri
     * Daily dan satu entri Overtime.
     */
    private function workbook(): string
    {
        $book = new Spreadsheet;

        $daily = $book->getActiveSheet();
        $daily->setTitle('Daily');
        $daily->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
        $daily->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
        $daily->setCellValue('B4', 46252);
        $daily->setCellValue('A5', '9 AM - 10 AM')->setCellValue('B5', $this->sel(25, 'Daily Meeting'));
        $daily->setCellValue('A6', '10 AM - 12 AM')->setCellValue('B6', $this->sel(8, 'Sprint 8 - MTA-1867'));

        $ot = $book->createSheet();
        $ot->setTitle('Overtime');
        $ot->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
        $ot->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
        $ot->setCellValue('B4', 46252);
        foreach ([
            '12 AM - 2 AM', '2 AM - 4 AM', '4 AM - 5 AM', '5 AM - 6 AM',
            '9 AM - 10 AM', '10 AM - 12 AM', '1 PM - 3 PM', '3 PM - 5 PM',
            '5 PM - 6 PM', '6 PM - 8 PM', '8 PM - 10 PM', '10 PM - 12 PM',
        ] as $i => $label) {
            $ot->setCellValue('A'.(5 + $i), $label);
        }
        $ot->setCellValue('B14', $this->sel(9, 'Deploy rilis 9.4.0'));   // '6 PM - 8 PM'

        $path = tempnam(sys_get_temp_dir(), 'upload_').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
        $this->temps[] = $path;

        return $path;
    }

    /** Workbook format baru: sel memakai NAMA activity, bukan id. */
    private function workbookBernama(string $namaActivity = '31_DEV_FEATURE'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Daily');
        $sheet->setCellValue('A1', 'Customer ID')->setCellValue('B1', 112);
        $sheet->setCellValue('A2', 'Project ID')->setCellValue('B2', 105);
        $sheet->setCellValue('B4', 46252);

        $rich = new RichText;
        $rich->createTextRun("Activity: {$namaActivity}")->getFont()->setBold(true);
        $rich->createText("\n\nSprint 8 - MTA-1867");
        $sheet->setCellValue('A5', '9 AM - 10 AM')->setCellValue('B5', $rich);

        $path = tempnam(sys_get_temp_dir(), 'named_').'.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
        $this->temps[] = $path;

        return $path;
    }

    private function drafter(): UploadDrafter
    {
        return app(UploadDrafter::class);
    }

    private function draft(): TimesheetUpload
    {
        return $this->drafter()->draft($this->user, $this->workbook(), 'Timesheet Agustus.xlsx');
    }

    private function kirim(TimesheetUpload $upload): TimesheetUpload
    {
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        return app(UploadPoster::class)->post($upload->refresh());
    }

    #[Test]
    public function draf_membaca_berkas_tanpa_mengirim_apa_pun(): void
    {
        $upload = $this->draft();

        $this->assertSame(UploadStatus::Draft, $upload->status);
        $this->assertSame(3, $upload->count_parsed);
        $this->assertSame(105, $upload->project_id);
        $this->assertSame(112, $upload->customer_id);
        $this->assertTrue($upload->duplicates_checked);
        $this->assertSame([], $this->kimaiPostBodies(), 'Langkah pratinjau tidak boleh POST apa pun.');
    }

    #[Test]
    public function seluruh_entri_pending_dikirim_dan_menyimpan_id_kimai(): void
    {
        $upload = $this->kirim($this->draft());

        $this->assertSame(UploadStatus::Success, $upload->status);
        $this->assertSame(3, $upload->count_posted);
        $this->assertCount(3, $this->kimaiPostBodies());

        $this->assertSame(
            3,
            $upload->entries()->where('status', UploadEntryStatus::Posted->value)->whereNotNull('kimai_timesheet_id')->count(),
        );
    }

    #[Test]
    public function body_post_hanya_memuat_field_yang_diizinkan(): void
    {
        // Ketiga aturan ini ditemukan dengan cara yang mahal; test inilah kontraknya.
        $this->kirim($this->draft());

        $bodies = $this->kimaiPostBodies();

        $daily = $bodies[0];
        $this->assertSame(['begin', 'end', 'project', 'activity', 'description'], array_keys($daily));
        $this->assertArrayNotHasKey('billable', $daily, '"This form should not contain extra fields"');
        $this->assertArrayNotHasKey('tags', $daily, 'tags kosong ditolak "This value is not valid."');

        $overtime = collect($bodies)->firstWhere('activity', 9);
        $this->assertSame('Overtime', $overtime['tags']);
        $this->assertIsString($overtime['tags'], 'tags harus STRING dipisah koma, bukan array.');
    }

    #[Test]
    public function waktu_dikirim_dengan_offset_tanpa_titik_dua(): void
    {
        $this->kirim($this->draft());

        $daily = $this->kimaiPostBodies()[0];

        $this->assertSame('2026-08-18T09:00:00+0700', $daily['begin']);
        $this->assertSame('2026-08-18T10:00:00+0700', $daily['end']);
    }

    #[Test]
    public function entri_yang_sudah_ada_di_kimai_dilewati_dan_tidak_pernah_dikirim(): void
    {
        $this->fakeKimai([$this->kimaiSlot(500, '2026-08-18', '09:00', '10:00')]);

        $upload = $this->draft();

        $dilewati = $upload->entries()->where('status', UploadEntryStatus::Skipped->value)->get();
        $this->assertCount(1, $dilewati);
        $this->assertStringContainsString('#500', $dilewati->first()->skip_reason);
        $this->assertFalse($dilewati->first()->overridable);

        $this->kirim($upload);

        $this->assertCount(2, $this->kimaiPostBodies(), 'Slot yang sudah terisi tidak boleh dikirim ulang.');
    }

    #[Test]
    public function bentrok_sebagian_ditandai_boleh_ditimpa(): void
    {
        $this->fakeKimai([$this->kimaiSlot(501, '2026-08-18', '09:30', '10:30')]);

        $entry = $this->draft()->entries()->where('status', UploadEntryStatus::Skipped->value)->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->overridable, 'Bentrok sebagian bisa saja disengaja.');
        $this->assertStringContainsString('Bentrok sebagian', $entry->skip_reason);
    }

    #[Test]
    public function entri_daily_tanpa_tag_pun_ikut_terdeteksi_sebagai_slot_terpakai(): void
    {
        // Justru inilah alasan pemeriksaan duplikat tidak boleh memakai filter tag
        // milik sync: entri Daily tidak bertag, tetapi tetap menempati jamnya.
        $this->fakeKimai([$this->kimaiSlot(502, '2026-08-18', '09:00', '10:00', ['tags' => []])]);

        $this->assertSame(
            1,
            $this->draft()->entries()->where('status', UploadEntryStatus::Skipped->value)->count(),
        );
    }

    #[Test]
    public function upload_kedua_atas_berkas_yang_sama_tidak_mengirim_apa_pun(): void
    {
        // Pembuktian utama anti-duplikat, dan persis kelemahan importer lama.
        $this->kirim($this->draft());
        $this->assertCount(3, $this->kimaiPostBodies());

        $this->fakeKimai([
            $this->kimaiSlot(601, '2026-08-18', '09:00', '10:00'),
            $this->kimaiSlot(602, '2026-08-18', '10:00', '12:00'),
            $this->kimaiSlot(603, '2026-08-18', '18:00', '20:00'),
        ]);

        $kedua = $this->kirim($this->draft());

        $this->assertSame(3, $kedua->count_skipped);
        $this->assertSame(0, $kedua->count_posted);
        $this->assertCount(3, $this->kimaiPostBodies(), 'Tidak boleh ada POST tambahan.');
    }

    #[Test]
    public function slot_yang_sudah_pernah_diupload_dilewati_meski_kimai_tak_terjangkau(): void
    {
        $this->kirim($this->draft());

        // Kimai diam-diam tidak bisa dihubungi saat pratinjau kedua.
        $this->kimaiGetStatus = 503;

        $upload = $this->draft();

        $this->assertFalse($upload->duplicates_checked, 'Pemeriksaan gagal tidak boleh terlihat lulus.');
        $this->assertSame(3, $upload->count_skipped, 'Lapis lokal tetap menangkapnya.');
    }

    #[Test]
    public function satu_entri_ditolak_empat_ratus_sisanya_tetap_masuk(): void
    {
        $this->failKimaiPostAt(2, 400, ['message' => 'This form should not contain extra fields']);

        $upload = $this->kirim($this->draft());

        $this->assertSame(UploadStatus::Partial, $upload->status);
        $this->assertSame(2, $upload->count_posted);
        $this->assertSame(1, $upload->count_failed);
        $this->assertCount(3, $this->kimaiPostBodies(), 'Entri setelahnya tetap dicoba.');

        $gagal = $upload->entries()->where('status', UploadEntryStatus::Failed->value)->first();
        $this->assertStringContainsString('This form should not contain extra fields', $gagal->skip_reason);
    }

    #[Test]
    public function token_ditolak_menghentikan_seluruh_upload(): void
    {
        $this->failKimaiPostAt(2, 401);

        $upload = $this->kirim($this->draft());

        $this->assertSame(UploadStatus::Failed, $upload->status);
        $this->assertCount(2, $this->kimaiPostBodies(), 'Tidak ada permintaan setelah 401.');
        $this->assertSame(1, $upload->count_posted);

        $this->user->refresh();
        $this->assertNull($this->user->kimai_token_valid_at, 'SY-22 — token ditandai tidak berlaku.');
        $this->assertFalse($this->user->kimai_auto_sync);

        $this->assertSame(2, $upload->entries()->where('status', UploadEntryStatus::Pending->value)->count());
    }

    #[Test]
    public function kimai_mati_menyisakan_entri_untuk_dilanjutkan(): void
    {
        $this->failKimaiPostAt(2, 503);

        $upload = $this->kirim($this->draft());

        $this->assertSame(UploadStatus::Failed, $upload->status);
        $this->assertTrue($upload->hasResumableEntries());
        $this->assertSame(1, $upload->count_posted);
    }

    #[Test]
    public function melanjutkan_upload_tidak_pernah_mengirim_ulang_yang_sudah_masuk(): void
    {
        // Test paling penting di berkas ini. Tanpa jaminan ini, satu 5xx di tengah
        // periode berubah jadi puluhan entri ganda yang harus dihapus manual.
        $this->failKimaiPostAt(2, 503);
        $upload = $this->kirim($this->draft());

        $this->assertSame(1, $upload->count_posted);

        $this->kimaiPostHandler = null;
        $lanjut = $this->kirim($upload);

        $this->assertSame(UploadStatus::Success, $lanjut->status);
        $this->assertSame(3, $lanjut->count_posted);

        // Empat permintaan total: satu sukses, satu gagal 503, lalu dua sisanya.
        $this->assertCount(4, $this->kimaiPostBodies());

        $ids = $lanjut->entries()->whereNotNull('kimai_timesheet_id')->pluck('kimai_timesheet_id')->all();
        $this->assertCount(3, $ids);
        $this->assertSame($ids, array_unique($ids), 'Tidak boleh ada entri yang terkirim dua kali.');
    }

    #[Test]
    public function sync_dipicu_setelah_entri_overtime_berhasil_terkirim(): void
    {
        Queue::fake();

        $this->kirim($this->draft());

        Queue::assertPushed(SyncKimaiTimesheets::class);
    }

    #[Test]
    public function upload_tanpa_entri_overtime_tidak_memicu_sync(): void
    {
        // Sync hanya menarik yang bertag Overtime, jadi upload Daily saja cuma
        // menghasilkan run kosong yang membingungkan di Riwayat Sync.
        Queue::fake();

        $upload = $this->draft();
        $upload->entries()->whereNotNull('tag')->update(['status' => UploadEntryStatus::Skipped->value]);

        $this->kirim($upload);

        Queue::assertNotPushed(SyncKimaiTimesheets::class);
    }

    #[Test]
    public function draf_baru_membatalkan_draf_lama_milik_orang_yang_sama(): void
    {
        $lama = $this->draft();
        $baru = $this->draft();

        $this->assertSame(UploadStatus::Cancelled, $lama->refresh()->status);
        $this->assertSame(UploadStatus::Draft, $baru->status);
    }

    #[Test]
    public function berkas_yang_melebihi_batas_maksimum_ditolak(): void
    {
        config(['kimai.upload_max_entries' => 2]);

        $this->expectException(InvalidWorkbook::class);
        $this->expectExceptionMessageMatches('/melebihi batas 2/');

        $this->draft();
    }

    #[Test]
    public function entri_bentrok_yang_boleh_ditimpa_bisa_dikembalikan_ke_antrean(): void
    {
        $this->fakeKimai([$this->kimaiSlot(700, '2026-08-18', '09:30', '09:45')]);

        $upload = $this->draft();
        $this->assertSame(1, $upload->count_skipped);

        $this->assertSame(1, $this->drafter()->includeOverridable($upload));
        $this->assertSame(0, $upload->refresh()->count_skipped);
    }

    #[Test]
    public function upload_milik_orang_lain_tidak_pernah_dikerjakan_job(): void
    {
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        $orangLain = $this->kimaiUser();

        (new PostTimesheetUpload($orangLain, $upload))->handle(app(UploadPoster::class));

        $this->assertSame([], $this->kimaiPostBodies());
        $this->assertSame(UploadStatus::Queued, $upload->refresh()->status);
    }

    #[Test]
    public function riwayat_upload_dipangkas_di_angka_maksimum(): void
    {
        foreach (range(1, TimesheetUpload::KEEP_PER_USER + 3) as $i) {
            TimesheetUpload::create([
                'user_id' => $this->user->id,
                'original_filename' => "berkas-{$i}.xlsx",
                'project_id' => 105,
                'status' => UploadStatus::Success->value,
            ]);
        }

        TimesheetUpload::pruneFor($this->user->id);

        $this->assertSame(
            TimesheetUpload::KEEP_PER_USER,
            TimesheetUpload::query()->where('user_id', $this->user->id)->count(),
        );
    }

    #[Test]
    public function entri_disimpan_dengan_tanggal_kerja_dan_jam_yang_benar(): void
    {
        $upload = $this->draft();

        $ot = $upload->entries()->where('sheet', 'Overtime')->first();

        $this->assertSame('2026-08-18', $ot->work_date->toDateString());
        $this->assertSame(120, $ot->duration_minutes);
        $this->assertSame('Overtime', $ot->tag);
        $this->assertSame('Overtime!B14', $ot->cell_ref);
        $this->assertSame('6 PM - 8 PM', $ot->slot_label);

        $daily = $upload->entries()->where('sheet', 'Daily')->orderBy('begin_at')->first();
        $this->assertNull($daily->tag, 'Entri Daily tidak bertag.');
        $this->assertSame('Daily Meeting', $daily->description);
    }

    #[Test]
    public function entri_disimpan_berpasangan_dengan_pemiliknya(): void
    {
        $upload = $this->draft();

        $this->assertSame(
            3,
            TimesheetUploadEntry::query()->where('user_id', $this->user->id)->count(),
        );
        $this->assertSame($this->user->id, $upload->user_id);
    }

    #[Test]
    public function nama_activity_diresolusi_jadi_id_kimai(): void
    {
        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');

        $entry = $upload->entries()->firstOrFail();

        $this->assertSame('31_DEV_FEATURE', $entry->activity_name);
        $this->assertSame(8, $entry->activity_id);
        $this->assertSame(UploadEntryStatus::Pending, $entry->status);
    }

    #[Test]
    public function nama_activity_global_ikut_ketemu(): void
    {
        // Activity global diambil lewat permintaan terpisah lalu digabung; kalau
        // penggabungannya salah, seluruh activity global tidak akan pernah ketemu.
        $upload = $this->drafter()->draft($this->user, $this->workbookBernama('12_PROJECT_MEETING'), 'Timesheet.xlsx');

        $this->assertSame(25, $upload->entries()->firstOrFail()->activity_id);
    }

    #[Test]
    public function nama_activity_tak_dikenal_ditandai_dan_tidak_pernah_dikirim(): void
    {
        $upload = $this->drafter()->draft($this->user, $this->workbookBernama('31_DEV_FEATUR'), 'Timesheet.xlsx');

        $entry = $upload->entries()->firstOrFail();

        $this->assertSame(UploadEntryStatus::Skipped, $entry->status);
        $this->assertNull($entry->activity_id);
        $this->assertStringContainsString('31_DEV_FEATUR', $entry->skip_reason);

        $this->kirim($upload);

        $this->assertSame([], $this->kimaiPostBodies());
    }

    #[Test]
    public function daftar_activity_yang_gagal_diambil_dilaporkan_jujur(): void
    {
        $this->kimaiGetStatus = 503;

        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');

        $entry = $upload->entries()->firstOrFail();

        $this->assertSame(UploadEntryStatus::Skipped, $entry->status);
        $this->assertStringContainsString('Daftar activity tidak bisa diambil', $entry->skip_reason);
        $this->assertNotEmpty($upload->issues);
    }

    #[Test]
    public function nama_activity_dicocokkan_dari_cermin_saat_kimai_mati(): void
    {
        // Kalau admin sudah pernah menyinkronkan katalog, matinya Kimai tidak lagi
        // menjatuhkan seluruh entri bernama.
        $this->seedKimaiCatalog();
        $this->kimaiGetStatus = 503;

        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');

        $entry = $upload->entries()->firstOrFail();

        $this->assertSame(8, $entry->activity_id);
        $this->assertSame(UploadEntryStatus::Pending, $entry->status);
    }

    #[Test]
    public function pratinjau_menyebut_kalau_activity_dicocokkan_dari_data_lokal(): void
    {
        // Cermin bisa tertinggal dari Kimai; pratinjau tidak boleh membuat unggahan
        // terlihat lebih pasti daripada kenyataannya.
        $this->seedKimaiCatalog();
        $this->kimaiGetStatus = 503;

        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');

        $this->assertStringContainsString(
            'dicocokkan dari data lokal',
            implode(' ', $upload->issues),
        );
    }

    #[Test]
    public function ganti_project_meresolusi_ulang_nama_tanpa_baca_ulang_berkas(): void
    {
        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');
        $this->assertSame(8, $upload->entries()->firstOrFail()->activity_id);

        // Di project 118, nama yang sama menunjuk id yang berbeda.
        $this->kimaiActivities = [
            ['id' => 77, 'name' => '31_DEV_FEATURE', 'project' => 118],
        ];

        $hasil = $this->drafter()->reresolveActivities($upload, 118);

        $this->assertSame(['diresolusi' => 1, 'gagal' => 0], $hasil);
        $this->assertSame(77, $upload->entries()->firstOrFail()->activity_id);
        $this->assertSame(118, $upload->refresh()->project_id);
    }

    #[Test]
    public function ganti_project_ke_yang_tidak_punya_activity_itu_menandai_entrinya(): void
    {
        $upload = $this->drafter()->draft($this->user, $this->workbookBernama(), 'Timesheet.xlsx');

        $this->kimaiActivities = [
            ['id' => 90, 'name' => '99_LAIN_LAIN', 'project' => 118],
        ];

        $hasil = $this->drafter()->reresolveActivities($upload, 118);

        $this->assertSame(1, $hasil['gagal']);

        $entry = $upload->entries()->firstOrFail();
        $this->assertSame(UploadEntryStatus::Skipped, $entry->status);
        $this->assertNull($entry->activity_id);
        $this->assertSame(1, $upload->refresh()->count_skipped);
    }

    #[Test]
    public function entri_format_lama_tidak_ikut_diresolusi_ulang(): void
    {
        // Sel `Activity ID: N` membawa id-nya sendiri dan tidak punya nama untuk
        // dicocokkan; mengganti project tidak boleh menghapusnya.
        $upload = $this->draft();

        $hasil = $this->drafter()->reresolveActivities($upload, 118);

        $this->assertSame(['diresolusi' => 0, 'gagal' => 0], $hasil);
        $this->assertSame(3, $upload->entries()->where('status', UploadEntryStatus::Pending->value)->count());
        $this->assertSame(118, $upload->refresh()->project_id);
    }

    #[Test]
    public function daftar_project_diminta_dengan_ignore_dates(): void
    {
        // Tanpa ignoreDates, project yang tanggal selesainya sudah lewat hilang
        // dari daftar — dan itu persis project periode lalu.
        app(KimaiCatalog::class)->projects($this->user);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/projects')
                && str_contains($request->url(), 'ignoreDates=1');
        });
    }

    #[Test]
    public function daftar_activity_diminta_dua_kali_project_dan_global(): void
    {
        app(KimaiCatalog::class)->activities($this->user, 105);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'project=105'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'globals=1'));
    }

    #[Test]
    public function up_10_upload_yang_macet_di_queued_ditandai_gagal_agar_tombolnya_hidup_lagi(): void
    {
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();
        $upload->created_at = now()->subMinutes(30);
        $upload->save();

        $this->assertSame(1, UploadRecovery::failStaleUploads($this->user));

        $upload->refresh();

        $this->assertSame(UploadStatus::Failed, $upload->status);
        $this->assertNotNull($upload->finished_at);
        $this->assertStringContainsString('queue worker', (string) $upload->error_message);
        // Status `failed` bukan `cancelled`: isResumable() membuat tombolnya
        // langsung berubah jadi "Lanjutkan", dan entri yang belum terkirim aman.
        $this->assertTrue($upload->status->isResumable());
        $this->assertSame(3, $upload->pendingEntries()->count());
    }

    #[Test]
    public function up_10_upload_yang_baru_saja_dikirim_tidak_ikut_ditandai_gagal(): void
    {
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        $this->assertSame(0, UploadRecovery::failStaleUploads($this->user));
        $this->assertSame(UploadStatus::Queued, $upload->refresh()->status);
    }

    #[Test]
    public function up_10_upload_posting_dinilai_dari_started_at_bukan_created_at(): void
    {
        // Baris yang dibuat lama tetapi baru mulai dikirim barusan masih sehat;
        // menilainya dari created_at akan memutus upload yang sedang berjalan.
        $upload = $this->draft();
        $upload->forceFill([
            'status' => UploadStatus::Posting->value,
            'started_at' => now()->subSeconds(5),
        ])->save();
        $upload->created_at = now()->subHours(3);
        $upload->save();

        $this->assertSame(0, UploadRecovery::failStaleUploads($this->user));
        $this->assertSame(UploadStatus::Posting, $upload->refresh()->status);

        $upload->forceFill(['started_at' => now()->subMinutes(30)])->save();

        $this->assertSame(1, UploadRecovery::failStaleUploads($this->user));
        $this->assertSame(UploadStatus::Failed, $upload->refresh()->status);
    }

    #[Test]
    public function up_10_penanda_cache_ikut_dibersihkan_saat_upload_ditutup(): void
    {
        // Statusnya saja tidak cukup: sedangBerjalan() membaca tiga penanda, dan
        // kunci yatim berumur 10 menit akan tetap menulis "Mengirim…" untuk upload
        // yang barusan dinyatakan gagal.
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();
        $upload->created_at = now()->subMinutes(30);
        $upload->save();

        PostTimesheetUpload::markPending($this->user);
        Cache::lock(PostTimesheetUpload::lockKey($this->user), 600)->get();

        $this->assertSame(1, UploadRecovery::failStaleUploads($this->user));

        $this->assertFalse(PostTimesheetUpload::isPendingFor($this->user));
        $this->assertFalse(PostTimesheetUpload::isRunningFor($this->user));
    }

    #[Test]
    public function up_10_penanda_cache_tidak_disentuh_kalau_tidak_ada_yang_ditutup(): void
    {
        // Upload yang sehat masih memegang kuncinya; membersihkannya di sini akan
        // membuka pintu untuk upload kedua yang berjalan bersamaan.
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        PostTimesheetUpload::markPending($this->user);

        $this->assertSame(0, UploadRecovery::failStaleUploads($this->user));
        $this->assertTrue(PostTimesheetUpload::isPendingFor($this->user));
    }

    #[Test]
    public function up_10_upload_milik_orang_lain_tidak_ikut_ditutup(): void
    {
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();
        $upload->created_at = now()->subMinutes(30);
        $upload->save();

        $this->assertSame(0, UploadRecovery::failStaleUploads($this->kimaiUser()));
        $this->assertSame(UploadStatus::Queued, $upload->refresh()->status);
    }

    #[Test]
    public function job_yang_kalah_rebutan_kunci_tidak_menghapus_penanda_pending(): void
    {
        // Penandanya dulu dihapus SEBELUM kunci diambil, sehingga job kedua ikut
        // membuang penanda milik job yang justru sedang berjalan.
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        PostTimesheetUpload::markPending($this->user);
        Cache::lock(PostTimesheetUpload::lockKey($this->user), 60)->get();

        (new PostTimesheetUpload($this->user, $upload))->handle(app(UploadPoster::class));

        $this->assertTrue(PostTimesheetUpload::isPendingFor($this->user));
        $this->assertSame(UploadStatus::Queued, $upload->refresh()->status);
        $this->assertSame([], $this->kimaiPostBodies());
    }

    #[Test]
    public function job_tanpa_api_key_menutup_upload_sebagai_gagal(): void
    {
        // Token yang dicabut di antara pratinjau dan pengiriman tidak akan kembali
        // sendiri; membiarkannya `queued` hanya menahan halaman tanpa alasan.
        $upload = $this->draft();
        $upload->forceFill(['status' => UploadStatus::Queued->value])->save();

        $this->user->forceFill(['kimai_api_token' => null])->save();

        (new PostTimesheetUpload($this->user->refresh(), $upload))->handle(app(UploadPoster::class));

        $upload->refresh();

        $this->assertSame(UploadStatus::Failed, $upload->status);
        $this->assertStringContainsString('API key Kimai', (string) $upload->error_message);
        $this->assertFalse(PostTimesheetUpload::isPendingFor($this->user));
    }
}
