<?php

namespace App\Domain\Lembur;

/**
 * BR-01, BR-03, BR-04 — aritmetika durasi murni, tanpa sentuhan database.
 * Dipisahkan supaya preview live di form (P-3) memakai kode yang PERSIS SAMA
 * dengan yang menghitung saat simpan. Kalau keduanya beda, preview berbohong.
 */
final class DurationCalculator
{
    public const MINUTES_PER_DAY = 1440;

    /**
     * BR-03 — lembur yang melewati tengah malam diikatkan ke tanggal jam mulai.
     * 21:00 → 02:00 dihitung 5 jam pada tanggal mulai, bukan dipecah dua hari.
     */
    public static function rawMinutes(string $startTime, string $endTime): int
    {
        $start = self::toMinutes($startTime);
        $end = self::toMinutes($endTime);
        $diff = $end - $start;

        return $diff < 0 ? $diff + self::MINUTES_PER_DAY : $diff;
    }

    /**
     * BR-04 — pembulatan ke JAM TERDEKAT, hanya bila preferensi user menyala.
     * 3j31m → 4 jam. 3j29m → 3 jam. 7j35m → 8 jam.
     */
    public static function effectiveMinutes(int $rawMinutes, bool $roundingEnabled): int
    {
        if (! $roundingEnabled) {
            return $rawMinutes;
        }

        return (int) round($rawMinutes / 60) * 60;
    }

    /** True hanya bila pembulatan benar-benar mengubah angkanya. */
    public static function roundingChangedAnything(int $rawMinutes, bool $roundingEnabled): bool
    {
        return $roundingEnabled && self::effectiveMinutes($rawMinutes, true) !== $rawMinutes;
    }

    /** True bila jam selesai lebih kecil dari jam mulai (lembur lintas hari). */
    public static function crossesMidnight(string $startTime, string $endTime): bool
    {
        return self::toMinutes($endTime) < self::toMinutes($startTime);
    }

    /** BR-01 — jam kerja normal 09:00–18:00; di luar itu terhitung lembur. */
    public static function toMinutes(string $time): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $h * 60 + $m;
    }

    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        // Dinormalisasi ke garis waktu menit dari jam mulai masing-masing, sehingga
        // sesi yang melewati tengah malam tetap terbandingkan dengan benar.
        $aStart = self::toMinutes($startA);
        $aEnd = $aStart + self::rawMinutes($startA, $endA);
        $bStart = self::toMinutes($startB);
        $bEnd = $bStart + self::rawMinutes($startB, $endB);

        return $aStart < $bEnd && $bStart < $aEnd;
    }
}
