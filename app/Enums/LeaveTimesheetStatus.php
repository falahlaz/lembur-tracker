<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * CT-02 — nasib satu slot cuti terhadap Kimai.
 *
 * Sengaja TIDAK memakai ulang UploadEntryStatus meskipun tiga namanya sama.
 * Upload workbook mengenal `skipped` (sel yang diputuskan tidak dikirim) tetapi
 * tidak mengenal `deleted`, dan cuti justru kebalikannya: tidak ada yang pernah
 * dilewati, tetapi entri yang sudah masuk bisa ditarik kembali saat klaimnya
 * dibatalkan. Menumpangkan keduanya pada satu enum berarti setiap pembacanya
 * harus tahu case mana yang mustahil di konteksnya.
 */
enum LeaveTimesheetStatus: string implements HasColor, HasLabel
{
    /** Direncanakan, belum pernah menyentuh Kimai — satu-satunya yang diambil pengirim. */
    case Pending = 'pending';

    /** Ada di Kimai; `kimai_timesheet_id` terisi. */
    case Posted = 'posted';

    /** Kimai menolak entri INI (4xx validasi). Tidak ada yang terbentuk di sana. */
    case Failed = 'failed';

    /** Pernah ada di Kimai, sudah ditarik kembali. */
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Belum terkirim',
            self::Posted => 'Terkirim',
            self::Failed => 'Gagal',
            self::Deleted => 'Dihapus',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Posted => 'success',
            self::Failed => 'danger',
            self::Deleted => 'warning',
        };
    }

    /** Baris yang masih memegang entri sungguhan di Kimai — hanya ini yang layak dihapus. */
    public function holdsKimaiEntry(): bool
    {
        return $this === self::Posted;
    }
}
