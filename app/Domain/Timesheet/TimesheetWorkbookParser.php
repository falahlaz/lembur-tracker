<?php

namespace App\Domain\Timesheet;

use App\Support\ExcelValue;
use Carbon\CarbonImmutable;

/**
 * Menerjemahkan grid workbook timesheet menjadi calon entri Kimai.
 *
 * Murni: array masuk, ParsedWorkbook keluar. Tidak ada berkas, database, maupun
 * jaringan di sini — seam yang sama yang dipakai HistoricalImporter, dan yang
 * membuat hampir seluruh pengujian format ini tidak butuh satu pun .xlsx.
 *
 * Bentuk masukan: nama sheet → nomor baris → huruf kolom → nilai sel.
 *
 * Tidak ada satu pun nomor baris yang diasumsikan. Baris meta, baris tanggal, dan
 * baris slot semuanya DICARI lewat isinya: sheet Daily punya 5 baris slot, sheet
 * Overtime punya 12, dan keduanya pernah bergeser. Importer lama mengunci baris
 * 5–9 untuk keduanya dan diam-diam kehilangan sebagian besar entri Overtime.
 */
class TimesheetWorkbookParser
{
    /** Sheet yang dibaca → apakah entrinya diberi tag. */
    public const SHEETS = ['Daily' => false, 'Overtime' => true];

    private const ACTIVITY_MARKER = '/Activity\s*ID\s*:\s*(\d+)/i';

    private const CUSTOMER_LABEL = '/^customer\s*id$/i';

    private const PROJECT_LABEL = '/^project\s*id$/i';

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sheets
     * @param  array<int, string>  $availableSheets  seluruh nama sheet di workbook, untuk pesan kesalahan
     */
    public function parse(array $sheets, array $availableSheets = []): ParsedWorkbook
    {
        $issues = [];
        $entries = [];
        $customerId = null;
        $projectId = null;

        $tag = $this->overtimeTag();

        if ($tag === null) {
            $issues[] = 'Tag Overtime tidak dikonfigurasi (KIMAI_TAGS kosong), '
                .'sehingga entri lembur tidak akan pernah tertarik kembali oleh sync.';
        }

        foreach (self::SHEETS as $sheet => $tagged) {
            $rows = $sheets[$sheet] ?? null;

            if ($rows === null) {
                // Sengaja dilaporkan, bukan dilewati diam-diam: "sheet Overtime
                // tidak ada" persis keadaan yang HARUS kelihatan sebelum orang
                // mengunggah separuh periodenya tanpa sadar.
                $issues[] = $availableSheets === []
                    ? "Sheet '{$sheet}' tidak ditemukan di berkas ini."
                    : "Sheet '{$sheet}' tidak ditemukan. Yang ada: ".implode(', ', $availableSheets).'.';

                continue;
            }

            $meta = $this->readMeta($rows);
            $customerId ??= $meta['customer'];
            $projectId ??= $meta['project'];

            [$sheetEntries, $sheetIssues] = $this->readSheet(
                $sheet,
                $rows,
                $tagged ? $tag : null,
            );

            $entries = array_merge($entries, $sheetEntries);
            $issues = array_merge($issues, $sheetIssues);
        }

        // Urutan menentukan siapa yang menang saat dua sel berebut jam yang sama:
        // yang lebih awal bertahan, dan Daily didahulukan atas Overtime pada jam
        // yang identik supaya hasilnya tidak bergantung urutan baca sheet.
        usort($entries, function (ParsedEntry $a, ParsedEntry $b) {
            return [$a->beginAt->getTimestamp(), $a->sheet, $a->endAt->getTimestamp()]
                <=> [$b->beginAt->getTimestamp(), $b->sheet, $b->endAt->getTimestamp()];
        });

        $issues = array_merge($issues, $this->markInternalClashes($entries));

        return new ParsedWorkbook(
            customerId: $customerId,
            projectId: $projectId,
            entries: $entries,
            issues: $issues,
        );
    }

    /**
     * Tag yang dipakai HARUS tag yang ditarik kembali oleh sync (SY-05). Diturunkan
     * dari config alih-alih ditulis ulang: salinan kedua hanya perlu meleset sekali
     * untuk membuat entri lembur masuk ke Kimai tanpa pernah menjadi catatan lembur.
     */
    private function overtimeTag(): ?string
    {
        $tags = array_values(array_filter((array) config('kimai.tags')));

        return $tags[0] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{customer: ?int, project: ?int}
     */
    private function readMeta(array $rows): array
    {
        $meta = ['customer' => null, 'project' => null];

        foreach ($rows as $cells) {
            $label = trim(ExcelValue::toText($cells['A'] ?? null));

            if ($label === '') {
                continue;
            }

            // Dicari lewat LABELNYA, bukan lewat B1/B2. Catatan importer lama
            // ("sel Project ID terbaca 112, itu salah") sebenarnya salah baca:
            // 112 adalah Customer ID di baris atasnya. Membaca label membuat
            // kekeliruan itu mustahil terulang.
            if ($meta['customer'] === null && preg_match(self::CUSTOMER_LABEL, $label) === 1) {
                $meta['customer'] = $this->toId($cells['B'] ?? null);
            }

            if ($meta['project'] === null && preg_match(self::PROJECT_LABEL, $label) === 1) {
                $meta['project'] = $this->toId($cells['B'] ?? null);
            }
        }

        return $meta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, ParsedEntry>, 1: array<int, string>}
     */
    private function readSheet(string $sheet, array $rows, ?string $tag): array
    {
        $issues = [];
        $entries = [];

        $dateRow = $this->findDateRow($rows);

        if ($dateRow === []) {
            $issues[] = "Sheet '{$sheet}': baris tanggal tidak ditemukan, jadi tidak ada entri yang bisa dibaca.";

            return [[], $issues];
        }

        foreach ($rows as $rowNumber => $cells) {
            $label = trim(ExcelValue::toText($cells['A'] ?? null));

            if ($label === '') {
                continue;
            }

            $slot = SlotLabel::parse($label);

            if ($slot === null) {
                // Label meta sudah dipakai readMeta(); sisanya berarti ada baris
                // yang bentuknya tidak dikenali. Dilaporkan — supaya template yang
                // berubah ketahuan, bukan menghilangkan sebaris entri tanpa suara.
                if (! $this->isMetaLabel($label)) {
                    $issues[] = "Sheet '{$sheet}' baris {$rowNumber}: label jam '{$label}' tidak dikenali, barisnya dilewati.";
                }

                continue;
            }

            foreach ($dateRow as $column => $date) {
                $text = ExcelValue::toText($cells[$column] ?? null);

                if (trim($text) === '') {
                    continue;
                }

                if (preg_match(self::ACTIVITY_MARKER, $text, $m) !== 1) {
                    // Sel berisi teks tetapi tanpa penanda activity: bukan entri,
                    // tetapi juga bukan sel kosong. Tidak diimpor, tetap dilaporkan.
                    $issues[] = "Sheet '{$sheet}'!{$column}{$rowNumber}: ada isi tetapi tanpa 'Activity ID', dilewati.";

                    continue;
                }

                $entries[] = new ParsedEntry(
                    sheet: $sheet,
                    cellRef: "{$sheet}!{$column}{$rowNumber}",
                    slotLabel: $slot->raw,
                    workDate: $date,
                    beginAt: $slot->beginAt($date),
                    endAt: $slot->endAt($date),
                    activityId: (int) $m[1],
                    description: $this->cleanDescription($text),
                    tag: $tag,
                );
            }
        }

        return [$entries, $issues];
    }

    /**
     * Mencari baris tanggal, bukan mengasumsikan baris 4.
     *
     * Penandanya KOLOM A KOSONG: baris meta ("Project ID") dan baris slot
     * ("9 AM - 10 AM") sama-sama berlabel, hanya baris tanggal yang tidak. Tanpa
     * syarat itu, baris "Customer ID | 112" ikut terpilih — 112 adalah serial
     * Excel yang sah, dan seluruh timesheet mendarat di April 1900.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, CarbonImmutable> huruf kolom → tanggal
     */
    private function findDateRow(array $rows): array
    {
        $best = [];

        foreach ($rows as $cells) {
            if (trim(ExcelValue::toText($cells['A'] ?? null)) !== '') {
                continue;
            }

            $dates = [];

            foreach ($cells as $column => $value) {
                if ($column === 'A') {
                    continue;
                }

                // Hanya serial dan objek tanggal yang dianggap. Teks bebas tidak,
                // karena Carbon terlalu rela menerjemahkan apa saja jadi tanggal
                // dan baris slot pun bisa ikut terpilih.
                if (! is_numeric($value) && ! $value instanceof \DateTimeInterface) {
                    continue;
                }

                $date = ExcelValue::toDate($value);

                if ($date !== null) {
                    $dates[$column] = $date;
                }
            }

            if (count($dates) > count($best)) {
                $best = $dates;
            }
        }

        return $best;
    }

    /**
     * Membuang penanda activity SEKALI, lalu merapikan ujungnya. Newline di dalam
     * badan deskripsi dipertahankan — itu daftar langkah kerja yang ikut masuk ke
     * Kimai apa adanya.
     */
    private function cleanDescription(string $text): string
    {
        return trim(preg_replace(self::ACTIVITY_MARKER, '', $text, limit: 1), "\r\n \t");
    }

    private function isMetaLabel(string $label): bool
    {
        return preg_match(self::CUSTOMER_LABEL, $label) === 1
            || preg_match(self::PROJECT_LABEL, $label) === 1;
    }

    private function toId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Sheet Daily dan Overtime berbagi LIMA label slot yang sama persis
     * ("9 AM - 10 AM", "1 PM - 3 PM", dan seterusnya). Mengisi keduanya di tanggal
     * yang sama menghasilkan dua entri yang saling tumpang tindih — satu bertag
     * Overtime — dan sync kemudian membuat catatan lembur untuk jam kerja biasa.
     * Kimai sendiri menerima keduanya tanpa protes, jadi pemeriksaan ini harus ada
     * di sini.
     *
     * Tidak bisa ditimpa user: yang keliru adalah BERKASNYA, dan memilih salah satu
     * hanya menebak mana yang dimaksud.
     *
     * @param  array<int, ParsedEntry>  $entries  sudah terurut menurut beginAt
     * @return array<int, string>
     */
    private function markInternalClashes(array $entries): array
    {
        $issues = [];
        /** @var array<int, ParsedEntry> $kept */
        $kept = [];

        foreach ($entries as $entry) {
            foreach ($kept as $earlier) {
                if ($entry->beginAt->lt($earlier->endAt) && $entry->endAt->gt($earlier->beginAt)) {
                    $entry->skip("Bentrok dengan {$earlier->cellRef} di berkas yang sama.");
                    $issues[] = "{$entry->cellRef} bentrok dengan {$earlier->cellRef} di berkas yang sama, jadi dilewati.";

                    continue 2;
                }
            }

            $kept[] = $entry;
        }

        return $issues;
    }
}
