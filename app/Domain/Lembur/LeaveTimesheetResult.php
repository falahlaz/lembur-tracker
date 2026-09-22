<?php

namespace App\Domain\Lembur;

/**
 * Hasil satu kali sinkronisasi timesheet cuti, dalam bentuk yang sudah siap
 * ditempel ke notifikasi Filament.
 *
 * Kalimatnya disusun di sini, bukan di halaman, supaya CreateLeaveClaim dan
 * EditLeaveClaim tidak punya dua versi teks yang bisa berbeda seiring waktu.
 */
final readonly class LeaveTimesheetResult
{
    private function __construct(
        public int $posted,
        public int $deleted,
        public int $pending,
        public int $failed,
        /** Alasan yang menghentikan segalanya, kalau ada. Sudah berbahasa manusia. */
        public ?string $blocker = null,
        /** "09:00–18:00" — hanya terisi kalau ada yang benar-benar terkirim. */
        public ?string $range = null,
        public bool $skipped = false,
    ) {}

    /** Tidak ada yang perlu dilakukan: user ini memang tidak memakai Kimai. */
    public static function skipped(): self
    {
        return new self(0, 0, 0, 0, skipped: true);
    }

    /** Berhenti sebelum satu entri pun disentuh — activity tidak ketemu, token tidak ada. */
    public static function blocked(string $reason): self
    {
        return new self(0, 0, 0, 0, blocker: $reason);
    }

    public static function make(
        int $posted,
        int $deleted,
        int $pending,
        int $failed,
        ?string $blocker = null,
        ?string $range = null,
    ): self {
        return new self($posted, $deleted, $pending, $failed, $blocker, $range);
    }

    public function isClean(): bool
    {
        return $this->blocker === null && $this->pending === 0 && $this->failed === 0;
    }

    /** Ada sesuatu yang layak disebut ke user sama sekali? */
    public function isNoop(): bool
    {
        return $this->skipped
            || ($this->blocker === null
                && $this->posted === 0
                && $this->deleted === 0
                && $this->pending === 0
                && $this->failed === 0);
    }

    /**
     * Satu kalimat untuk ditempel di badan notifikasi. Null berarti tidak ada
     * yang perlu dikatakan — user tanpa koneksi Kimai tidak semestinya membaca
     * soal Kimai sama sekali.
     */
    public function pesan(): ?string
    {
        if ($this->isNoop()) {
            return null;
        }

        $bagian = [];

        if ($this->posted > 0) {
            $bagian[] = $this->range === null
                ? "{$this->posted} timesheet cuti dibuat di Kimai."
                : "{$this->posted} timesheet cuti dibuat di Kimai ({$this->range}).";
        }

        if ($this->deleted > 0) {
            $bagian[] = "{$this->deleted} timesheet cuti dihapus dari Kimai.";
        }

        if ($this->failed > 0) {
            $bagian[] = "{$this->failed} slot ditolak Kimai.";
        }

        if ($this->blocker !== null) {
            $bagian[] = $this->blocker;
        } elseif ($this->pending > 0) {
            $bagian[] = "{$this->pending} slot belum terkirim — bisa dikirim ulang dari daftar klaim.";
        }

        return $bagian === [] ? null : implode(' ', $bagian);
    }
}
