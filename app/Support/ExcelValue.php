<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Menormalkan nilai mentah dari satu sel spreadsheet.
 *
 * Dipisahkan dari HistoricalImporter supaya importer timesheet memakai penanganan
 * tanggal yang SAMA, bukan salinannya. Penanganan "15/01/2026" di bawah bukan
 * hal sepele — dan dua salinan yang perlahan berbeda adalah cara paling sunyi
 * untuk membuat dua importer membaca tanggal yang sama menjadi hari yang berbeda.
 */
final class ExcelValue
{
    /** Serial Excel, teks tanggal, atau objek tanggal → tanggal. Null kalau bukan. */
    public static function toDate(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        // Excel menyimpan tanggal sebagai serial number (hari sejak 1900).
        if (is_numeric($value) && (float) $value >= 1) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);

        // Orang Indonesia menulis 15/01/2026, sementara Carbon membaca garis miring
        // sebagai format Amerika (bulan/hari) dan diam-diam menolaknya. Format
        // hari-dulu dicoba eksplisit, dengan verifikasi roundtrip supaya tidak ada
        // tanggal yang "berhasil" dibaca menjadi sesuatu yang lain.
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format.'|', $text);
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed->startOfDay();
            }
        }

        try {
            return CarbonImmutable::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Isi sel sebagai teks biasa.
     *
     * Sel timesheet adalah rich text ber-newline; pembaca sudah meratakannya jadi
     * string, tetapi float dan null tetap bisa lewat sini, dan (string) atas null
     * memicu deprecation di PHP 8.4.
     */
    public static function toText(mixed $value): string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return '';
        }

        return (string) $value;
    }
}
