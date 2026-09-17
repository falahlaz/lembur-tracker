<?php

namespace App\Models;

use App\Enums\UploadEntryStatus;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu upload workbook timesheet, dari pratinjau sampai hasil kirimnya. */
#[Fillable([
    'user_id', 'original_filename', 'file_hash', 'customer_id', 'project_id',
    'status', 'range_start', 'range_end', 'count_parsed', 'duplicates_checked', 'issues',
])]
class TimesheetUpload extends Model
{
    use HasFactory;

    /** Riwayat menyimpan 20 upload terakhir per user, seperti SyncRun::KEEP_PER_USER. */
    public const KEEP_PER_USER = 20;

    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'range_start' => 'immutable_date:Y-m-d',
            'range_end' => 'immutable_date:Y-m-d',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'customer_id' => 'integer',
            'project_id' => 'integer',
            'count_parsed' => 'integer',
            'count_skipped' => 'integer',
            'count_posted' => 'integer',
            'count_failed' => 'integer',
            'duplicates_checked' => 'boolean',
            'issues' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetUploadEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [UploadStatus::Queued->value, UploadStatus::Posting->value]);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', UploadStatus::Draft->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** Entri yang masih menunggu giliran — satu-satunya yang pernah diambil pengirim. */
    public function pendingEntries(): HasMany
    {
        return $this->entries()->where('status', UploadEntryStatus::Pending->value);
    }

    public function hasResumableEntries(): bool
    {
        return $this->status->isResumable() && $this->pendingEntries()->exists();
    }

    /** "Upload selesai · 87 entri terkirim, 13 dilewati" — bukan sekadar "berhasil". */
    public function summary(): string
    {
        if ($this->status === UploadStatus::Failed && filled($this->error_message)) {
            return $this->error_message;
        }

        if ($this->status === UploadStatus::Cancelled) {
            return 'Upload dibatalkan.';
        }

        $parts = [];

        if ($this->count_posted > 0) {
            $parts[] = "{$this->count_posted} entri terkirim";
        }

        if ($this->count_skipped > 0) {
            $parts[] = "{$this->count_skipped} dilewati";
        }

        if ($this->count_failed > 0) {
            $parts[] = "{$this->count_failed} gagal";
        }

        if ($parts === []) {
            return 'Tidak ada entri yang dikirim.';
        }

        return 'Upload selesai · '.implode(', ', $parts);
    }

    /** @return array<string, int> cacah per status, untuk menutup upload. */
    public function tally(): array
    {
        return $this->entries()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn (int $total, string $status) => [UploadEntryStatus::from($status)->value => $total])
            ->all();
    }

    /** Menyimpan KEEP_PER_USER upload terakhir. Dipanggil di akhir setiap run, seperti SyncRun. */
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
}
