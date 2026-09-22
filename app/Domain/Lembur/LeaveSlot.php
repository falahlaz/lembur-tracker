<?php

namespace App\Domain\Lembur;

use Carbon\CarbonImmutable;

/**
 * Satu potong jam kerja yang akan dicatat sebagai cuti — "09:00–11:00".
 *
 * Disimpan sebagai MENIT SEJAK TENGAH MALAM, bukan sebagai jam bulat, supaya
 * blok yang dipotong di tengah (lihat LeaveDayPlanner) tidak perlu bentuk kedua.
 */
final readonly class LeaveSlot
{
    private function __construct(
        /** 0–1440, waktu dinding di zona Kimai. */
        public int $startMinute,
        public int $endMinute,
    ) {}

    /** LeaveSlot::fromClock('09:00', '11:00') */
    public static function fromClock(string $start, string $end): self
    {
        return new self(self::toMinutes($start), self::toMinutes($end));
    }

    /** Salinan yang berhenti lebih awal; dipakai saat menit tersisa lebih pendek dari bloknya. */
    public function endingAt(int $endMinute): self
    {
        return new self($this->startMinute, $endMinute);
    }

    public function durationMinutes(): int
    {
        return $this->endMinute - $this->startMinute;
    }

    /** Awal slot sebagai instan absolut di zona Kimai. */
    public function beginAt(CarbonImmutable $date): CarbonImmutable
    {
        return $this->anchor($date)->addMinutes($this->startMinute);
    }

    public function endAt(CarbonImmutable $date): CarbonImmutable
    {
        return $this->anchor($date)->addMinutes($this->endMinute);
    }

    /** "09:00–11:00" */
    public function label(): string
    {
        return self::toClock($this->startMinute).'–'.self::toClock($this->endMinute);
    }

    /**
     * Tengah malam tanggal itu DI ZONA KIMAI — disalin dari SlotLabel::anchor()
     * karena jebakannya juga sama persis: menyusunnya di zona aplikasi (UTC)
     * menggeser seluruh entri tujuh jam tanpa ada yang menyadarinya sampai
     * timesheetnya terlanjur masuk.
     */
    private function anchor(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(config('kimai.timezone'))->startOfDay();
    }

    private static function toMinutes(string $clock): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return $hour * 60 + $minute;
    }

    private static function toClock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
