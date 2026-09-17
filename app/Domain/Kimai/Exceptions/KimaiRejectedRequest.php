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
        /**
         * Pesan validasi dari Kimai, kalau ada. Diisi hanya untuk POST: di sana
         * 400 berarti satu entri ditolak dengan alasan tertentu ("This form should
         * not contain extra fields"), dan tanpa alasan itu laporan kegagalannya
         * tidak bisa ditindaklanjuti siapa pun. Isinya dipilih di KimaiClient dan
         * hanya berupa teks statis dari server — tidak pernah memuat token, header,
         * maupun query.
         */
        private readonly ?string $detail = null,
    ) {
        parent::__construct(
            "Kimai menolak permintaan (HTTP {$status}): {$safeUrl}"
            .($detail !== null ? " — {$detail}" : '')
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function userMessage(): string
    {
        if ($this->detail !== null) {
            return "Kimai menolak entri: {$this->detail}";
        }

        return $this->status === 404
            ? 'Endpoint API tidak ditemukan. Hubungi admin.'
            : 'Kimai menolak permintaan. Hubungi admin.';
    }
}
