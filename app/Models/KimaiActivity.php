<?php

namespace App\Models;

use App\Domain\Kimai\KimaiCatalogSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu activity Kimai di cermin lokal. Diisi hanya oleh sync katalog.
 *
 * @see KimaiCatalogSync
 */
#[Fillable(['kimai_id', 'name', 'project_id', 'synced_at'])]
class KimaiActivity extends Model
{
    protected function casts(): array
    {
        return [
            'kimai_id' => 'integer',
            'project_id' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(KimaiProject::class, 'project_id', 'kimai_id');
    }

    /** Activity global berlaku di semua project. */
    public function isGlobal(): bool
    {
        return $this->project_id === null;
    }

    /**
     * Yang berlaku di satu project: miliknya sendiri DITAMBAH yang global.
     *
     * Aturan yang sama persis dengan KimaiCatalog::activities() — kalau legenda
     * menyembunyikan yang global, ia justru menyesatkan: activity itu sah ditulis
     * untuk project mana pun.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForProject(Builder $query, int $projectId): void
    {
        $query->where(
            fn (Builder $q) => $q->where('project_id', $projectId)->orWhereNull('project_id'),
        );
    }

    /** Teks siap tempel ke baris pertama sel workbook. */
    public function barisSel(): string
    {
        return "Activity: {$this->name}";
    }

    /**
     * Bentuk yang SAMA PERSIS dengan keluaran KimaiClient::activities(), sehingga
     * ActivityResolver tidak perlu tahu datanya dari jaringan atau dari cermin.
     *
     * @return array{id: int, name: string, project: ?int}
     */
    public function toCatalogRow(): array
    {
        return [
            'id' => (int) $this->kimai_id,
            'name' => (string) $this->name,
            'project' => $this->project_id === null ? null : (int) $this->project_id,
        ];
    }
}
