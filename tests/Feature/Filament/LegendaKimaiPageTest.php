<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\LegendaKimai;
use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legenda pengisian workbook: daftar activity yang bisa dibaca tanpa membuka Kimai,
 * plus tombol sync katalog milik admin.
 */
class LegendaKimaiPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-09-17');
        $this->fakeKimai([]);
    }

    #[Test]
    public function karyawan_tanpa_koneksi_kimai_tetap_bisa_membuka_legenda(): void
    {
        // Justru merekalah yang paling butuh: mengisi Excel dengan tangan, tanpa
        // token, tanpa cara lain melihat nama activity.
        $this->actingAs($this->employee());

        $this->assertTrue(LegendaKimai::canAccess());
        $this->get(LegendaKimai::getUrl())->assertOk();
    }

    #[Test]
    public function halaman_penuh_ter_render_saat_cerminnya_terisi(): void
    {
        // Model::shouldBeStrict() melempar pada lazy loading, dan blade-nya menyusuri
        // relasi project di dua tempat — render penuh yang membuktikannya aman.
        $this->actingAs($this->employee());
        $this->seedKimaiCatalog();

        $this->get(LegendaKimai::getUrl())
            ->assertOk()
            ->assertSee('C5385 - MyTelkomsel')
            ->assertSee('Cara menulis sel timesheet');
    }

    #[Test]
    public function legenda_menampilkan_activity_dari_cermin_lokal(): void
    {
        $this->actingAs($this->employee());
        $this->seedKimaiCatalog();

        Livewire::test(LegendaKimai::class)
            ->assertCanSeeTableRecords(KimaiActivity::query()->get())
            // Yang ditempel ke sel bukan namanya saja, melainkan baris utuhnya.
            ->assertSee('Activity: 31_DEV_FEATURE')
            ->assertSee('Semua project (global)');
    }

    #[Test]
    public function pencarian_menemukan_activity_lewat_namanya(): void
    {
        $this->actingAs($this->employee());
        $this->seedKimaiCatalog();

        Livewire::test(LegendaKimai::class)
            ->searchTable('DEV_FEATURE')
            ->assertCanSeeTableRecords(KimaiActivity::query()->where('kimai_id', 8)->get())
            ->assertCanNotSeeTableRecords(KimaiActivity::query()->where('kimai_id', 16)->get());
    }

    #[Test]
    public function filter_project_ikut_menampilkan_activity_global(): void
    {
        $this->actingAs($this->employee());
        $this->seedKimaiCatalog();

        // Activity global sah ditulis untuk project mana pun; menyembunyikannya
        // membuat legenda ini menyesatkan.
        Livewire::test(LegendaKimai::class)
            ->filterTable('project_id', 105)
            ->assertCanSeeTableRecords(KimaiActivity::query()->whereIn('kimai_id', [8, 25])->get());
    }

    #[Test]
    public function hanya_admin_yang_melihat_tombol_sync(): void
    {
        $this->actingAs($this->employee());
        Livewire::test(LegendaKimai::class)->assertActionHidden('syncKatalog');

        $this->actingAs($this->kimaiAdmin());
        Livewire::test(LegendaKimai::class)->assertActionVisible('syncKatalog');
    }

    #[Test]
    public function karyawan_tidak_bisa_menjalankan_sync_meski_memanggilnya_langsung(): void
    {
        // Filament mendaftarkan sendiri method ber-akhiran Action(), jadi tombol yang
        // tak tergambar tetap bisa dipanggil lewat Livewire. Pagarnya harus di method,
        // bukan cuma di tampilan.
        $this->actingAs($this->kimaiUser());

        Livewire::test(LegendaKimai::class)->call('syncKatalog');

        $this->assertSame(0, KimaiActivity::query()->count());
    }

    #[Test]
    public function admin_tanpa_token_diarahkan_ke_preferensi(): void
    {
        $admin = $this->employee();
        $admin->update(['role' => Role::Admin]);

        $this->actingAs($admin->refresh());

        Livewire::test(LegendaKimai::class)
            ->assertActionVisible('hubungkanKimai')
            ->assertActionHidden('syncKatalog');
    }

    #[Test]
    public function admin_menekan_sync_mengisi_legenda(): void
    {
        $this->actingAs($this->kimaiAdmin());

        Livewire::test(LegendaKimai::class)
            ->assertSee('belum pernah disinkronkan')
            ->callAction('syncKatalog')
            ->assertNotified();

        $this->assertSame(2, KimaiProject::query()->count());
        $this->assertSame(4, KimaiActivity::query()->count());
    }

    #[Test]
    public function tabel_langsung_terisi_di_request_yang_sama_dengan_sync(): void
    {
        // Filament menyimpan record tabel per request; tanpa resetTable() tabelnya
        // baru terisi setelah halaman dimuat ulang manual.
        $this->actingAs($this->kimaiAdmin());

        Livewire::test(LegendaKimai::class)
            ->callAction('syncKatalog')
            ->assertCanSeeTableRecords(KimaiActivity::query()->get())
            ->assertDontSee('belum pernah disinkronkan');
    }

    #[Test]
    public function sync_yang_gagal_tidak_mengosongkan_legenda(): void
    {
        $this->actingAs($this->kimaiAdmin());
        $this->seedKimaiCatalog();

        $this->kimaiGetStatus = 503;

        Livewire::test(LegendaKimai::class)
            ->callAction('syncKatalog')
            ->assertNotified();

        $this->assertSame(4, KimaiActivity::query()->count());
    }

    #[Test]
    public function legenda_kosong_menjelaskan_langkah_berikutnya(): void
    {
        $this->actingAs($this->employee());

        // Staff tidak punya tombolnya, jadi yang berguna adalah tahu harus minta siapa.
        Livewire::test(LegendaKimai::class)
            ->assertSee('Minta admin menekan tombol');
    }

    #[Test]
    public function label_jam_ditampilkan_beserta_jam_sebenarnya(): void
    {
        $this->actingAs($this->employee());

        // Konvensi jam 12 di template ini terbalik; tabel yang salah soal itu lebih
        // berbahaya daripada tidak ada tabel.
        Livewire::test(LegendaKimai::class)
            ->assertSee('10 AM - 12 AM')
            ->assertSee('10:00–12:00')
            ->assertSee('22:00–24:00');
    }
}
