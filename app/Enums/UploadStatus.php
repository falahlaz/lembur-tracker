<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status satu upload timesheet.
 *
 * Sengaja bukan SyncStatus yang dipakai ulang: upload punya dua keadaan yang
 * tidak dimiliki sync — `draft` (sudah dibaca, belum dikirim) dan `cancelled` —
 * dan melebarkan SyncStatus akan menyeret semantik sync ikut berubah, termasuk
 * scopeActive() yang mengunci tombol sync.
 */
enum UploadStatus: string implements HasColor, HasLabel
{
    /** Sudah dibaca dan dipratinjau; belum satu pun entri dikirim. */
    case Draft = 'draft';
    case Queued = 'queued';
    case Posting = 'posting';
    case Success = 'success';
    /** Sebagian entri gagal, sisanya tetap masuk. */
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Queued => 'Menunggu',
            self::Posting => 'Mengirim',
            self::Success => 'Selesai',
            self::Partial => 'Selesai sebagian',
            self::Failed => 'Gagal',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Queued => 'gray',
            self::Posting => 'info',
            self::Success => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** Selama masih hidup, upload baru ditolak. */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Posting;
    }

    public function isFinished(): bool
    {
        return match ($this) {
            self::Success, self::Partial, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /** Masih ada entri tertinggal yang bisa dilanjutkan. */
    public function isResumable(): bool
    {
        return $this === self::Failed || $this === self::Partial;
    }
}
