<?php

namespace App\Domain\Timesheet;

use App\Domain\Timesheet\Exceptions\InvalidWorkbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Satu-satunya kelas di fitur ini yang menyentuh berkas. Mengubah .xlsx jadi grid
 * biasa, lalu menyerahkannya ke TimesheetWorkbookParser yang murni.
 *
 * Memakai PhpSpreadsheet langsung, bukan Excel::toArray() milik maatwebsite yang
 * dipakai ImportLembur, karena tiga hal yang dibutuhkan di sini tidak tersedia
 * lewat jalur itu:
 *
 *   1. NAMA sheet. Excel::toArray() mengembalikan sheet secara posisional, jadi
 *      "mana Daily mana Overtime" hanya bisa ditebak. Concern WithMultipleSheets
 *      bisa memberi kunci nama, tetapi pasangannya SkipsUnknownSheets MEMBUANG
 *      sheet yang hilang tanpa suara — padahal "sheet Overtime tidak ada" justru
 *      yang paling wajib terlihat sebelum orang mengunggah separuh periodenya.
 *   2. Serial tanggal yang belum diformat berbarengan dengan teks sel yang sudah
 *      diratakan. Di sini keduanya diminta eksplisit lewat formatData: false.
 *   3. Nomor baris dan huruf kolom aslinya, supaya kesalahan bisa menunjuk sel
 *      yang benar — returnCellRef: true.
 *
 * ImportLembur dan RawSheetImport tidak disentuh sama sekali.
 */
class WorkbookReader
{
    /**
     * @return array<int, string>
     *
     * @throws InvalidWorkbook
     */
    public function sheetNames(string $path): array
    {
        try {
            // Hanya membaca workbook.xml, tidak memuat satu pun sel.
            return IOFactory::createReaderForFile($path)->listWorksheetNames($path);
        } catch (Throwable $e) {
            throw new InvalidWorkbook('Berkas tidak bisa dibaca sebagai Excel: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $wanted  sheet yang diambil; yang tidak ada dilewati di sini
     *                                      dan dilaporkan parser
     * @return array<string, array<int, array<string, mixed>>> nama sheet → baris → kolom → nilai
     *
     * @throws InvalidWorkbook
     */
    public function read(string $path, array $wanted): array
    {
        $available = $this->sheetNames($path);
        $load = array_values(array_intersect($wanted, $available));

        if ($load === []) {
            throw new InvalidWorkbook(
                'Berkas ini tidak memuat sheet '.implode(' maupun ', $wanted)
                .'. Yang ada: '.(implode(', ', $available) ?: 'tidak ada').'.'
            );
        }

        $book = $this->load($path, $load);

        $sheets = [];

        foreach ($load as $name) {
            $sheets[$name] = $this->grid($book, $name);
        }

        // Melepas memori worksheet secara eksplisit: satu workbook bisa memuat
        // ratusan sel rich text, dan objeknya menyimpan referensi silang yang
        // tidak dibereskan garbage collector dengan sendirinya.
        $book->disconnectWorksheets();

        return $sheets;
    }

    /** @param array<int, string> $load */
    private function load(string $path, array $load): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            // Gaya sel tidak dipakai sama sekali; rich text tetap terbaca karena
            // ia tinggal di sharedStrings, bukan di styles.
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly($load);

            return $reader->load($path);
        } catch (Throwable $e) {
            throw new InvalidWorkbook('Berkas tidak bisa dibaca sebagai Excel: '.$e->getMessage());
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function grid(Spreadsheet $book, string $name): array
    {
        $sheet = $book->getSheetByName($name);

        if ($sheet === null) {
            return [];
        }

        // Baris tanggal bisa saja berisi rumus (=B4+1), jadi rumus dihitung. Kalau
        // ada satu rumus rusak, seluruh berkas tidak boleh ikut gagal — nilai
        // terakhir yang tersimpan masih jauh lebih berguna daripada tidak ada.
        try {
            return $sheet->toArray(
                nullValue: null,
                calculateFormulas: true,
                formatData: false,
                returnCellRef: true,
            );
        } catch (Throwable) {
            return $sheet->toArray(
                nullValue: null,
                calculateFormulas: false,
                formatData: false,
                returnCellRef: true,
            );
        }
    }
}
