<?php

namespace App\Models;

use App\Domain\Kimai\KimaiCatalogSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu project Kimai di cermin lokal. Diisi hanya oleh sync katalog.
 *
 * @see KimaiCatalogSync
 */
#[Fillable(['kimai_id', 'name', 'customer', 'synced_at'])]
class KimaiProject extends Model
{
    protected function casts(): array
    {
        return [
            'kimai_id' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /** Relasi lewat id KIMAI, bukan kunci primer lokal. */
    public function activities(): HasMany
    {
        return $this->hasMany(KimaiActivity::class, 'project_id', 'kimai_id');
    }

    /**
     * "C5385 - MyTelkomsel (Telkomsel)".
     *
     * Satu tempat, dipakai legenda DAN opsi Select di Upload Timesheet — supaya
     * label yang dilihat orang di dua halaman itu tidak pernah berbeda.
     */
    public function label(): string
    {
        return $this->customer !== null
            ? "{$this->name} ({$this->customer})"
            : $this->name;
    }

    /**
     * Bentuk yang SAMA PERSIS dengan keluaran KimaiClient::projects(), sehingga
     * pemakainya tidak perlu tahu datanya dari jaringan atau dari cermin.
     *
     * @return array{id: int, name: string, customer: ?string}
     */
    public function toCatalogRow(): array
    {
        return [
            'id' => (int) $this->kimai_id,
            'name' => (string) $this->name,
            'customer' => $this->customer,
        ];
    }
}
