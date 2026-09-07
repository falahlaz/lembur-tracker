<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Design Brief §7 + §10 — perilaku mobile dan checklist implementasi desain. */
class MobileAndThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function bottom_bar_mobile_hadir_di_setiap_halaman_panel(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        foreach (['/app', OvertimeRecordResource::getUrl('index')] as $url) {
            $response = $this->get($url);

            $response->assertOk()
                ->assertSee('Navigasi utama')
                // Hanya tampil di layar kecil.
                ->assertSee('md:hidden', escape: false)
                // Tombol tengah menonjol: alur terpenting di seluruh aplikasi.
                ->assertSee('aria-label="Catat lembur"', escape: false);
        }
    }

    #[Test]
    public function target_sentuh_memenuhi_ukuran_minimum(): void
    {
        $this->actingAs($this->employee());

        // Minimal 44x44px; kelas min-h-[56px] memberi ruang lebih dari cukup.
        $this->get('/app')->assertSee('min-h-[56px]', escape: false);
    }

    #[Test]
    public function panel_memakai_indigo_dan_mendukung_mode_gelap(): void
    {
        $this->actingAs($this->employee());

        $html = $this->get('/app')->assertOk()->getContent();

        // Indigo-500 dalam oklch — Design Brief §5.1.
        $this->assertStringContainsString('--primary-500:oklch(0.585 0.233 277.117)', $html);
        // Filament menyiapkan kelas dark; temanya mengikuti preferensi sistem.
        $this->assertStringContainsString('dark', $html);
    }

    /**
     * Regresi — Filament 4 hanya mengirim kelas semantik `fi-*`; layer utility
     * Tailwind sudah tidak ikut, dan theme.css vendor memakai `source(none)`.
     * Tanpa tema kustom, setiap kelas Tailwind di view kita tidak pernah
     * terkompilasi: markup tetap render, tata letaknya saja yang hilang, tanpa
     * satu pun error. Tes lain di kelas ini hanya memeriksa string kelas di
     * HTML, jadi tidak akan menangkapnya.
     */
    #[Test]
    public function panel_memakai_tema_kustom_agar_kelas_tailwind_terkompilasi(): void
    {
        $theme = resource_path('css/filament/app/theme.css');

        $this->assertSame(
            'resources/css/filament/app/theme.css',
            Filament::getPanel('app')->getViteTheme(),
        );
        $this->assertFileExists($theme);

        $css = file_get_contents($theme);

        // Cakupannya harus seluruh resources/views, bukan hanya subfolder
        // filament/ seperti stub bawaan — komponen di components/lembur ikut
        // memakai utility.
        $this->assertStringContainsString("@source '../../../../resources/views/**/*.blade.php';", $css);
        $this->assertStringContainsString("@source '../../../../app/Filament/**/*.php';", $css);
    }

    #[Test]
    public function angka_memakai_tabular_nums(): void
    {
        // Tanpa ini kolom rupiah terlihat berantakan dan sulit dibandingkan.
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-19', '19:00', '23:30');

        $this->get('/app')->assertSee('tabular-nums', escape: false);
    }

    #[Test]
    public function seluruh_empty_state_ditulis_sendiri(): void
    {
        $this->actingAs($this->employee());

        // Tidak ada satu pun yang memakai teks default Filament.
        $this->get(OvertimeRecordResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Belum ada lembur tercatat')
            ->assertSee('Catat lembur pertamamu')
            ->assertDontSee('No records found');
    }
}
