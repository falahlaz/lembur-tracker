<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** BR-05 — tier ditentukan dari total durasi efektif per TANGGAL, bukan per record. */
enum Tier: int implements HasColor, HasLabel
{
    case None = 0;
    case Tier1 = 1;
    case Tier2 = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Belum 4 jam',
            self::Tier1 => '≥ 4 jam',
            self::Tier2 => '≥ 8 jam',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Tier1, self::Tier2 => 'success',
        };
    }
}
