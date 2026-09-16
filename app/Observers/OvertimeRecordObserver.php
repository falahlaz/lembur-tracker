<?php

namespace App\Observers;

use App\Domain\Kimai\SessionWriter;
use App\Domain\Lembur\DurationCalculator;
use App\Domain\Lembur\OvertimeDayCalculator;
use App\Domain\Lembur\PayrollPeriodResolver;
use App\Models\OvertimeRecord;
use Illuminate\Support\Facades\Date;

/**
 * BR-02/BR-07 — hak melekat pada tanggal, jadi setiap tulisan ke satu record
 * memicu hitung ulang SELURUH tanggal itu. Bila tanggal lembur berubah, dua
 * tanggal harus dihitung ulang: yang ditinggalkan dan yang dituju — kalau tidak,
 * tanggal lama akan menyimpan hak yang sudah tidak punya dasar.
 *
 * Kalkulator menulis lewat saveQuietly() dan menjaga flag statisnya sendiri,
 * sehingga observer ini tidak memanggil dirinya sendiri.
 */
class OvertimeRecordObserver
{
    public function __construct(
        private readonly OvertimeDayCalculator $calculator,
        private readonly PayrollPeriodResolver $periods,
    ) {}

    /**
     * Durasi record dihitung SEBELUM insert. Nilainya hanya bergantung pada jam
     * mulai/selesai dan preferensi user, jadi tidak butuh baris lain — dan dengan
     * begini kolom NOT NULL tidak pernah sempat terisi angka bohongan.
     * Agregasi per-tanggal (tier, uang makan, saldo) tetap di `saved`, karena
     * ia memang perlu record ini sudah ada di tabel.
     */
    public function saving(OvertimeRecord $record): void
    {
        if (OvertimeDayCalculator::isRecalculating()) {
            return;
        }

        // SY-14 — begitu user menyimpan perubahan apa pun pada record bersumber
        // Kimai, record itu dikunci dari sync selamanya. Ditentukan SEBELUM durasi
        // dihitung, karena rawMinutes() membaca flag ini.
        $this->markLocallyModified($record);
        $this->clearEvidenceFlag($record);

        $raw = $record->rawMinutes();
        $rounding = (bool) ($record->user?->rounding_enabled ?? false);

        $record->duration_raw_minutes = $raw;
        $record->duration_effective_minutes = DurationCalculator::effectiveMinutes($raw, $rounding);
        $record->rounding_applied = $record->duration_effective_minutes !== $raw;

        // Versi aturan (BR-24) dan periode payroll (BR-09) ditentukan oleh tanggal
        // lembur saja, jadi keduanya juga bisa dikunci sebelum baris ditulis.
        $date = Date::parse($record->overtime_date)->startOfDay();
        $rule = $this->calculator->ruleFor($date);

        $record->rule_version_id = $rule->id;
        $record->payroll_period_id = $this->periods->resolve($date, $rule)->id;
    }

    /**
     * SY-12 — badge "Lengkapi evidence" padam begitu user benar-benar mengganti
     * URL-nya. Diikatkan pada perubahan nilai, bukan pada aksi menyimpan, supaya
     * menyimpan form tanpa menyentuh evidence tidak diam-diam membuka kunci status.
     */
    private function clearEvidenceFlag(OvertimeRecord $record): void
    {
        if (! $record->exists || ! $record->evidence_needs_review) {
            return;
        }

        if (SessionWriter::isWriting()) {
            return;
        }

        if ($record->isDirty('evidence_url') && filled($record->evidence_url)) {
            $record->evidence_needs_review = false;
        }
    }

    /**
     * SY-14 — hanya menyala untuk perubahan yang datang dari manusia. Sync sendiri
     * menulis lewat guard SessionWriter::isWriting(), jadi pembaruan rutin
     * dari Kimai tidak mengunci recordnya sendiri.
     *
     * Sekali menyala tidak pernah padam: tidak ada cabang yang mengembalikannya
     * ke false.
     */
    private function markLocallyModified(OvertimeRecord $record): void
    {
        if (! $record->exists || ! $record->isFromKimai() || $record->locally_modified) {
            return;
        }

        if (SessionWriter::isWriting()) {
            return;
        }

        $touched = array_keys($record->getDirty());
        $human = array_diff($touched, ['updated_at', 'synced_at', 'locally_modified']);

        if ($human !== []) {
            $record->locally_modified = true;
        }
    }

    public function saved(OvertimeRecord $record): void
    {
        // `saved` menyala sebelum syncOriginal(), jadi nilai lama masih terbaca.
        $previous = $record->getOriginal('overtime_date');

        $this->recalculate($record, $previous);
    }

    public function deleted(OvertimeRecord $record): void
    {
        $this->recalculate($record);
    }

    public function restored(OvertimeRecord $record): void
    {
        $this->recalculate($record);
    }

    public function forceDeleted(OvertimeRecord $record): void
    {
        $this->recalculate($record);
    }

    private function recalculate(OvertimeRecord $record, mixed $previousDate = null): void
    {
        if (OvertimeDayCalculator::isRecalculating()) {
            return;
        }

        $user = $record->user;

        if ($user === null) {
            return;
        }

        $current = $record->overtime_date;

        if ($previousDate !== null) {
            $previous = Date::parse($previousDate)->startOfDay();

            if (! $previous->isSameDay($current)) {
                $this->calculator->recalculate($user, $previous);
            }
        }

        $this->calculator->recalculate($user, Date::parse($current)->startOfDay());
    }
}
