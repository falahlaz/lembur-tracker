<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\ImportLembur;
use App\Filament\Pages\UploadTimesheet;
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

    /**
     * Berkas yang terbit saja tidak cukup: URL-nya harus memakai skema yang sama
     * dengan halamannya. HTTPS ditutup di reverse proxy VPS, jadi php-fpm selalu
     * melihat request http polos dan satu-satunya petunjuk skema asli adalah
     * X-Forwarded-Proto. Sebelum `trustProxies()` dipasang di bootstrap/app.php,
     * header itu diabaikan dan Filament menulis `http://.../select.js` di
     * halaman https — browser memblokirnya sebagai mixed content, dan gejalanya
     * sama persis dengan aset yang hilang: date picker jadi input readonly,
     * select jadi kotak kosong, file upload kembali jadi "Choose File".
     *
     * Test tetangga di kelas ini mencocokkan substring tanpa skema, jadi
     * kerusakan itu lolos begitu saja. Dua test berikut yang menjaganya.
     */
    #[Test]
    public function url_aset_ikut_https_saat_proxy_mengirim_x_forwarded_proto(): void
    {
        $this->actingAs($this->employee());

        $urls = $this->filamentScriptUrls(
            OvertimeRecordResource::getUrl('create'),
            ['X-Forwarded-Proto' => 'https'],
        );

        $this->assertContains('select.js', array_map(
            fn (string $url) => basename(parse_url($url, PHP_URL_PATH)),
            $urls,
        ));

        foreach ($urls as $url) {
            $this->assertStringStartsWith(
                'https://',
                $url,
                "Aset Filament masih http:// di halaman https: {$url}",
            );
        }
    }

    #[Test]
    public function url_aset_tetap_http_tanpa_header_proxy(): void
    {
        // Dev lokal jalan di http://localhost:8080 tanpa TLS di depannya.
        // Perbaikan skema tidak boleh memaksa https di sana.
        $this->actingAs($this->employee());

        $urls = $this->filamentScriptUrls(OvertimeRecordResource::getUrl('create'));

        foreach ($urls as $url) {
            $this->assertStringStartsWith('http://', $url);
        }
    }

    /**
     * Setiap URL absolut ke modul `public/js/filament/` di sebuah halaman.
     * Host-nya sengaja tidak diasumsikan: nilainya ikut APP_URL, yang berbeda
     * antara mesin developer dan CI.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function filamentScriptUrls(string $url, array $headers = []): array
    {
        $html = $this->get($url, $headers)->assertOk()->getContent();

        preg_match_all('#https?://[^"\'\s&<>]+/js/filament/[^"\'\s&<>]+#', $html, $matches);

        $urls = array_values(array_unique($matches[0]));

        $this->assertNotSame([], $urls, 'Halaman tidak merujuk satu pun modul Filament.');

        return $urls;
    }

    /**
     * FileUpload selalu merender `<input type="file">` di server — FilePond
     * memakainya sebagai sumber, bukan sebagai fallback. Kalau modul
     * file-upload.js tidak pernah diminta, input itu tampil apa adanya
     * ("Choose File / No file chosen") sementara sisa panel terlihat normal.
     *
     * Dua halaman ber-FileUpload diuji TERPISAH: berganti user di dalam satu
     * request test memicu AuthenticateSession membatalkan sesinya.
     */
    #[Test]
    public function halaman_upload_timesheet_merender_src_komponen_file_upload(): void
    {
        // Halaman menarik daftar project dari Kimai saat mount.
        $this->fakeKimai([]);
        $this->actingAs($this->kimaiUser());

        $this->get(UploadTimesheet::getUrl())
            ->assertOk()
            ->assertSee('js/filament/forms/components/file-upload.js', escape: false);
    }

    #[Test]
    public function halaman_import_historis_merender_src_komponen_file_upload(): void
    {
        $admin = $this->employee();
        $admin->update(['role' => Role::Admin]);
        $this->actingAs($admin->refresh());

        $this->get(ImportLembur::getUrl())
            ->assertOk()
            ->assertSee('js/filament/forms/components/file-upload.js', escape: false);
    }
}
