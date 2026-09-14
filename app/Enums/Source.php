<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** PRD Sync Kimai SY-09 — asal-usul record, dipakai SY-10 dan SY-14. */
enum Source: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Kimai = 'kimai';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Kimai => 'Kimai',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Kimai => 'info',
        };
    }
}
