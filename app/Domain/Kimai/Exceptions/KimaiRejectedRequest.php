<?php

namespace App\Domain\Kimai\Exceptions;

/**
 * §10 — 4xx selain 401/403, termasuk "Bad Request" pada filter tag yang pernah
 * jadi bug di Kimai < 1.7. Tidak di-retry; URL request dicatat di Riwayat Sync
 * supaya admin punya bahan, tanpa pernah menyertakan token.
 */
class KimaiRejectedRequest extends KimaiException
{
    public function __construct(
        private readonly int $status,
        private readonly string $safeUrl,
    ) {
        parent::__construct("Kimai menolak permintaan (HTTP {$status}): {$safeUrl}");
    }

    public function status(): int
    {
        return $this->status;
    }

    public function userMessage(): string
    {
        return $this->status === 404
            ? 'Endpoint API tidak ditemukan. Hubungi admin.'
            : 'Kimai menolak permintaan. Hubungi admin.';
    }
}
