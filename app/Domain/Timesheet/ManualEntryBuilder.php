<?php

namespace App\Domain\Timesheet;

use App\Domain\Timesheet\Exceptions\InvalidManualInput;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Baris isian halaman Isi Timesheet menjadi entri Kimai yang masing-masing ≤ 2 jam.
 *
 * Kimai menolak timesheet yang lebih panjang dari 2 jam, dan itulah alasan halaman
 * ini ada: orang cukup menulis "09:00–18:00, Development" sekali, lalu pemecahannya
 * dikerjakan di sini — 09–11, 11–13, 13–15, 15–17, 17–18. Potongannya murni per
 * 2 jam dari jam mulai; jam istirahat tidak dipotong otomatis. Yang ingin
 * istirahatnya kosong menulisnya sebagai dua baris.
 *
 * Hasilnya ParsedWorkbook yang sama dengan hasil membaca berkas, sehingga
 * pemeriksaan duplikat, pratinjau, dan pengirimannya tidak perlu tahu dari mana
 * entrinya berasal.
 */
class ManualEntryBuilder
{
    /** Batas Kimai untuk satu timesheet. */
    public const MAX_MINUTES = 120;

    /**
     * @param  array<int|string, array<string, mixed>>  $rows  state repeater apa adanya
     * @param  array<int, string>  $activityNames  id => nama, untuk pratinjau
     *
     * @throws InvalidManualInput
     */
    public function build(array $rows, int $projectId, array $activityNames = []): ParsedWorkbook
    {
        $entries = [];
        $problems = [];
        /** @var array<string, array<int, array{0: int, 1: int, 2: int}>> $perTanggal */
        $perTanggal = [];

        foreach (array_values($rows) as $i => $row) {
            $nomor = $i + 1;

            $parsed = $this->readRow($row, $nomor, $problems);

            if ($parsed === null) {
                continue;
            }

            [$tanggal, $mulai, $selesai] = $parsed;

            // Bentrok di dalam isian ditolak, bukan dilewati: selain membingungkan,
            // dua potongan berjam sama melanggar unique index draf (tue_sel_unik).
            foreach ($perTanggal[$tanggal->toDateString()] ?? [] as [$lainMulai, $lainSelesai, $lainNomor]) {
                if ($mulai < $lainSelesai && $selesai > $lainMulai) {
                    $problems[] = "Baris {$nomor} bertumpukan jamnya dengan baris {$lainNomor} di "
                        .$tanggal->translatedFormat('j M').'.';

                    continue 2;
                }
            }

            $perTanggal[$tanggal->toDateString()][] = [$mulai, $selesai, $nomor];

            $entries = array_merge($entries, $this->split($row, $nomor, $tanggal, $mulai, $selesai, $activityNames));
        }

        if ($problems !== []) {
            throw new InvalidManualInput($problems);
        }

        if ($entries === []) {
            throw new InvalidManualInput(['Belum ada satu baris pun yang diisi.']);
        }

        $max = (int) config('kimai.upload_max_entries');

        if (count($entries) > $max) {
            throw new InvalidManualInput([
                'Isian ini menghasilkan '.count($entries)." entri Kimai, melebihi batas {$max}. Kirim dalam beberapa bagian.",
            ]);
        }

        usort($entries, fn (ParsedEntry $a, ParsedEntry $b) => $a->beginAt->getTimestamp() <=> $b->beginAt->getTimestamp());

        return new ParsedWorkbook(customerId: null, projectId: $projectId, entries: $entries);
    }

    /**
     * Berapa entri Kimai yang akan lahir dari rentang ini — dipakai ringkasan
     * langsung di halaman, supaya angkanya persis sama dengan hasil build().
     */
    public static function pieces(int $minutes): int
    {
        return $minutes <= 0 ? 0 : (int) ceil($minutes / self::MAX_MINUTES);
    }

    /**
     * "08:30" → 510. "00:00" sebagai jam SELESAI berarti tengah malam (1440),
     * seperti slot "10 PM - 12 PM" di template workbook.
     */
    public static function minuteOfDay(mixed $value, bool $asEnd = false): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $m) !== 1) {
            return null;
        }

        $jam = (int) $m[1];
        $menit = (int) $m[2];

        if ($jam > 23 || $menit > 59) {
            return null;
        }

        $total = $jam * 60 + $menit;

        return $asEnd && $total === 0 ? 1440 : $total;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $problems
     * @return array{0: CarbonImmutable, 1: int, 2: int}|null
     */
    private function readRow(array $row, int $nomor, array &$problems): ?array
    {
        $awal = count($problems);

        $tanggal = null;

        try {
            $tanggal = filled($row['tanggal'] ?? null)
                ? CarbonImmutable::parse((string) $row['tanggal'], config('kimai.timezone'))->startOfDay()
                : null;
        } catch (Throwable) {
            // dilaporkan di bawah
        }

        if ($tanggal === null) {
            $problems[] = "Baris {$nomor}: tanggalnya belum diisi.";
        }

        $mulai = self::minuteOfDay($row['mulai'] ?? null);
        $selesai = self::minuteOfDay($row['selesai'] ?? null, asEnd: true);

        if ($mulai === null || $selesai === null) {
            $problems[] = "Baris {$nomor}: jam mulai dan selesai harus diisi.";
        } elseif ($selesai <= $mulai) {
            $problems[] = "Baris {$nomor}: jam selesai harus setelah jam mulai.";
        }

        if ((int) ($row['activity_id'] ?? 0) <= 0) {
            $problems[] = "Baris {$nomor}: activity belum dipilih.";
        }

        if (blank($row['deskripsi'] ?? null)) {
            $problems[] = "Baris {$nomor}: deskripsi pekerjaan belum diisi.";
        }

        return count($problems) === $awal ? [$tanggal, $mulai, $selesai] : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $activityNames
     * @return array<int, ParsedEntry>
     */
    private function split(array $row, int $nomor, CarbonImmutable $tanggal, int $mulai, int $selesai, array $activityNames): array
    {
        $lembur = (bool) ($row['lembur'] ?? false);
        $tag = $lembur ? $this->overtimeTag() : null;
        $activityId = (int) $row['activity_id'];

        $bagian = self::pieces($selesai - $mulai);
        $out = [];

        for ($ke = 0, $dari = $mulai; $dari < $selesai; $ke++, $dari += self::MAX_MINUTES) {
            $sampai = min($selesai, $dari + self::MAX_MINUTES);

            // Dirakit dari tengah malam DI ZONA KIMAI, seperti SlotLabel::anchor();
            // jam 24 jatuh di tengah malam hari berikutnya.
            $begin = $tanggal->addMinutes($dari);
            $end = $tanggal->addMinutes($sampai);

            $out[] = new ParsedEntry(
                // Mengikuti nilai sheet workbook supaya ringkasan per sheet dan
                // pemicu sync lembur di UploadPoster bekerja tanpa cabang baru.
                sheet: $lembur ? 'Overtime' : 'Daily',
                cellRef: $bagian > 1 ? "Baris {$nomor} (".($ke + 1)."/{$bagian})" : "Baris {$nomor}",
                slotLabel: $begin->format('H:i').'–'.$end->format('H:i'),
                workDate: $tanggal,
                beginAt: $begin,
                endAt: $end,
                activityId: $activityId,
                activityName: $activityNames[$activityId] ?? null,
                description: trim((string) $row['deskripsi']),
                tag: $tag,
            );
        }

        return $out;
    }

    /** Sama dengan TimesheetWorkbookParser: tag yang ditarik kembali oleh sync. */
    private function overtimeTag(): ?string
    {
        $tags = array_values(array_filter((array) config('kimai.tags')));

        return $tags[0] ?? null;
    }
}
