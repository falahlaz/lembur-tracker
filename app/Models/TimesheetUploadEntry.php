<?php

namespace App\Models;

use App\Enums\UploadEntryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu sel workbook: apa yang akan dikirim, dan apa yang terjadi padanya. */
#[Fillable([
    'timesheet_upload_id', 'user_id', 'sheet', 'cell_ref', 'slot_label', 'work_date',
    'begin_at', 'end_at', 'duration_minutes', 'activity_id', 'activity_name', 'description', 'tag',
    'status', 'skip_reason', 'overridable', 'conflicting_kimai_id',
])]
class TimesheetUploadEntry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => UploadEntryStatus::class,
            'work_date' => 'immutable_date:Y-m-d',
            'begin_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'posted_at' => 'datetime',
            'duration_minutes' => 'integer',
            'activity_id' => 'integer',
            'conflicting_kimai_id' => 'integer',
            'kimai_timesheet_id' => 'integer',
            'overridable' => 'boolean',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(TimesheetUpload::class, 'timesheet_upload_id');
    }

    /**
     * Body POST Kimai. Aturannya sama persis dengan ParsedEntry::toKimaiPayload()
     * dan sengaja diulang di sini, bukan dibagi: yang satu bekerja atas hasil
     * parsing, yang satu atas baris database, dan menyatukannya lewat DTO perantara
     * hanya menambah lapisan tanpa menghilangkan satu pun aturannya.
     *
     * - Hanya lima field; `billable` ditolak "This form should not contain extra fields".
     * - `tags` HILANG kalau tidak ada tag; `tags: []` ditolak "This value is not valid."
     * - `tags` STRING dipisah koma, bukan array.
     * - Offset ditulis "+0700"; toIso8601String() memberi "+07:00" dan tidak dipakai.
     *
     * @return array{begin: string, end: string, project: int, activity: int, description: string, tags?: string}
     */
    public function toKimaiPayload(int $projectId): array
    {
        $zone = config('kimai.timezone');

        $payload = [
            'begin' => $this->begin_at->setTimezone($zone)->format('Y-m-d\TH:i:sO'),
            'end' => $this->end_at->setTimezone($zone)->format('Y-m-d\TH:i:sO'),
            'project' => $projectId,
            'activity' => (int) $this->activity_id,
            'description' => (string) $this->description,
        ];

        if (filled($this->tag)) {
            $payload['tags'] = $this->tag;
        }

        return $payload;
    }

    /** "18 Agu · 22:00–00:00" untuk pratinjau. */
    public function slotRange(): string
    {
        $zone = config('kimai.timezone');

        return $this->begin_at->setTimezone($zone)->format('H:i')
            .'–'.$this->end_at->setTimezone($zone)->format('H:i');
    }
}
