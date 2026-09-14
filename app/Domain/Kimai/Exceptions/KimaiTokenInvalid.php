<?php

namespace App\Domain\Kimai\Exceptions;

/** SY-22 — 401/403. Tidak pernah di-retry; auto-sync dimatikan. */
class KimaiTokenInvalid extends KimaiException
{
    public function userMessage(): string
    {
        return 'Koneksi Kimai terputus — API key kamu sudah tidak berlaku. '
            .'Buat token baru di Kimai dan masukkan lagi di Pengaturan.';
    }
}
