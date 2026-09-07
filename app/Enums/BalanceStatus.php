<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** BR-12 / BR-17 — satu batch saldo per record lembur yang memenuhi tier. */
enum BalanceStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case PartiallyUsed = 'partially_used';
    case Used = 'used';
    case Expired = 'expired';
    /** BR-23 — hak dibatalkan setelah saldonya terlanjur dipakai. */
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::PartiallyUsed => 'Terpakai sebagian',
            self::Used => 'Habis terpakai',
            self::Expired => 'Hangus',
            self::Void => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active, self::PartiallyUsed => 'success',
            self::Used => 'gray',
            self::Expired, self::Void => 'danger',
        };
    }

    /** Hanya batch ini yang boleh dialokasikan ke klaim baru (BR-14). */
    public function isAllocatable(): bool
    {
        return in_array($this, [self::Active, self::PartiallyUsed], true);
    }
}
