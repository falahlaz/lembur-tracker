<?php

namespace Tests\Feature\Console;

use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Jalan masuk tanpa browser untuk mengisi legenda — lihat SyncKimaiCatalog. */
class SyncKimaiCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-09-17');
        $this->fakeKimai([]);
    }

    #[Test]
    public function tanpa_admin_bertoken_command_menjelaskan_langkah_berikutnya(): void
    {
        $this->employee();

        $this->artisan('lemburku:kimai:catalog')
            ->expectsOutputToContain('Tidak ada admin aktif yang sudah menyimpan API key Kimai.')
            ->assertFailed();
    }

    #[Test]
    public function admin_bertoken_mengisi_cermin_katalog(): void
    {
        $this->kimaiAdmin();

        $this->artisan('lemburku:kimai:catalog')->assertSuccessful();

        $this->assertSame(2, KimaiProject::query()->count());
        $this->assertSame(4, KimaiActivity::query()->count());
    }

    #[Test]
    public function user_yang_disebut_tapi_belum_punya_token_ditolak_dengan_jelas(): void
    {
        $karyawan = $this->employee();

        $this->artisan('lemburku:kimai:catalog', ['--user' => $karyawan->email])
            ->expectsOutputToContain('belum menyimpan API key Kimai')
            ->assertFailed();

        $this->assertSame(0, KimaiActivity::query()->count());
    }

    #[Test]
    public function kimai_yang_gagal_dilaporkan_tanpa_stack_trace(): void
    {
        $this->kimaiAdmin();
        $this->kimaiGetStatus = 503;

        $this->artisan('lemburku:kimai:catalog')->assertFailed();

        $this->assertSame(0, KimaiActivity::query()->count());
    }
}
