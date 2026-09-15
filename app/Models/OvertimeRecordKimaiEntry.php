<?php

namespace App\Models;

use App\Domain\Kimai\KimaiTimesheet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SY-23 — satu entri timesheet Kimai yang menjadi anggota sebuah record lembur.
 *
 * Baris inilah yang memegang dedup SY-13, dan yang membuat sesi separuh bisa
 * dilanjutkan pada sync berikutnya: anggota yang sudah tersimpan tetap terbaca
 * meskipun tidak ikut ditarik lagi.
 */
#[Fillable([
    'overtime_record_id', 'user_id', 'kimai_timesheet_id',
    'begin', 'end', 'duration_minutes', 'break_minutes', 'description',
])]
class OvertimeRecordKimaiEntry extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'begin' => 'immutable_datetime',
            'end' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'break_minutes' => 'integer',
        ];
    }

    public function overtimeRecord(): BelongsTo
    {
        return $this->belongsTo(OvertimeRecord::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mengembalikan anggota ke bentuk yang dimengerti SessionGrouper dan
     * OvertimeSession, sehingga rekonsiliasi memakai satu tipe saja — entri yang
     * baru ditarik dan entri yang sudah tersimpan diperlakukan identik.
     *
     * Jamnya dibangun ulang DI ZONA KIMAI, bukan zona aplikasi. Kolom datetime
     * dibaca kembali berlabel zona aplikasi (UTC di test), sedangkan entri yang baru
     * ditarik berlabel +0700 — jam dindingnya sama, instannya beda tujuh jam. Kalau
     * keduanya dicampur apa adanya, pengurutan anggota sesi jadi terbalik dan record
     * gabungan berakhir dengan jam mulai yang sama dengan jam selesainya.
     *
     * `tags` sengaja kosong: penyaringan tag (SY-05) sudah terjadi saat baris ini
     * pertama kali dibuat, dan mengulangnya di sini justru akan membuang anggota
     * yang sah hanya karena tag-nya berubah di Kimai.
     */
    public function toTimesheet(): KimaiTimesheet
    {
        $zone = (string) config('kimai.timezone');

        return new KimaiTimesheet(
            id: (int) $this->kimai_timesheet_id,
            begin: CarbonImmutable::parse($this->begin->format('Y-m-d H:i:s'), $zone),
            end: CarbonImmutable::parse($this->end->format('Y-m-d H:i:s'), $zone),
            durationSeconds: (int) $this->duration_minutes * 60,
            breakSeconds: (int) $this->break_minutes * 60,
            description: (string) ($this->description ?? ''),
            projectId: $this->overtimeRecord?->kimai_project_id,
            activityId: $this->overtimeRecord?->kimai_activity_id,
            tags: [],
        );
    }
}
