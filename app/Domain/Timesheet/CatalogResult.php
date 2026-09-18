<?php

namespace App\Domain\Timesheet;

/**
 * Sebuah daftar katalog beserta asal-usulnya.
 *
 * Ada supaya pemanggil tahu daftarnya dari Kimai atau dari cermin lokal TANPA
 * parameter keluaran by-reference dan tanpa exception sebagai alat kendali alur.
 * Bentuk `items`-nya sama persis dengan keluaran KimaiClient, sehingga
 * ActivityResolver dan opsi Select tidak perlu berubah sama sekali.
 */
final readonly class CatalogResult
{
    private function __construct(
        /** @var array<int, array<string, mixed>> */
        public array $items,
        public CatalogSource $source,
        /** Alasan Kimai gagal dihubungi; null berarti memang tidak ada masalah. */
        public ?string $error,
    ) {}

    /** @param array<int, array<string, mixed>> $items */
    public static function live(array $items): self
    {
        return new self($items, CatalogSource::Live, null);
    }

    /** @param array<int, array<string, mixed>> $items */
    public static function mirror(array $items, string $error): self
    {
        return new self($items, CatalogSource::Mirror, $error);
    }

    public static function kosong(string $error): self
    {
        return new self([], CatalogSource::None, $error);
    }

    public function dariCermin(): bool
    {
        return $this->source === CatalogSource::Mirror;
    }

    public function tersedia(): bool
    {
        return $this->items !== [];
    }
}
