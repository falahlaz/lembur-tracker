<?php

namespace App\Domain\Lembur;

use App\Enums\Tier;
use App\Support\Format;
use Carbon\CarbonInterface;

/**
 * Hasil hitungan hak untuk SATU TANGGAL. Dipakai dua tempat:
 * preview live di form (P-3) dan penulisan saat simpan — sumbernya sama.
 */
final readonly class EntitlementPreview
{
    public function __construct(
        public int $rawMinutes,
        public int $effectiveMinutes,
        public bool $roundingApplied,
        public int $dailyTotalMinutes,
        public Tier $tier,
        public int $mealAmount,
        public int $leaveMinutes,
        public ?CarbonInterface $expiresAt,
        public string $payrollLabel,
        public bool $pastCutoff,
        public bool $crossesMidnight,
    ) {}

    public function qualifies(): bool
    {
        return $this->tier !== Tier::None;
    }

    /**
     * Design Brief §4.2 — nada untuk durasi < 4 jam bersifat netral-informatif,
     * bukan error. User tidak melakukan kesalahan; mereka belum memenuhi ambang.
     */
    public function shortfallMessage(): ?string
    {
        if ($this->qualifies()) {
            return null;
        }

        return 'Durasi '.Format::durasi($this->dailyTotalMinutes)
            .' — belum mencapai minimal 4 jam, jadi belum ada uang makan atau cuti pengganti.'
            .' Catatannya tetap bisa disimpan.';
    }

    /** Pembulatan tidak boleh terjadi diam-diam (Design Brief §4.2). */
    public function roundingMessage(): ?string
    {
        if (! $this->roundingApplied) {
            return null;
        }

        return Format::durasi($this->rawMinutes).' dibulatkan jadi '
            .Format::durasi($this->effectiveMinutes).' sesuai preferensi kamu';
    }

    public function leaveHumanised(): string
    {
        return Format::saldoManusiawi($this->leaveMinutes);
    }
}
