<?php

namespace Tests\Feature\Filament;

use App\Enums\ClaimType;
use App\Enums\OvertimeStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BannerSaldoHangus;
use App\Filament\Widgets\RingkasanStats;
use App\Filament\Widgets\TimelinePayroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** F-03 — dashboard: banner, stat tile, timeline, saldo aktif, aktivitas. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function stat_tile_menampilkan_saldo_dan_estimasi_bertingkat(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        // Periode berjalan 19 Mar – 18 Apr.
        $this->logOvertime($user, '2026-03-19', '09:00', '19:00', OvertimeStatus::Approved);   // 8j, Rp100rb
        $this->logOvertime($user, '2026-03-20', '19:00', '23:30', OvertimeStatus::Recorded);   // 4j30m, Rp50rb

        Livewire::test(RingkasanStats::class)
            ->assertSee('Saldo cuti pengganti')
            ->assertSee('12 jam')                                  // 480 + 240
            ->assertSee('1 hari + datang siang 4 jam')             // P-2
            ->assertSee('Estimasi uang makan')
            ->assertSee('Rp150.000')                               // BR-10 maksimal
            ->assertSee('Sudah pasti Rp100.000')                   // BR-10 pasti
            ->assertSee('Kuota bulan ini')
            ->assertSee('0 / 3 hari');
    }

    #[Test]
    public function banner_muncul_hanya_saat_ada_saldo_hampir_hangus(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        // Lembur 25 Feb → hangus 25 Mar, yaitu 5 hari lagi dari 20 Maret.
        $this->logOvertime($user, '2026-02-25', '19:00', '23:30');

        $this->assertTrue(BannerSaldoHangus::canView());

        Livewire::test(BannerSaldoHangus::class)
            ->assertSee('hangus 5 hari lagi')
            ->assertSee('Dari lembur 25 Februari 2026')
            ->assertSee('Ajukan klaim');
    }

    #[Test]
    public function banner_tidak_muncul_saat_semua_saldo_masih_lama(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        // Hangus 19 April — masih 30 hari lagi.
        $this->logOvertime($user, '2026-03-19', '09:00', '19:00');

        // Tidak ada banner "semuanya aman"; ruang kosong lebih baik.
        $this->assertFalse(BannerSaldoHangus::canView());
    }

    #[Test]
    public function timeline_menunjukkan_posisi_terhadap_cut_off(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(TimelinePayroll::class)
            ->assertSee('Periode payroll berjalan')
            ->assertSee('April 2026')          // label periode 19 Mar – 18 Apr
            ->assertSee('19 Mar')
            ->assertSee('18 Apr')
            ->assertSee('cut-off');
    }

    #[Test]
    public function dashboard_menyapa_dan_memasang_disclaimer(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertSee('Halo, '.$user->name)
            ->assertSee('bukan perhitungan payroll resmi');
    }

    #[Test]
    public function aktivitas_terakhir_menggabungkan_lembur_dan_klaim(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        Livewire::test(\App\Filament\Widgets\AktivitasTerakhir::class)
            ->assertSee('Lembur 12 Maret 2026')
            ->assertSee('Klaim 1 April 2026')
            ->assertSee('libur 1 hari penuh');
    }
}
