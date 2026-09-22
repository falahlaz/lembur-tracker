<?php

namespace App\Models;

use App\Enums\LeaveTimesheetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CT-01 — satu slot jam cuti: apa yang akan dikirim, dan apa yang terjadi padanya. */
#[Fillable([
    'leave_claim_id', 'user_id', 'kimai_project_id', 'kimai_activity_id',
    'begin_at', 'end_at', 'duration_minutes', 'status',
])]
class LeaveClaimTimesheet extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => LeaveTimesheetStatus::class,
            'begin_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'posted_at' => 'datetime',
            'discarded_at' => 'datetime',
            'deleted_from_kimai_at' => 'datetime',
            'duration_minutes' => 'integer',
            'kimai_project_id' => 'integer',
            'kimai_activity_id' => 'integer',
            'kimai_timesheet_id' => 'integer',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(LeaveClaim::class, 'leave_claim_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Slot yang masih dikehendaki klaimnya — lawan dari `discarded`. */
    public function scopeKept(Builder $query): Builder
    {
        return $query->whereNull('discarded_at');
    }

    /** Entri yang sudah ada di Kimai tetapi tidak lagi dikehendaki. */
    public function scopeAwaitingDeletion(Builder $query): Builder
    {
        return $query
            ->whereNotNull('discarded_at')
            ->where('status', LeaveTimesheetStatus::Posted->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LeaveTimesheetStatus::Pending->value);
    }

    /**
     * Body POST Kimai.
     *
     * Aturannya sama persis dengan TimesheetUploadEntry::toKimaiPayload() dan
     * sengaja diulang di sini, bukan dibagi: yang satu bekerja atas baris upload
     * workbook, yang satu atas slot cuti, dan menyatukannya lewat DTO perantara
     * hanya menambah lapisan tanpa menghilangkan satu pun aturannya.
     *
     *   - Hanya lima field; `billable` ditolak "This form should not contain
     *     extra fields".
     *   - Offset ditulis "+0700", bukan "+07:00"; toIso8601String() memberi yang
     *     kedua dan tidak bisa dipakai di sini.
     *   - Jam dikembalikan ke zona Kimai dulu. Kolom datetime dibaca berlabel zona
     *     aplikasi (UTC di test), jadi tanpa konversi ini seluruh entri bergeser
     *     tujuh jam.
     *
     * Dan satu aturan yang HANYA berlaku di sini: `tags` tidak pernah ada. Entri
     * bertag `Overtime` justru yang ditarik kembali SyncKimaiTimesheets menjadi
     * catatan lembur, jadi menandai cuti akan melahirkan lembur hantu yang
     * menambah saldo — dari cuti yang justru sedang memakainya.
     *
     * @return array{begin: string, end: string, project: int, activity: int, description: string}
     */
    public function toKimaiPayload(string $description): array
    {
        $zone = config('kimai.timezone');

        return [
            'begin' => $this->begin_at->setTimezone($zone)->format('Y-m-d\TH:i:sO'),
            'end' => $this->end_at->setTimezone($zone)->format('Y-m-d\TH:i:sO'),
            'project' => (int) $this->kimai_project_id,
            'activity' => (int) $this->kimai_activity_id,
            'description' => $description,
        ];
    }

    /** "09:00–11:00" untuk pratinjau dan tabel. */
    public function slotRange(): string
    {
        $zone = config('kimai.timezone');

        return $this->begin_at->setTimezone($zone)->format('H:i')
            .'–'.$this->end_at->setTimezone($zone)->format('H:i');
    }
}
