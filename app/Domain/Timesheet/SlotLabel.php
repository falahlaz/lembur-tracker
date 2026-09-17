<?php

namespace App\Domain\Timesheet;

use Carbon\CarbonImmutable;

/**
 * Satu label slot jam dari kolom A workbook timesheet — "9 AM - 10 AM".
 *
 * Slot dibaca dari LABELNYA, bukan dari nomor barisnya. Itu perbedaan paling
 * penting dengan importer lama: importer itu mengunci baris 5–9 untuk kedua
 * sheet, padahal sheet Daily punya 5 baris slot dan sheet Overtime punya 12.
 * Akibatnya sebagian besar entri Overtime tidak pernah terbaca, dan yang terbaca
 * dikirim ke jam yang salah — tanpa satu pun pesan kesalahan. Template berubah
 * lagi tahun depan; label jauh lebih mungkin bertahan daripada nomor baris.
 *
 * TEMPLATE INI MEMBALIK KONVENSI JAM 12. Di dalamnya "12 AM" berarti tengah hari
 * dan "12 PM" berarti tengah malam — kebalikan dari arti bakunya:
 *
 *     "10 AM - 12 AM"  →  10:00–12:00  (siang, bukan 10:00–00:00)
 *     "10 PM - 12 PM"  →  22:00–24:00  (tengah malam, bukan 22:00–12:00)
 *
 * Perbaikannya: setelah diterjemahkan apa adanya, jam akhir yang jatuh di bawah
 * atau sama dengan jam mulai ditambah 12. Itu BUKAN aturan umum "lewat tengah
 * malam" — ia hanya menyandikan pembalikan di atas. Karena itu hasilnya diperiksa
 * lagi: rentang yang tetap tidak masuk akal ditolak, bukan dipaksa benar. Lihat
 * catatan di validasi bawah.
 */
final readonly class SlotLabel
{
    /** Batas wajar satu slot. Seluruh slot di template nyata ≤ 2 jam. */
    private const MIN_HOURS = 1;

    private const MAX_HOURS = 12;

    private function __construct(
        public string $raw,
        /** 0–23 */
        public int $startHour,
        /** 1–24; 24 berarti tengah malam HARI BERIKUTNYA. */
        public int $endHour,
    ) {}

    /**
     * Mengembalikan null kalau teksnya bukan slot jam sama sekali (baris kosong,
     * "Total", "Customer ID") — pemanggil yang memutuskan apakah itu layak
     * dilaporkan atau memang wajar dilewati.
     */
    public static function parse(?string $raw): ?self
    {
        $text = trim(str_replace("\u{00A0}", ' ', (string) $raw));

        if ($text === '') {
            return null;
        }

        // Tanda pisahnya bisa hyphen, en dash, atau em dash — workbook diedit di
        // Excel, Numbers, dan Google Sheets bergantian, dan ketiganya berbeda.
        $matched = preg_match(
            '/^(\d{1,2})\s*(AM|PM)\s*[-\x{2013}\x{2014}]\s*(\d{1,2})\s*(AM|PM)$/iu',
            $text,
            $m,
        );

        if ($matched !== 1) {
            return null;
        }

        $start = self::toHour((int) $m[1], strtoupper($m[2]));
        $end = self::toHour((int) $m[3], strtoupper($m[4]));

        if ($start === null || $end === null) {
            return null;
        }

        if ($end <= $start) {
            $end += 12;
        }

        // Rentang yang benar-benar melewati tengah malam — "11 PM - 1 AM" —
        // tidak bisa diwakili: perbaikan di atas mengubahnya jadi 23:00–13:00,
        // yang durasinya negatif. Template nyata tidak pernah memuatnya, jadi
        // yang benar adalah MENOLAK dengan jujur, bukan menebak maksudnya dan
        // mengirim entri ngawur ke Kimai.
        $hours = $end - $start;

        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            return null;
        }

        return new self($text, $start, $end);
    }

    /** Awal slot, sebagai instan absolut di zona Kimai. */
    public function beginAt(CarbonImmutable $date): CarbonImmutable
    {
        return $this->anchor($date)->addHours($this->startHour);
    }

    /** Akhir slot; jam 24 jatuh di tengah malam hari berikutnya. */
    public function endAt(CarbonImmutable $date): CarbonImmutable
    {
        return $this->anchor($date)->addHours($this->endHour);
    }

    public function durationMinutes(): int
    {
        return ($this->endHour - $this->startHour) * 60;
    }

    /**
     * Tengah malam tanggal itu DI ZONA KIMAI. Menyusunnya di zona aplikasi (UTC)
     * akan menggeser seluruh entri tujuh jam tanpa ada yang menyadarinya sampai
     * timesheet-nya terlanjur masuk.
     */
    private function anchor(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone(config('kimai.timezone'))->startOfDay();
    }

    private static function toHour(int $hour, string $meridiem): ?int
    {
        if ($hour < 1 || $hour > 12) {
            return null;
        }

        return ($hour % 12) + ($meridiem === 'PM' ? 12 : 0);
    }
}
