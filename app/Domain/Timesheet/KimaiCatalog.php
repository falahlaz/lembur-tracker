<?php

namespace App\Domain\Timesheet;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Kimai\KimaiCatalogMirror;
use App\Domain\Kimai\KimaiClient;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Daftar project dan activity milik satu user, dengan cache pendek.
 *
 * Cache-nya bukan optimasi belaka: `Select::options()` di Filament dipanggil ulang
 * SETIAP KALI komponen dirender, jadi tanpa ini satu halaman bisa memicu belasan
 * permintaan ke instance Kimai yang dipakai seluruh tim.
 *
 * Kuncinya per user karena daftar yang terlihat bergantung pada team dan
 * permission pemilik token — dua orang bisa melihat daftar project yang berbeda.
 */
class KimaiCatalog
{
    public function __construct(
        private readonly KimaiClient $client,
        private readonly KimaiCatalogMirror $mirror,
    ) {}

    /**
     * @return array<int, array{id: int, name: string, customer: ?string}>
     *
     * @throws KimaiException
     */
    public function projects(User $user): array
    {
        return Cache::remember(
            $this->key('projects', $user),
            (int) config('kimai.catalog_ttl'),
            fn () => $this->client->projects((string) $user->kimai_api_token),
        );
    }

    /**
     * Activity yang berlaku di satu project: milik project itu DITAMBAH yang
     * global. Keduanya diambil terpisah lalu digabung — lihat alasannya di
     * KimaiClient::activities().
     *
     * @return array<int, array{id: int, name: string, project: ?int}>
     *
     * @throws KimaiException
     */
    public function activities(User $user, int $projectId): array
    {
        return Cache::remember(
            $this->key("activities:{$projectId}", $user),
            (int) config('kimai.catalog_ttl'),
            function () use ($user, $projectId) {
                $token = (string) $user->kimai_api_token;

                $merged = [];

                foreach ([
                    $this->client->activities($token, projectId: $projectId),
                    $this->client->activities($token, globalsOnly: true),
                ] as $batch) {
                    foreach ($batch as $activity) {
                        // Dedupe: kalau filter project ternyata SUDAH memuat yang
                        // global, entri yang sama datang dua kali.
                        $merged[$activity['id']] = $activity;
                    }
                }

                return array_values($merged);
            },
        );
    }

    /**
     * Kimai dulu; kalau gagal, cermin lokal hasil sync katalog admin.
     *
     * Dipisahkan dari projects() yang tetap MELEMPAR, bukan menggantikannya: jalur
     * yang melempar sudah teruji dan masih dipakai di tempat yang memang harus
     * berhenti saat Kimai mati. Yang ini untuk tempat yang lebih baik memakai
     * daftar agak lama daripada tidak punya daftar sama sekali — asal ia
     * mengatakannya, dan itulah gunanya CatalogResult::dariCermin().
     */
    public function projectsOrMirror(User $user): CatalogResult
    {
        // Token kosong tidak perlu diadu dengan jaringan: permintaannya pasti 401,
        // dan 401 palsu itu akan menandai koneksi user sebagai tidak valid.
        if (! $user->hasKimaiConnection()) {
            return $this->cerminAtauKosong(
                $this->mirror->projects(),
                'API key Kimai belum tersimpan.',
            );
        }

        try {
            return CatalogResult::live($this->projects($user));
        } catch (KimaiException $e) {
            return $this->cerminAtauKosong($this->mirror->projects(), $e->userMessage());
        }
    }

    /** Lihat catatan di projectsOrMirror(). */
    public function activitiesOrMirror(User $user, int $projectId): CatalogResult
    {
        if (! $user->hasKimaiConnection()) {
            return $this->cerminAtauKosong(
                $this->mirror->activities($projectId),
                'API key Kimai belum tersimpan.',
            );
        }

        try {
            return CatalogResult::live($this->activities($user, $projectId));
        } catch (KimaiException $e) {
            return $this->cerminAtauKosong($this->mirror->activities($projectId), $e->userMessage());
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function cerminAtauKosong(array $rows, string $error): CatalogResult
    {
        return $rows === []
            ? CatalogResult::kosong($error)
            : CatalogResult::mirror($rows, $error);
    }

    /** Dipakai tombol "Muat ulang", supaya project baru tidak perlu menunggu TTL. */
    public function forget(User $user): void
    {
        Cache::forget($this->key('projects', $user));

        // Daftar activity ber-kunci per project; yang praktis cuma menaikkan
        // versi kunci, karena project mana saja yang pernah di-cache tidak dilacak.
        Cache::increment($this->versionKey($user));
    }

    private function key(string $what, User $user): string
    {
        $version = Cache::get($this->versionKey($user), 0);

        return "kimai-catalog:{$user->id}:v{$version}:{$what}";
    }

    private function versionKey(User $user): string
    {
        return "kimai-catalog-version:{$user->id}";
    }
}
