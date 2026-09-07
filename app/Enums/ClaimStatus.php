<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * BR-20 — siklus status klaim:
 *   draft → submitted → approved → taken
 *                     ↘ rejected
 *                     ↘ cancelled
 */
enum ClaimStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Taken = 'taken';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Taken => 'Diambil',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::Approved, self::Taken => 'success',
            self::Rejected, self::Cancelled => 'danger',
        };
    }

    /**
     * BR-20 — sejak `submitted`, saldo DITAHAN supaya tidak terpakai dua kali.
     * `taken` sudah dipotong permanen, jadi tidak lagi menahan.
     */
    public function holdsBalance(): bool
    {
        return in_array($this, [self::Submitted, self::Approved], true);
    }

    /** BR-21 — klaim aktif tidak boleh tumpang tindih tanggal. */
    public function isActive(): bool
    {
        return in_array($this, [self::Submitted, self::Approved, self::Taken], true);
    }

    /** BR-19 — hanya klaim aktif yang memakan kuota bulanan. */
    public function consumesQuota(): bool
    {
        return $this->isActive();
    }

    /** BR-20 — melepas hold, saldo kembali ke batch asal tanpa mengubah expires_at. */
    public function releasesBalance(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }
}
