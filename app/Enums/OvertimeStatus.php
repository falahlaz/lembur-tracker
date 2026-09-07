<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** PRD F-02. Nilai DB berbahasa Inggris, label UI berbahasa Indonesia (Design Brief §8). */
enum OvertimeStatus: string implements HasColor, HasLabel
{
    case Recorded = 'recorded';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recorded => 'Dicatat',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
        };
    }

    /** Peta warna Design Brief §5.1 — satu warna, satu makna. */
    public function getColor(): string
    {
        return match ($this) {
            self::Recorded => 'gray',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** BR-10 — masuk hitungan "estimasi maksimal" uang makan. */
    public function countsTowardEstimate(): bool
    {
        return $this !== self::Rejected;
    }

    /** BR-10 — masuk hitungan "sudah pasti". */
    public function isCertain(): bool
    {
        return $this === self::Approved;
    }

    /** BR-23 — status ini membatalkan hak, memicu peninjauan batch yang sudah terpakai. */
    public function grantsEntitlement(): bool
    {
        return $this !== self::Rejected;
    }
}
