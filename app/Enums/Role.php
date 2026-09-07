<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Admin = 'admin';
    case Employee = 'employee';
    /** Fase 2 (PRD §5) — read-only atas data tim. Sudah ada agar tidak perlu migrasi struktural. */
    case Manager = 'manager';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Employee => 'Karyawan',
            self::Manager => 'Atasan / PM',
        };
    }
}
