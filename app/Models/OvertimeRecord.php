<?php

namespace App\Models;

use App\Domain\Kimai\KimaiTimesheet;
use App\Domain\Lembur\DurationCalculator;
use App\Enums\OvertimeStatus;
use App\Enums\Source;
use App\Enums\Tier;
use App\Observers\OvertimeRecordObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ObservedBy(OvertimeRecordObserver::class)]
#[Fillable([
    'user_id', 'rule_version_id', 'payroll_period_id',
    'overtime_date', 'start_time', 'end_time',
    'work_description', 'evidence_url', 'status', 'notes', 'created_by_id',
    // Sync Kimai: referensi asal-usul dan `break` yang ikut menentukan durasi.
    // `kimai_duration_minutes`, `locally_modified`, dan `synced_at` sengaja TIDAK
    // di sini — ketiganya hanya boleh ditulis oleh jalur sync.
    'source', 'kimai_timesheet_id', 'kimai_project_id', 'kimai_activity_id',
    'kimai_group_key', 'break_minutes', 'evidence_needs_review',
])]
class OvertimeRecord extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Kolom hasil hitungan sengaja TIDAK fillable. Satu-satunya yang boleh
     * menulisnya adalah OvertimeDayCalculator, supaya BR-02/BR-07 tidak bisa
     * dilewati lewat mass assignment dari form.
     */
    protected function casts(): array
    {
        return [
            'overtime_date' => 'immutable_date:Y-m-d',
            'rounding_applied' => 'boolean',
            'tier' => Tier::class,
            'status' => OvertimeStatus::class,
            'meal_allowance_amount' => 'integer',
            'leave_credit_minutes' => 'integer',
            'duration_raw_minutes' => 'integer',
            'duration_effective_minutes' => 'integer',
            'source' => Source::class,
            'break_minutes' => 'integer',
            'kimai_duration_minutes' => 'integer',
            'locally_modified' => 'boolean',
            'evidence_needs_review' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'overtime_date', 'start_time', 'end_time', 'status',
                'duration_raw_minutes', 'duration_effective_minutes',
                'tier', 'meal_allowance_amount', 'leave_credit_minutes',
                'work_description', 'evidence_url', 'notes',
                'break_minutes', 'evidence_needs_review', 'locally_modified',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(OvertimeRule::class, 'rule_version_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** BR-07 — paling banyak satu batch per record, dan hanya pada record pemegang hak. */
    public function leaveBalance(): HasOne
    {
        return $this->hasOne(LeaveBalance::class);
    }

    /**
     * SY-23 — entri timesheet Kimai yang melebur jadi record ini. Kimai membatasi satu
     * timesheet maksimal 2 jam, jadi lembur 8 jam punya empat anggota di sini.
     */
    public function kimaiEntries(): HasMany
    {
        return $this->hasMany(OvertimeRecordKimaiEntry::class)->orderBy('begin')->orderBy('kimai_timesheet_id');
    }

    /**
     * SY-10 — SATU-SATUNYA sumber kebenaran durasi mentah, dipakai bersama oleh
     * OvertimeRecordObserver dan OvertimeDayCalculator. Keduanya dulu memanggil
     * DurationCalculator::rawMinutes() sendiri-sendiri; begitu durasi Kimai masuk,
     * pemanggil kedua akan menimpa yang pertama tanpa suara.
     *
     * `duration` dari Kimai sudah bersih dari `break`, sehingga sengaja berbeda
     * dari selisih jam: 19:00–23:00 dengan istirahat 30 menit menghasilkan 210
     * menit, bukan 240. Itu bukan bug (SY-10).
     */
    public function rawMinutes(): int
    {
        if ($this->source === Source::Kimai
            && ! $this->locally_modified
            && $this->kimai_duration_minutes !== null) {
            return $this->kimai_duration_minutes;
        }

        $clock = DurationCalculator::rawMinutes(
            (string) $this->start_time,
            (string) $this->end_time,
        );

        // Record Kimai yang sudah diedit user tetap memotong break, supaya durasinya
        // tidak melompat naik 30 menit hanya karena user membetulkan deskripsi.
        return max(0, $clock - (int) ($this->break_minutes ?? 0));
    }

    /**
     * SY-23 — record ini dikembalikan ke bentuk entri Kimai, supaya bisa
     * dikelompokkan ulang oleh SessionGrouper tanpa menghubungi Kimai sama sekali.
     * Dipakai `lemburku:kimai:regroup` untuk membereskan record warisan sync 1:1
     * yang sudah di luar jangkauan fetch.
     *
     * @return array<int, KimaiTimesheet>
     */
    public function toKimaiTimesheets(): array
    {
        if ($this->kimaiEntries->isNotEmpty()) {
            return $this->kimaiEntries->map(fn ($entry) => $entry->toTimesheet())->all();
        }

        if (! $this->isFromKimai() || $this->kimai_timesheet_id === null) {
            return [];
        }

        // Zona Kimai, bukan zona aplikasi — lihat alasannya di
        // OvertimeRecordKimaiEntry::toTimesheet().
        $begin = CarbonImmutable::parse(
            $this->overtime_date->toDateString().' '.$this->start_time,
            (string) config('kimai.timezone'),
        );

        // BR-03 — jam selesai yang lebih kecil berarti lembur ini melewati tengah
        // malam, dan justru entri seperti inilah yang paling perlu dikelompokkan ulang.
        $clock = DurationCalculator::rawMinutes((string) $this->start_time, (string) $this->end_time);

        return [new KimaiTimesheet(
            id: (int) $this->kimai_timesheet_id,
            begin: $begin,
            end: $begin->addMinutes($clock),
            durationSeconds: $this->rawMinutes() * 60,
            breakSeconds: (int) ($this->break_minutes ?? 0) * 60,
            description: (string) $this->work_description,
            projectId: $this->kimai_project_id,
            activityId: $this->kimai_activity_id,
            tags: [],
        )];
    }

    /** SY-12 — selama evidence masih placeholder, record tidak boleh diajukan. */
    public function needsEvidenceReview(): bool
    {
        return (bool) $this->evidence_needs_review;
    }

    public function isFromKimai(): bool
    {
        return $this->source === Source::Kimai;
    }

    /** OQ-2 — dicatat admin atas nama user lain. */
    public function wasCreatedOnBehalf(): bool
    {
        return $this->created_by_id !== null && $this->created_by_id !== $this->user_id;
    }

    public function scopeForDate(Builder $query, int $userId, mixed $date): Builder
    {
        return $query->where('user_id', $userId)->whereDate('overtime_date', $date);
    }

    public function scopeCountsTowardEstimate(Builder $query): Builder
    {
        return $query->where('status', '!=', OvertimeStatus::Rejected->value);
    }

    /**
     * SY-23 — record yang memegang salah satu entri timesheet ini.
     *
     * Cabang kedua menangkap record warisan sync 1:1, yang lahir sebelum tabel
     * penghubung ada dan karena itu hanya punya `kimai_timesheet_id` skalar. Tanpa
     * cabang itu, setiap record lama akan terlihat seperti catatan asing yang
     * membentrok sesinya sendiri, dan sesi itu tidak akan pernah terimpor.
     *
     * @param  array<int, int>  $timesheetIds
     */
    public function scopeOwningKimaiEntries(Builder $query, array $timesheetIds): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereHas('kimaiEntries', fn ($e) => $e->whereIn('kimai_timesheet_id', $timesheetIds))
            ->orWhere(fn (Builder $legacy) => $legacy
                ->where('source', Source::Kimai->value)
                ->doesntHave('kimaiEntries')
                ->whereIn('kimai_timesheet_id', $timesheetIds)));
    }
}
