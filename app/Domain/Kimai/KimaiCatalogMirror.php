<?php

namespace App\Domain\Kimai;

use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use Carbon\CarbonImmutable;

/**
 * Sisi BACA cermin katalog.
 *
 * Bentuk kembaliannya sengaja sama persis dengan KimaiClient/KimaiCatalog, supaya
 * ActivityResolver dan opsi Select tidak perlu tahu datanya datang dari jaringan
 * atau dari salinan lokal. Itu yang membuat jalur cadangan hanya menambah satu
 * pembungkus, bukan cabang logika baru di setiap pemakainya.
 */
class KimaiCatalogMirror
{
    /** @return array<int, array{id: int, name: string, customer: ?string}> */
    public function projects(): array
    {
        return KimaiProject::query()
            ->orderBy('name')
            ->get()
            ->map(fn (KimaiProject $p) => $p->toCatalogRow())
            ->all();
    }

    /**
     * Yang berlaku di satu project: miliknya sendiri DITAMBAH yang global —
     * aturan yang sama dengan KimaiCatalog::activities().
     *
     * @return array<int, array{id: int, name: string, project: ?int}>
     */
    public function activities(int $projectId): array
    {
        return KimaiActivity::query()
            ->forProject($projectId)
            ->orderBy('name')
            ->get()
            ->map(fn (KimaiActivity $a) => $a->toCatalogRow())
            ->all();
    }

    /**
     * Kapan katalog terakhir dipastikan sama dengan Kimai.
     *
     * Diturunkan dari max(synced_at), bukan tabel metadata terpisah: setiap sync
     * yang berhasil menyentuh stempel SELURUH baris, jadi nilainya sudah pasti
     * benar, dan tabel kosong otomatis berarti "belum pernah disinkronkan".
     */
    public function terakhirDisinkronkan(): ?CarbonImmutable
    {
        $waktu = collect([
            KimaiProject::query()->max('synced_at'),
            KimaiActivity::query()->max('synced_at'),
        ])->filter()->max();

        return $waktu === null ? null : CarbonImmutable::parse($waktu);
    }

    public function kosong(): bool
    {
        return ! KimaiActivity::query()->exists() && ! KimaiProject::query()->exists();
    }
}
