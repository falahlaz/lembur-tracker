<?php

namespace App\Models;

use App\Enums\SyncAction;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F-13 — satu baris per sync, lengkap dengan rentang dan cacah hasilnya. */
#[Fillable(['user_id', 'trigger', 'status', 'range_start', 'range_end'])]
class SyncRun extends Model
{
    use HasFactory;

    /** F-13 — riwayat menyimpan 30 run terakhir per user. */
    public const KEEP_PER_USER = 30;

    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncStatus::class,
            'range_start' => 'datetime',
            'range_end' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'count_fetched' => 'integer',
            'count_created' => 'integer',
            'count_updated' => 'integer',
            'count_skipped' => 'integer',
            'count_failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class);
    }

    /** SY-18 — run yang masih hidup mengunci tombol sync. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value]);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** F-13 — "Sync selesai · 4 lembur baru, 1 diperbarui, 2 dilewati". */
    public function summary(): string
    {
        if ($this->status === SyncStatus::Failed) {
            return $this->error_message ?: 'Sync gagal.';
        }

        $parts = [];

        if ($this->count_created > 0) {
            $parts[] = "{$this->count_created} lembur baru";
        }

        if ($this->count_updated > 0) {
            $parts[] = "{$this->count_updated} diperbarui";
        }

        if ($this->count_skipped > 0) {
            $parts[] = "{$this->count_skipped} dilewati";
        }

        if ($this->count_failed > 0) {
            $parts[] = "{$this->count_failed} gagal";
        }

        if ($parts === []) {
            // F-13 — nol perubahan tetap dijelaskan dengan tanggalnya, bukan
            // sekadar "tidak ada perubahan".
            return 'Tidak ada lembur baru di Kimai sejak '
                .$this->range_start->locale('id')->translatedFormat('j F').'.';
        }

        return 'Sync selesai · '.implode(', ', $parts);
    }

    /**
     * F-13 — hanya menyimpan KEEP_PER_USER run terakhir. Dipanggil di akhir setiap
     * run, bukan lewat job terjadwal, supaya tabelnya tidak pernah menumpuk.
     */
    public static function pruneFor(int $userId): void
    {
        $cutoff = static::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(self::KEEP_PER_USER - 1)
            ->take(1)
            ->value('id');

        if ($cutoff === null) {
            return;
        }

        static::query()->where('user_id', $userId)->where('id', '<', $cutoff)->delete();
    }

    /** @return array<string, int> cacah per tindakan, untuk menutup run. */
    public function tally(): array
    {
        return $this->items()
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action')
            ->mapWithKeys(fn (int $total, string $action) => [SyncAction::from($action)->value => $total])
            ->all();
    }
}
