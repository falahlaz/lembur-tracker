<?php

namespace App\Domain\Kimai;

/** Perubahan satu tabel cermin dalam satu kali sync katalog. */
final readonly class KimaiCatalogTally
{
    public function __construct(
        public int $baru = 0,
        public int $diperbarui = 0,
        public int $dihapus = 0,
        /** Jumlah baris setelah sync selesai. */
        public int $total = 0,
    ) {}

    public function berubah(): bool
    {
        return $this->baru > 0 || $this->diperbarui > 0 || $this->dihapus > 0;
    }
}
