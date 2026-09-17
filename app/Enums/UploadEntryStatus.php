<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Nasib satu sel workbook setelah upload dijalankan. */
enum UploadEntryStatus: string implements HasColor, HasLabel
{
    /** Siap dikirim — dan satu-satunya status yang pernah diambil pengirim. */
    case Pending = 'pending';
    /** Sistem memutuskan tidak mengirim: sudah ada di Kimai, atau bentrok. */
    case Skipped = 'skipped';
    case Posted = 'posted';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Akan dikirim',
            self::Skipped => 'Dilewati',
            self::Posted => 'Terkirim',
            self::Failed => 'Gagal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Skipped => 'warning',
            self::Posted => 'success',
            self::Failed => 'danger',
        };
    }
}
