<?php

namespace App\Domain\Kimai;

use Carbon\CarbonImmutable;

/**
 * Hasil satu kali sync katalog.
 *
 * Ada supaya notifikasinya bisa menyebut APA yang berubah, mengikuti preseden
 * SyncRun::summary(): "berhasil" saja tidak memberi tahu apa pun, dan orang yang
 * menekan tombol justru sedang mencari jawaban "activity barunya sudah masuk belum".
 */
final readonly class KimaiCatalogSyncResult
{
    public function __construct(
        public KimaiCatalogTally $projects,
        public KimaiCatalogTally $activities,
        public CarbonImmutable $syncedAt,
    ) {}

    public function berubah(): bool
    {
        return $this->projects->berubah() || $this->activities->berubah();
    }

    public function summary(): string
    {
        if (! $this->berubah()) {
            // Nol perubahan tetap dijelaskan dengan isinya, supaya terlihat bahwa
            // sync-nya memang jalan dan katalognya memang sudah sama.
            return sprintf(
                'Katalog sudah sama dengan Kimai — %d project, %d activity.',
                $this->projects->total,
                $this->activities->total,
            );
        }

        $parts = [];

        foreach ([
            ['project', $this->projects],
            ['activity', $this->activities],
        ] as [$label, $tally]) {
            if ($tally->baru > 0) {
                $parts[] = "{$tally->baru} {$label} baru";
            }

            if ($tally->diperbarui > 0) {
                $parts[] = "{$tally->diperbarui} {$label} diperbarui";
            }

            if ($tally->dihapus > 0) {
                $parts[] = "{$tally->dihapus} {$label} dihapus";
            }
        }

        return 'Katalog diperbarui — '.implode(', ', $parts).'.';
    }
}
