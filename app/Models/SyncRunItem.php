<?php

namespace App\Models;

use App\Enums\SyncAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F-13 — satu baris per entri Kimai yang diproses, termasuk alasan dilewati. */
#[Fillable([
    'sync_run_id', 'kimai_timesheet_id', 'action', 'reason',
    'overtime_record_id', 'overtime_date', 'duration_minutes',
])]
class SyncRunItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action' => SyncAction::class,
            'overtime_date' => 'immutable_date:Y-m-d',
            'duration_minutes' => 'integer',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function overtimeRecord(): BelongsTo
    {
        return $this->belongsTo(OvertimeRecord::class);
    }
}
