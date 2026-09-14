<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** SY-14 — tindakan yang diambil terhadap satu entri timesheet Kimai. */
enum SyncAction: string implements HasColor, HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Unchanged = 'unchanged';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Dibuat',
            self::Updated => 'Diperbarui',
            self::Skipped => 'Dilewati',
            self::Unchanged => 'Tidak berubah',
            self::Failed => 'Gagal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created => 'success',
            self::Updated => 'info',
            self::Skipped => 'warning',
            self::Unchanged => 'gray',
            self::Failed => 'danger',
        };
    }
}
