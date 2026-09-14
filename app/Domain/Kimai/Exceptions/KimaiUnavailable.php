<?php

namespace App\Domain\Kimai\Exceptions;

/** SY-20 — 5xx, timeout, atau DNS gagal. Boleh di-retry. */
class KimaiUnavailable extends KimaiException
{
    public function userMessage(): string
    {
        return 'Tidak bisa menghubungi Kimai. Cek koneksi jaringan atau VPN kamu.';
    }
}
