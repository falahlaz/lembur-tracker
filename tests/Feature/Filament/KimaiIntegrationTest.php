<?php

namespace Tests\Feature\Filament;

use App\Domain\Kimai\KimaiSynchronizer;
use App\Enums\OvertimeStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Preferensi;
use App\Filament\Pages\RiwayatSync;
use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use App\Filament\Resources\OvertimeRecords\Pages\EditOvertimeRecord;
use App\Filament\Resources\OvertimeRecords\Pages\ListOvertimeRecords;
use App\Jobs\SyncKimaiTimesheets;
use App\Models\OvertimeRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-11, F-12, F-13 — lapisan UI sync, lewat komponen Filament sungguhan. */
class KimaiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-09-13');
        $this->baselineRule();
    }

    #[Test]
    public function f_11_token_hanya_tersimpan_bila_tes_koneksi_lulus(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Http::fake(['*/api/timesheets*' => Http::response(['message' => 'Unauthorized'], 401)]);

        Livewire::test(Preferensi::class)
            ->fillForm(['kimai_token' => 'token-salah'])
            ->call('saveKimaiToken');

        $this->assertNull($user->refresh()->kimai_api_token);
    }

    #[Test]
    public function f_11_token_yang_lulus_tersimpan_dan_hanya_tampil_empat_digit(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Http::fake(['*/api/timesheets*' => Http::response([], 200)]);

        Livewire::test(Preferensi::class)
            ->fillForm(['kimai_token' => 'token-panjang-ab12'])
            ->call('saveKimaiToken');

        $user->refresh();

        $this->assertSame('token-panjang-ab12', $user->kimai_api_token);
        $this->assertSame('ab12', $user->kimai_token_last4);
        // §9 — yang pernah ditampilkan kembali hanya 4 karakter terakhir.
        $this->assertSame('••••••••••••ab12', $user->kimaiTokenMask());
    }

    #[Test]
    public function f_11_token_disimpan_terenkripsi_di_database(): void
    {
        $user = $this->kimaiUser(token: 'rahasia-sekali-9999');

        $raw = DB::table('users')->where('id', $user->id)->value('kimai_api_token');

        $this->assertNotSame('rahasia-sekali-9999', $raw);
        $this->assertStringNotContainsString('rahasia-sekali', (string) $raw);
    }

    #[Test]
    public function f_11_hapus_koneksi_menghapus_token_tapi_menyisakan_lembur(): void
    {
        $user = $this->kimaiUser();
        $this->actingAs($user);
        $this->fakeKimai([$this->kimaiEntry()]);
        app(KimaiSynchronizer::class)->run($user);

        $this->assertSame(1, OvertimeRecord::count());

        Livewire::test(Preferensi::class)->call('forgetKimaiConnection');

        $user->refresh();
        $this->assertNull($user->kimai_api_token);
        $this->assertFalse($user->kimai_auto_sync);
        // §9 Rotasi — record lembur yang sudah masuk tetap ada.
        $this->assertSame(1, OvertimeRecord::count());
    }

    #[Test]
    public function f_12_tombol_sync_hanya_muncul_bila_token_sudah_tersimpan(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(Dashboard::class)
            ->assertActionExists('hubungkanKimai')
            ->assertActionDoesNotExist('syncKimai');

        $this->actingAs($this->kimaiUser());

        Livewire::test(Dashboard::class)
            ->assertActionExists('syncKimai')
            ->assertActionDoesNotExist('hubungkanKimai');
    }

    #[Test]
    public function f_12_klik_sync_hanya_menaruh_job_ke_queue(): void
    {
        Queue::fake();
        $user = $this->kimaiUser();
        $this->actingAs($user);

        Livewire::test(ListOvertimeRecords::class)->callAction('syncKimai');

        // SY-17 — tidak ada permintaan HTTP ke Kimai di siklus request web.
        Queue::assertPushed(SyncKimaiTimesheets::class);
        $this->assertTrue(SyncKimaiTimesheets::isPendingFor($user));
    }

    #[Test]
    public function sy_18_klik_kedua_saat_job_masih_jalan_ditolak(): void
    {
        Queue::fake();
        $user = $this->kimaiUser();
        $this->actingAs($user);

        SyncKimaiTimesheets::markPending($user);

        Livewire::test(Dashboard::class)->callAction('syncKimai');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function f_13_riwayat_sync_menampilkan_run_milik_sendiri_saja(): void
    {
        $user = $this->kimaiUser();
        $lain = $this->kimaiUser();

        $this->fakeKimai([$this->kimaiEntry()]);
        app(KimaiSynchronizer::class)->run($user);
        app(KimaiSynchronizer::class)->run($lain);

        $this->actingAs($user);

        Livewire::test(RiwayatSync::class)
            ->assertCanSeeTableRecords($user->syncRuns()->get())
            ->assertCanNotSeeTableRecords($lain->syncRuns()->get());
    }

    #[Test]
    public function f_13_riwayat_sync_tersembunyi_bagi_user_tanpa_koneksi(): void
    {
        $this->actingAs($this->employee());

        $this->assertFalse(RiwayatSync::canAccess());
    }

    #[Test]
    public function halaman_kimai_render_utuh_lewat_http(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);
        app(KimaiSynchronizer::class)->run($user);
        $this->actingAs($user);

        // F-13 — ringkasan dan tautan ke record hasilnya.
        $this->get(RiwayatSync::getUrl())
            ->assertOk()
            ->assertSee('Sync selesai · 1 lembur baru')
            ->assertSee('Lihat lembur');

        // F-11 — status koneksi terbaca manusia.
        $this->get(Preferensi::getUrl())
            ->assertOk()
            ->assertSee('Integrasi Kimai')
            ->assertSee('Terhubung');

        // SY-12 — badge muncul di muka kartu, bukan tersembunyi di panel.
        $this->get(OvertimeRecordResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Lengkapi evidence');

        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Sync Kimai');
    }

    #[Test]
    public function sy_12_status_tidak_bisa_diajukan_selama_evidence_belum_diganti(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);
        app(KimaiSynchronizer::class)->run($user);

        $record = OvertimeRecord::sole();
        $this->assertTrue($record->evidence_needs_review);

        $this->actingAs($user);

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm(['status' => OvertimeStatus::Submitted->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(OvertimeStatus::Recorded, $record->refresh()->status);
    }

    #[Test]
    public function sy_12_mengganti_evidence_memadamkan_badge_dan_membuka_status(): void
    {
        $user = $this->kimaiUser();
        $this->fakeKimai([$this->kimaiEntry()]);
        app(KimaiSynchronizer::class)->run($user);

        $record = OvertimeRecord::sole();
        $this->actingAs($user);

        Livewire::test(EditOvertimeRecord::class, ['record' => $record->getKey()])
            ->fillForm([
                'evidence_url' => 'https://onedrive.example.test/spl/asli',
                'status' => OvertimeStatus::Submitted->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();
        $this->assertFalse($record->evidence_needs_review);
        $this->assertSame(OvertimeStatus::Submitted, $record->status);
        // SY-14 — sentuhan manusia mengunci record ini dari sync selamanya.
        $this->assertTrue($record->locally_modified);
    }
}
