<?php

namespace App\Domain\Timesheet;

use App\Domain\Timesheet\Exceptions\InvalidManualInput;
use App\Models\TimesheetUpload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Baris isian halaman Isi Timesheet menjadi entri Kimai yang masing-masing ≤ 4 jam.
 *
 * Kimai menolak timesheet yang lebih panjang dari 4 jam ("Maximum 4:00 hours
 * allowed", diuji langsung: 4:00 diterima, 4:01 ditolak), dan itulah alasan halaman
 * ini ada: orang cukup menulis "09:00 selama 8 jam, Development" sekali, lalu
 * pemecahannya dikerjakan di sini — 09–12, 13–17, 17–18.
 *
 * Istirahat 12:00–13:00 dilewati: jam itu tidak masuk entri mana pun, dan Durasi
 * di form berarti jam kerja bersih. Kimai tidak bisa diberi `break` lewat API
 * ("This form should not contain extra fields"), jadi satu-satunya cara
 * mewujudkan istirahat adalah jeda kosong di antara dua entri.
 *
 * Hasilnya ParsedWorkbook yang sama dengan hasil membaca berkas, sehingga
 * pemeriksaan duplikat, pratinjau, dan pengirimannya tidak perlu tahu dari mana
 * entrinya berasal.
 */
class ManualEntryBuilder
{
    /** Batas Kimai untuk satu timesheet. */
    public const MAX_MINUTES = 240;

    /** Istirahat siang, dalam menit sejak tengah malam: 12:00–13:00. */
    public const BREAK_START = 720;

    public const BREAK_END = 780;

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
     * Potongan entri Kimai untuk rentang jam dinding, sebagai [dari, sampai] dalam
     * menit sejak tengah malam: istirahat 12:00–13:00 dibuang, sisanya dipotong
     * per MAX_MINUTES dari awal tiap bagian. Satu-satunya tempat aturan ini hidup —
     * build(), ringkasan, dan form semua memakainya, sehingga angkanya tidak bisa
     * berbeda dari entri yang benar-benar terkirim.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function chunks(int $mulai, int $selesai): array
    {
        $bagian = [];

        if ($mulai < self::BREAK_START) {
            $bagian[] = [$mulai, min($selesai, self::BREAK_START)];
        }

        if ($selesai > self::BREAK_END) {
            $bagian[] = [max($mulai, self::BREAK_END), $selesai];
        }

        $out = [];

        foreach ($bagian as [$dari, $sampai]) {
            for (; $dari < $sampai; $dari += self::MAX_MINUTES) {
                $out[] = [$dari, min($sampai, $dari + self::MAX_MINUTES)];
            }
        }

        return $out;
    }

    /** Jam kerja bersih (menit) dari rentang jam dinding: selisihnya dikurangi istirahat. */
    public static function netMinutes(int $mulai, int $selesai): int
    {
        return array_sum(array_map(fn (array $c) => $c[1] - $c[0], self::chunks($mulai, $selesai)));
    }

    /** Kebalikan netMinutes(): jam selesai untuk jam kerja bersih tertentu, istirahat dilewati. Maksimal 24:00. */
    public static function selesaiDariDurasi(int $mulai, int $durasi): int
    {
        if ($mulai >= self::BREAK_START && $mulai < self::BREAK_END) {
            $mulai = self::BREAK_END;
        }

        $sebelumIstirahat = max(0, self::BREAK_START - $mulai);

        $selesai = $durasi <= $sebelumIstirahat
            ? $mulai + $durasi
            : max($mulai, self::BREAK_END) + $durasi - $sebelumIstirahat;

        return min(1440, $selesai);
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
        } elseif (self::chunks($mulai, $selesai) === []) {
            $problems[] = "Baris {$nomor}: seluruhnya jam istirahat (12:00–13:00), tidak ada yang bisa dicatat.";
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

        $potongan = self::chunks($mulai, $selesai);
        $bagian = count($potongan);
        $out = [];

        foreach ($potongan as $ke => [$dari, $sampai]) {
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

    /**
     * Kebalikan split(): entri sebuah upload isian dikembalikan menjadi baris form.
     *
     * Bisa lossless karena split() meninggalkan jejaknya di setiap potongan —
     * cell_ref "Baris N (k/m)" menyebut baris asalnya, sheet Overtime/Daily adalah
     * toggle Lembur, dan jam mulai/selesai barisnya tinggal awal potongan pertama
     * dan akhir potongan terakhir. Tidak ada kolom tambahan yang perlu disimpan,
     * sehingga upload yang dibatalkan SEBELUM fitur pulihkan ada pun ikut bisa
     * dipulihkan.
     *
     * Entri berstatus apa pun ikut: baris yang dulu dilewati karena duplikat tetap
     * bagian dari isian orangnya, dan Periksa ulang yang memutuskan nasibnya.
     *
     * @return array<string, array<string, mixed>> state repeater, berkunci uuid
     */
    public function rowsFromUpload(TimesheetUpload $upload): array
    {
        $zone = config('kimai.timezone');
        $perBaris = [];

        foreach ($upload->entries()->orderBy('begin_at')->orderBy('id')->get() as $entry) {
            if (preg_match('/^Baris (\d+)/', (string) $entry->cell_ref, $m) !== 1) {
                continue;
            }

            $perBaris[(int) $m[1]][] = $entry;
        }

        ksort($perBaris);

        $rows = [];

        foreach ($perBaris as $potongan) {
            $pertama = $potongan[0];
            $terakhir = end($potongan);

            $tanggal = CarbonImmutable::parse($pertama->work_date->toDateString(), $zone);
            $mulai = (int) $tanggal->diffInMinutes($pertama->begin_at, true);
            $selesai = (int) $tanggal->diffInMinutes($terakhir->end_at, true);

            $rows[(string) Str::uuid()] = [
                'tanggal' => $tanggal->toDateString(),
                'mulai' => sprintf('%02d:%02d', intdiv($mulai, 60), $mulai % 60),
                // Jam kerja bersih = jumlah potongan; selisih jam dinding akan ikut menghitung istirahat.
                'durasi' => sprintf('%d:%02d', intdiv($kerja = (int) collect($potongan)->sum('duration_minutes'), 60), $kerja % 60),
                // Tengah malam ditulis 00:00; minuteOfDay(asEnd) membacanya kembali sebagai 24:00.
                'selesai' => sprintf('%02d:%02d', intdiv($selesai, 60) % 24, $selesai % 60),
                'activity_id' => $pertama->activity_id,
                'deskripsi' => $pertama->description,
                'lembur' => $pertama->sheet === 'Overtime',
            ];
        }

        return $rows;
    }

    /** Sama dengan TimesheetWorkbookParser: tag yang ditarik kembali oleh sync. */
    private function overtimeTag(): ?string
    {
        $tags = array_values(array_filter((array) config('kimai.tags')));

        return $tags[0] ?? null;
    }
}
