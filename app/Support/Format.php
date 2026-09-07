<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Design Brief §5.3 + §10 — satu sumber kebenaran untuk format angka, durasi,
 * saldo dan tanggal. Tidak boleh ada format rupiah/durasi yang ditulis ulang
 * di Blade; kalau ada dua tempat, cepat atau lambat keduanya berbeda.
 */
final class Format
{
    public const MINUTES_PER_FULL_DAY = 480;
    public const MINUTES_PER_HALF_DAY = 240;

    /** "Rp50.000" — titik ribuan, tanpa desimal. */
    public static function rupiah(int $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    /** "4 jam 30 menit" — kata penuh, untuk teks mengalir. */
    public static function durasi(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return match (true) {
            $hours > 0 && $mins > 0 => "{$hours} jam {$mins} menit",
            $hours > 0 => "{$hours} jam",
            default => "{$mins} menit",
        };
    }

    /** "4j 30m" — ringkas, untuk kolom tabel. */
    public static function durasiRingkas(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return match (true) {
            $hours > 0 && $mins > 0 => "{$hours}j {$mins}m",
            $hours > 0 => "{$hours}j",
            default => "{$mins}m",
        };
    }

    /**
     * P-2 — jam mentah diterjemahkan ke bahasa manusia.
     * 720 menit → "1 hari + datang siang 4 jam". Angka jamnya tetap ada
     * sebagai teks utama; ini hanya pendampingnya.
     */
    public static function saldoManusiawi(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'belum ada saldo';
        }

        $days = intdiv($minutes, self::MINUTES_PER_FULL_DAY);
        $rest = $minutes % self::MINUTES_PER_FULL_DAY;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} hari";
        }
        if ($rest >= self::MINUTES_PER_HALF_DAY) {
            $parts[] = 'datang siang '.intdiv(self::MINUTES_PER_HALF_DAY, 60).' jam';
            $rest -= self::MINUTES_PER_HALF_DAY;
        }
        if ($rest > 0) {
            $parts[] = self::durasi($rest);
        }

        return implode(' + ', $parts);
    }

    /** "12 jam (1 hari + datang siang 4 jam)" */
    public static function saldo(int $minutes): string
    {
        return self::durasi($minutes).' ('.self::saldoManusiawi($minutes).')';
    }

    /** "3 hari lagi" — relatif, dipakai kolom sisa waktu batch. */
    public static function sisaWaktu(int $days): string
    {
        return match (true) {
            $days < 0 => 'sudah lewat '.abs($days).' hari',
            $days === 0 => 'hari ini',
            $days === 1 => 'besok',
            default => "{$days} hari lagi",
        };
    }

    /** "5 April 2026" */
    public static function tanggalPanjang(CarbonInterface $date): string
    {
        return $date->translatedFormat('j F Y');
    }

    /** "5 Apr" */
    public static function tanggalRingkas(CarbonInterface $date): string
    {
        return $date->translatedFormat('j M');
    }

    /** "19 Ags – 18 Sep (periode September)" */
    public static function periode(CarbonInterface $start, CarbonInterface $end, string $label): string
    {
        return self::tanggalRingkas($start).' – '.self::tanggalRingkas($end)." (periode {$label})";
    }

    /** "19:00" — jam murni, tanpa konversi timezone. */
    public static function jam(string $time): string
    {
        return substr($time, 0, 5);
    }
}
