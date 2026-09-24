<?php

namespace Tests\Feature\Filament;

use App\Domain\Timesheet\UploadDrafter;
use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use App\Filament\Pages\IsiTimesheet;
use App\Filament\Pages\UploadTimesheet;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IsiTimesheetPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-09-24');
        $this->baselineRule();
        $this->fakeKimai([]);
    }

    private function baris(string $tanggal, string $mulai = '09:00', string $selesai = '18:00', array $overrides = []): array
    {
        return array_merge([
            'tanggal' => $tanggal,
            'mulai' => $mulai,
            'durasi' => null,
            'selesai' => $selesai,
            'activity_id' => 8,
            'deskripsi' => 'Development fitur A',
            'lembur' => false,
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function halaman(array $rows): Testable
    {
        $keyed = [];

        foreach ($rows as $i => $row) {
            $keyed["baris-{$i}"] = $row;
        }

        return Livewire::test(IsiTimesheet::class)
            ->set('data.project_id', 105)
            ->set('data.rows', $keyed);
    }

    #[Test]
    public function karyawan_yang_sudah_menghubungkan_kimai_bisa_membukanya(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->assertTrue(IsiTimesheet::canAccess());
        $this->get(IsiTimesheet::getUrl())->assertOk()->assertSee('Periksa isian');
    }

    #[Test]
    public function tertutup_untuk_yang_belum_menghubungkan_kimai(): void
    {
        $this->actingAs($this->employee());

        $this->assertFalse(IsiTimesheet::canAccess());
    }

    #[Test]
    public function dua_hari_penuh_menjadi_draf_berisi_potongan_dua_jam_tanpa_mengirim(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22'), $this->baris('2026-09-23')])
            ->call('periksa')
            ->assertHasNoErrors()
            ->assertSee('Pratinjau · Isian manual · 22 Sep – 23 Sep 2026');

        $upload = TimesheetUpload::query()->sole();

        $this->assertSame(TimesheetUpload::SOURCE_FORM, $upload->source);
        $this->assertSame(UploadStatus::Draft, $upload->status);
        $this->assertSame(105, $upload->project_id);
        $this->assertSame(10, $upload->count_parsed);
        $this->assertSame(10, $upload->pendingEntries()->count());
        $this->assertLessThanOrEqual(120, $upload->entries()->max('duration_minutes'));
        $this->assertSame('31_DEV_FEATURE', $upload->entries()->first()->activity_name);
        $this->assertSame([], $this->kimaiPostBodies());
    }

    #[Test]
    public function jam_yang_sudah_terisi_di_kimai_dilewati_seperti_upload(): void
    {
        $this->fakeKimai([$this->kimaiSlot(700001, '2026-09-22', '09:00', '11:00', ['tags' => []])]);
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22', '09:00', '13:00')])->call('periksa');

        $entries = TimesheetUpload::query()->sole()->entries()->orderBy('begin_at')->get();

        $this->assertSame(UploadEntryStatus::Skipped, $entries[0]->status);
        $this->assertSame(UploadEntryStatus::Pending, $entries[1]->status);
    }

    #[Test]
    public function activity_yang_tidak_berlaku_di_project_dilewati(): void
    {
        $user = $this->kimaiUser();

        // Select di form sudah menolak id yang tidak ada di daftarnya; ini pagar
        // lapis kedua di drafter, misalnya untuk state browser yang basi.
        $upload = app(UploadDrafter::class)->draftManual(
            $user,
            [$this->baris('2026-09-22', '09:00', '10:00', ['activity_id' => 999])],
            105,
        );

        $entry = $upload->entries()->sole();

        $this->assertSame(UploadEntryStatus::Skipped, $entry->status);
        $this->assertStringContainsString('tidak berlaku', (string) $entry->skip_reason);
    }

    #[Test]
    public function baris_yang_bertumpukan_ditolak_tanpa_membuat_draf(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([
            $this->baris('2026-09-22', '09:00', '12:00'),
            $this->baris('2026-09-22', '11:00', '13:00'),
        ])
            ->call('periksa')
            ->assertNotified('Isiannya belum bisa diperiksa');

        $this->assertSame(0, TimesheetUpload::query()->count());
    }

    #[Test]
    public function field_wajib_divalidasi_sebelum_apa_pun(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22', overrides: ['deskripsi' => null])])
            ->call('periksa')
            ->assertHasErrors(['data.rows.baris-0.deskripsi']);

        $this->assertSame(0, TimesheetUpload::query()->count());
    }

    #[Test]
    public function salin_ke_rentang_mengisi_hari_kerja_saja(): void
    {
        $this->actingAs($this->kimaiUser());

        $page = $this->halaman([
            $this->baris('2026-09-21', '09:00', '12:00'),
            $this->baris('2026-09-21', '13:00', '18:00', ['activity_id' => 9]),
        ])->callAction('salinKeRentang', [
            'sumber' => '2026-09-21',
            'dari' => '2026-09-21',
            'sampai' => '2026-09-27',
            'hari' => [1, 2, 3, 4, 5],
            'lewati_terisi' => true,
        ])->assertHasNoActionErrors();

        $rows = array_values($page->get('data.rows'));

        // Senin sumber + Selasa–Jumat, masing-masing dua baris; Sabtu–Minggu kosong.
        $this->assertCount(10, $rows);
        $this->assertSame(
            ['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'],
            array_values(array_unique(array_column($rows, 'tanggal'))),
        );

        $page->call('periksa');

        $this->assertSame(25, TimesheetUpload::query()->sole()->count_parsed);
    }

    #[Test]
    public function kirim_hanya_menaruh_job_ke_queue_ke_project_draf(): void
    {
        Queue::fake();
        $this->actingAs($this->kimaiUser());

        $page = $this->halaman([$this->baris('2026-09-22', '09:00', '11:00')])->call('periksa');

        // Project di form diganti SESUDAH diperiksa: yang dipakai tetap project draf,
        // karena activity-nya diperiksa terhadap project itu.
        $page->set('data.project_id', 118)->call('kirim');

        Queue::assertPushed(PostTimesheetUpload::class);

        $upload = TimesheetUpload::query()->sole();
        $this->assertSame(UploadStatus::Queued, $upload->status);
        $this->assertSame(105, $upload->project_id);
    }

    #[Test]
    public function mengirim_betulan_memecah_payload_sesuai_batas_kimai(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22', '19:00', '00:00', ['lembur' => true])])
            ->call('periksa')
            ->call('kirim');

        $bodies = $this->kimaiPostBodies();

        $this->assertCount(3, $bodies);
        $this->assertSame('2026-09-22T19:00:00+0700', $bodies[0]['begin']);
        $this->assertSame('2026-09-23T00:00:00+0700', $bodies[2]['end']);
        $this->assertSame('Overtime', $bodies[0]['tags']);
    }

    #[Test]
    public function draf_isian_tidak_muncul_di_halaman_upload_dan_sebaliknya(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22', '09:00', '10:00')])->call('periksa');

        $this->assertNotNull(Livewire::test(IsiTimesheet::class)->get('uploadId'));
        $this->assertNull(Livewire::test(UploadTimesheet::class)->get('uploadId'));
    }

    #[Test]
    public function durasi_mengisi_jam_selesai(): void
    {
        $this->actingAs($this->kimaiUser());

        $this->halaman([$this->baris('2026-09-22', '09:00', '')])
            ->set('data.rows.baris-0.durasi', '2:30')
            ->assertSet('data.rows.baris-0.selesai', '11:30');
    }
}
