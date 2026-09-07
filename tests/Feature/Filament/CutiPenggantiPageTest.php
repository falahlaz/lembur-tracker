<?php

namespace Tests\Feature\Filament;

use App\Enums\BalanceStatus;
use App\Enums\ClaimType;
use App\Filament\Pages\CutiPengganti;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Design Brief §4.3 — tiga tab: saldo aktif, riwayat klaim, saldo hangus. */
class CutiPenggantiPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function tab_saldo_aktif_menampilkan_kartu_per_batch(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-05', '19:00', '23:30');   // 4 jam, hangus 5 Apr
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');   // 8 jam, hangus 12 Apr

        Livewire::test(CutiPengganti::class)
            ->assertSee('Dari lembur 5 Maret 2026')
            ->assertSee('Dari lembur 12 Maret 2026')
            ->assertSee('Berlaku sampai 5 April 2026')
            // Total 12 jam diterjemahkan ke bahasa manusia (P-2).
            ->assertSee('1 hari + datang siang 4 jam')
            ->assertSee('Pakai saldo ini');
    }

    #[Test]
    public function tab_saldo_aktif_kosong_punya_kalimatnya_sendiri(): void
    {
        $this->actingAs($this->employee());

        Livewire::test(CutiPengganti::class)
            ->assertSee('Kamu belum punya saldo cuti pengganti')
            ->assertSee('Saldo muncul otomatis dari lembur minimal 4 jam');
    }

    #[Test]
    public function tab_saldo_hangus_membuka_dengan_total_yang_terbuang(): void
    {
        $user = $this->employee();
        $this->actingAs($user);

        // Lembur Januari yang tidak pernah diklaim — hangus 5 Februari.
        $this->logOvertime($user, '2026-01-05', '09:00', '19:00');   // 8 jam
        $balance = LeaveBalance::query()->sole();
        $balance->syncStatus();
        $balance->save();
        $this->assertSame(BalanceStatus::Expired, $balance->status);

        Livewire::test(CutiPengganti::class)
            ->call('setTab', 'hangus')
            ->assertSee('Sepanjang 2026 kamu kehilangan 8 jam cuti pengganti karena lewat masa berlaku')
            ->assertSee('Hangus 5 Februari 2026');
    }

    #[Test]
    public function tab_riwayat_menampilkan_klaim(): void
    {
        $user = $this->employee();
        $this->actingAs($user);
        $this->logOvertime($user, '2026-03-12', '09:00', '19:00');
        $claim = $this->submitClaim($user, '2026-04-01', ClaimType::FullDay);

        Livewire::test(CutiPengganti::class)
            ->call('setTab', 'riwayat')
            ->assertCanSeeTableRecords([$claim]);
    }

    #[Test]
    public function saldo_orang_lain_tidak_bocor(): void
    {
        $saya = $this->employee();
        $lain = $this->employee();
        $this->logOvertime($lain, '2026-03-12', '09:00', '19:00');

        $this->actingAs($saya);

        Livewire::test(CutiPengganti::class)
            ->assertSee('Kamu belum punya saldo cuti pengganti')
            ->assertDontSee('Dari lembur 12 Maret 2026');
    }
}
