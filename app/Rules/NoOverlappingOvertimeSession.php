<?php

namespace App\Rules;

use App\Domain\Lembur\DurationCalculator;
use App\Models\OvertimeRecord;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Date;

/**
 * F-02 — sistem menolak dua record lembur dengan rentang jam yang tumpang tindih
 * pada tanggal yang sama. Tanpa ini, satu sesi bisa dicatat dua kali dan total
 * harian (BR-02) menggelembung tanpa dasar.
 */
class NoOverlappingOvertimeSession implements ValidationRule
{
    public function __construct(
        private readonly ?int $userId,
        private readonly ?string $date,
        private readonly ?string $startTime,
        private readonly ?int $ignoreRecordId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($this->userId) || blank($this->date) || blank($this->startTime) || blank($value)) {
            return;
        }

        $clash = OvertimeRecord::query()
            ->where('user_id', $this->userId)
            ->whereDate('overtime_date', Date::parse($this->date)->toDateString())
            ->when($this->ignoreRecordId, fn ($q) => $q->whereKeyNot($this->ignoreRecordId))
            ->get()
            ->first(fn (OvertimeRecord $r) => DurationCalculator::overlaps(
                $this->startTime,
                (string) $value,
                (string) $r->start_time,
                (string) $r->end_time,
            ));

        if ($clash !== null) {
            $fail(sprintf(
                'Jamnya bertabrakan dengan lembur yang sudah dicatat (%s–%s). Ubah jamnya, atau edit catatan yang itu.',
                substr((string) $clash->start_time, 0, 5),
                substr((string) $clash->end_time, 0, 5),
            ));
        }
    }
}
