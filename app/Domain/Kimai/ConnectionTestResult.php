<?php

namespace App\Domain\Kimai;

/** F-11 — hasil tes koneksi, dengan pesan yang sudah dibedakan per penyebab. */
final readonly class ConnectionTestResult
{
    private function __construct(
        public bool $ok,
        public string $message,
    ) {}

    public static function success(): self
    {
        return new self(true, 'Koneksi Kimai berhasil.');
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
