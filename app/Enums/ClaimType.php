<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** BR-18 — dua bentuk klaim yang dikenali sistem. */
enum ClaimType: string implements HasLabel
{
    case FullDay = 'full_day';
    case LateArrival = 'late_arrival';

    public function getLabel(): string
    {
        return match ($this) {
            self::FullDay => 'Libur 1 hari penuh',
            self::LateArrival => 'Datang lebih siang',
        };
    }

    /** Teks radio pada form klaim (Design Brief §4.4). */
    public function getDescription(): string
    {
        return match ($this) {
            self::FullDay => 'Libur 1 hari penuh — pakai 8 jam',
            self::LateArrival => 'Datang lebih siang — pakai 4 jam',
        };
    }

    /** BR-19 — bobot terhadap kuota 3 hari per bulan kalender. */
    public function quotaWeight(): float
    {
        return match ($this) {
            self::FullDay => 1.0,
            self::LateArrival => 0.5,
        };
    }

    public function requiresArrivalTime(): bool
    {
        return $this === self::LateArrival;
    }
}
