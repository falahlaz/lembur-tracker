<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * F-13/F-14 — run manual dan run terjadwal dibedakan di Riwayat Sync. `Range`
 * adalah run manual dengan rentang pilihan user, yang tidak menggeser watermark.
 */
enum SyncTrigger: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Range = 'range';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Otomatis',
            self::Range => 'Rentang',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Scheduled => 'info',
            self::Range => 'warning',
        };
    }
}
