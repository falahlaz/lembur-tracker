<?php

namespace App\Domain\Timesheet;

use App\Domain\Kimai\Exceptions\KimaiException;
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
    public function __construct(private readonly KimaiClient $client) {}

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
