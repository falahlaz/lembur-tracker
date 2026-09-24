<?php

namespace App\Filament\Concerns;

use App\Domain\Kimai\KimaiCatalogMirror;
use App\Domain\Timesheet\CatalogResult;
use App\Domain\Timesheet\KimaiCatalog;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Daftar project Kimai untuk Select di halaman Upload dan Isi Timesheet: Kimai
 * dulu, cermin lokal kalau gagal, dan keterangan jujur tentang yang mana.
 */
trait PicksKimaiProject
{
    /**
     * Kenapa Kimai gagal dihubungi; null berarti daftarnya memang datang dari Kimai.
     *
     * Sejak ada cermin lokal, terisinya properti ini TIDAK lagi otomatis berarti
     * daftarnya kosong — bisa saja cermin yang menyelamatkan. Yang membedakan
     * keduanya katalogDariLokal(), dan itulah yang dibaca blade untuk memilih antara
     * peringatan "pakai ID manual" dan keterangan "daftar ini dari data lokal".
     */
    public ?string $katalogError = null;

    /** Memo per request; options() dipanggil berkali-kali per render. */
    private ?CatalogResult $katalogMemo = null;

    private function katalogProject(): CatalogResult
    {
        if ($this->katalogMemo !== null) {
            return $this->katalogMemo;
        }

        $user = Auth::user();

        if ($user === null) {
            return $this->katalogMemo = CatalogResult::kosong('Sesi tidak dikenali.');
        }

        $hasil = app(KimaiCatalog::class)->projectsOrMirror($user);

        $this->katalogError = $hasil->error;

        return $this->katalogMemo = $hasil;
    }

    /** @return array<int, string> id => "Nama (Customer)" */
    public function opsiProject(): array
    {
        $opsi = [];

        foreach ($this->katalogProject()->items as $project) {
            $opsi[$project['id']] = $project['customer'] !== null
                ? "{$project['name']} ({$project['customer']})"
                : $project['name'];
        }

        return $opsi;
    }

    public function katalogTersedia(): bool
    {
        return $this->opsiProject() !== [];
    }

    /** Daftarnya terselamatkan cermin lokal, bukan datang dari Kimai. */
    public function katalogDariLokal(): bool
    {
        return $this->katalogProject()->dariCermin();
    }

    public function terakhirKatalogDisinkronkan(): ?CarbonImmutable
    {
        return app(KimaiCatalogMirror::class)->terakhirDisinkronkan();
    }

    /** "17 Sep 14:20", atau null kalau cerminnya belum pernah diisi. */
    public function terakhirKatalogDisinkronkanTeks(): ?string
    {
        $waktu = $this->terakhirKatalogDisinkronkan();

        return $waktu === null
            ? null
            : Format::tanggalRingkas($waktu).' '
                .$waktu->timezone(config('app.display_timezone'))->format('H:i');
    }

    /**
     * Potongan kalimat ", terakhir disinkronkan 17 Sep 14:20" — atau string kosong.
     *
     * Dirakit di sini, bukan di blade: menyusunnya di sana menuntut variabel blade,
     * dan @php(...) sebaris di berkas ini adalah jebakan (lihat catatan di view).
     */
    public function keteranganSinkronCermin(): string
    {
        $teks = $this->terakhirKatalogDisinkronkanTeks();

        return $teks === null ? '' : ", terakhir disinkronkan {$teks}";
    }

    /** Project baru di Kimai tidak perlu menunggu TTL cache. */
    public function muatUlangKatalog(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        app(KimaiCatalog::class)->forget($user);
        $this->katalogMemo = null;
        unset($this->uploadSaatIni, $this->entries, $this->riwayat);

        Notification::make()->success()->title('Daftar project dimuat ulang')->send();
    }
}
