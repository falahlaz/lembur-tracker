<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LeaveClaims\LeaveClaimResource;
use App\Filament\Resources\OvertimeRecords\OvertimeRecordResource;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menjaga aset JS Filament tetap terbit dan tetap tereferensi.
 *
 * Komponen `native(false)` — DatePicker dan Select di seluruh panel — tidak
 * mengirim markup yang bisa dipakai; Filament hanya menaruh wadah kosong
 * ber-`x-load` lalu menyerahkan sisanya ke modul di `public/js/filament/`.
 * Kalau modul itu tidak ada, tidak ada satu pun exception, log, atau perubahan
 * tampilan: halaman tetap ter-style, tetapi "Tanggal cuti" jadi input readonly
 * yang kalendernya tak pernah terbuka dan "Status" jadi kotak kosong. Kegagalan
 * sediam itu hanya bisa dijaga dari sini.
 */
class AssetsPublishedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeDate('2026-03-20');
        $this->baselineRule();
    }

    #[Test]
    public function setiap_aset_filament_terpublikasi_di_public(): void
    {
        // Sumber daftarnya sama dengan yang disalin `php artisan filament:assets`,
        // jadi test ini ikut mendeteksi aset baru dari paket yang di-upgrade.
        $missing = (new Collection)
            ->merge(FilamentAsset::getAlpineComponents())
            ->merge(FilamentAsset::getScripts())
            ->merge(FilamentAsset::getStyles())
            ->reject(fn ($asset) => $asset->isRemote())
            ->reject(fn ($asset) => file_exists($asset->getPublicPath()))
            ->map(fn ($asset) => $asset->getRelativePublicPath())
            ->values()
            ->all();

        $this->assertSame([], $missing, 'Aset belum terbit — jalankan `php artisan filament:upgrade`.');
    }

    #[Test]
    public function form_merender_src_komponen_alpine_yang_dibutuhkan(): void
    {
        $this->actingAs($this->employee());

        // Kedua form memakai DatePicker dan Select `native(false)`; keduanya ikut
        // diuji supaya perbaikan di satu halaman tidak menutupi kerusakan global.
        foreach ([
            LeaveClaimResource::getUrl('create'),
            OvertimeRecordResource::getUrl('create'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('js/filament/forms/components/date-time-picker.js', escape: false)
                ->assertSee('js/filament/forms/components/select.js', escape: false);
        }
    }
}
