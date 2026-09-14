<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** PRD Sync Kimai §7 — status satu run sync. */
enum SyncStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    /** §10 — sebagian entri gagal, tetapi sisanya tetap masuk (SY-19). */
    case Partial = 'partial';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Menunggu',
            self::Running => 'Berjalan',
            self::Success => 'Selesai',
            self::Partial => 'Selesai sebagian',
            self::Failed => 'Gagal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Running => 'info',
            self::Success => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
        };
    }

    /** SY-18 — selama run masih hidup, tombol sync dikunci. */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }
}
