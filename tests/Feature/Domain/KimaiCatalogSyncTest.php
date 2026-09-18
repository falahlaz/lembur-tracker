<?php

namespace Tests\Feature\Domain;

use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\Exceptions\KimaiUnavailable;
use App\Domain\Kimai\KimaiCatalogSync;
use App\Domain\Kimai\KimaiCatalogSyncResult;
use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KimaiCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeDate('2026-09-17');
        $this->fakeKimai([]);
    }

    private function sync(): KimaiCatalogSyncResult
    {
        return app(KimaiCatalogSync::class)->run($this->kimaiAdmin());
    }

    #[Test]
    public function katalog_ditarik_dan_disimpan_ke_cermin_lokal(): void
    {
        $hasil = $this->sync();

        $this->assertSame(2, KimaiProject::query()->count());
        $this->assertSame(4, KimaiActivity::query()->count());

        $project = KimaiProject::query()->where('kimai_id', 105)->sole();
        $this->assertSame('C5385 - MyTelkomsel', $project->name);
        // parentTitle di respons Kimai adalah nama CUSTOMER, bukan nama project.
        $this->assertSame('Telkomsel', $project->customer);

        $this->assertSame(2, $hasil->projects->baru);
        $this->assertSame(4, $hasil->activities->baru);
        $this->assertTrue($hasil->berubah());
    }

    #[Test]
    public function activity_global_tersimpan_tanpa_project(): void
    {
        $this->sync();

        // Fake hanya melayani activity global lewat permintaan ber-`globals=1`,
        // jadi baris ini membuktikan penggabungan dua permintaan itu benar-benar jalan.
        $global = KimaiActivity::query()->where('kimai_id', 25)->sole();

        $this->assertNull($global->project_id);
        $this->assertTrue($global->isGlobal());
    }

    #[Test]
    public function sync_kedua_menghitung_yang_baru_diperbarui_dan_dihapus(): void
    {
        $this->sync();

        $this->kimaiActivities = [
            ['id' => 25, 'name' => '12_PROJECT_MEETING', 'project' => null],
            // 8 berganti nama, 9 hilang dari Kimai, 44 baru.
            ['id' => 8, 'name' => '31_DEV_FEATURE_BARU', 'project' => 105],
            ['id' => 16, 'name' => '33_DEV_BUGFIX', 'project' => 105],
            ['id' => 44, 'name' => '61_CODE_REVIEW', 'project' => 105],
        ];

        $hasil = $this->sync();

        $this->assertSame(1, $hasil->activities->baru);
        $this->assertSame(1, $hasil->activities->diperbarui);
        $this->assertSame(1, $hasil->activities->dihapus);
        $this->assertSame(4, $hasil->activities->total);

        $this->assertSame('31_DEV_FEATURE_BARU', KimaiActivity::query()->where('kimai_id', 8)->sole()->name);
        $this->assertFalse(KimaiActivity::query()->where('kimai_id', 9)->exists());
    }

    #[Test]
    public function sync_yang_tidak_mengubah_apa_pun_tetap_menyebut_isinya(): void
    {
        $this->sync();
        $hasil = $this->sync();

        $this->assertFalse($hasil->berubah());
        // "Berhasil" saja tidak memberi tahu apa pun — ringkasannya wajib menyebut angka.
        $this->assertSame('Katalog sudah sama dengan Kimai — 2 project, 4 activity.', $hasil->summary());
    }

    #[Test]
    public function ringkasan_menyebut_apa_yang_berubah(): void
    {
        $hasil = $this->sync();

        $this->assertStringContainsString('2 project baru', $hasil->summary());
        $this->assertStringContainsString('4 activity baru', $hasil->summary());
    }

    #[Test]
    public function kimai_tidak_terjangkau_tidak_pernah_mengosongkan_cermin(): void
    {
        $this->sync();

        $this->kimaiGetStatus = 503;

        try {
            $this->sync();
            $this->fail('Seharusnya melempar KimaiUnavailable.');
        } catch (KimaiUnavailable) {
            // Yang diuji justru ini: pemangkasan TIDAK boleh jalan dengan daftar
            // yang cuma separuh — legenda lama jauh lebih berguna daripada kosong.
        }

        $this->assertSame(2, KimaiProject::query()->count());
        $this->assertSame(4, KimaiActivity::query()->count());
    }

    #[Test]
    public function token_yang_ditolak_dilaporkan_sebagai_token_invalid(): void
    {
        $this->kimaiGetStatus = 401;

        $this->expectException(KimaiTokenInvalid::class);

        $this->sync();
    }

    #[Test]
    public function activity_lebih_dari_satu_halaman_tetap_lengkap(): void
    {
        // Regresi untuk /api/activities yang dulu diambil dengan SATU permintaan
        // tanpa page/size: begitu jumlahnya melewati satu halaman, sisanya hilang
        // tanpa satu pun pesan.
        config(['kimai.page_size' => 20]);

        $banyak = [['id' => 25, 'name' => '12_PROJECT_MEETING', 'project' => null]];

        for ($i = 1; $i <= 55; $i++) {
            $banyak[] = ['id' => 1000 + $i, 'name' => sprintf('90_BULK_%02d', $i), 'project' => 105];
        }

        $this->kimaiActivities = $banyak;

        $this->sync();

        $this->assertSame(56, KimaiActivity::query()->count());

        // Terbukti benar-benar berhalaman: 55 activity ber-project dengan size 20
        // butuh 3 permintaan, ditambah 1 permintaan khusus yang global.
        $permintaanActivity = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/api/activities'))
            ->count();

        $this->assertSame(4, $permintaanActivity);
    }
}
