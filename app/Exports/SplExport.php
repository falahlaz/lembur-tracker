<?php

namespace App\Exports;

use App\Models\KimaiProject;
use App\Models\OvertimeRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Surat Perintah Lembur — template Excel kantor yang diisi dari catatan lembur,
 * supaya tiap tanggal 19 berkasnya tinggal diunduh lalu diunggah.
 *
 * Sengaja memakai PhpSpreadsheet langsung, bukan maatwebsite seperti
 * RekapLemburExport: yang dibutuhkan di sini mengisi sel template yang sudah
 * jadi (logo, border, merge), bukan membangun sheet dari koleksi.
 */
class SplExport
{
    public const TEMPLATE = 'templates/spl.xlsx';

    /** Baris data pertama dan jumlah baris yang disediakan template (12–36). */
    private const FIRST_ROW = 12;

    private const TEMPLATE_ROWS = 25;

    /** Kolom Deskripsi kira-kira muat sekian karakter per baris pada lebar template. */
    private const CHARS_PER_LINE = 30;

    private const LINE_HEIGHT = 15;

    public function __construct(
        private readonly User $user,
        private readonly CarbonInterface $from,
        private readonly CarbonInterface $to,
    ) {}

    /** Meniru nama berkas asli: "CODE.ID - SPL NAMA KARYAWAN - 202603.xlsx". */
    public function fileName(): string
    {
        $name = mb_strtoupper(trim(preg_replace('/[^\pL\pN ]+/u', '', $this->user->name)));

        return "CODE.ID - SPL {$name} - {$this->to->format('Ym')}.xlsx";
    }

    /** @return Collection<int, OvertimeRecord> */
    public function records(): Collection
    {
        return OvertimeRecord::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('overtime_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->countsTowardEstimate()
            ->orderBy('overtime_date')
            ->orderBy('start_time')
            ->get();
    }

    public function spreadsheet(): Spreadsheet
    {
        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $sheet = $book->getActiveSheet();
        $records = $this->records();

        // Template menyediakan 25 baris; sisanya disisipkan sebelum baris Total
        // sehingga border dan blok tanda tangan ikut bergeser.
        $extra = max(0, $records->count() - self::TEMPLATE_ROWS);
        $totalRow = self::FIRST_ROW + self::TEMPLATE_ROWS;

        if ($extra > 0) {
            $sheet->insertNewRowBefore($totalRow, $extra);
            $totalRow += $extra;
        }

        $this->fillHeader($sheet);
        $this->fillRows($sheet, $records, $totalRow);
        $this->fillFooter($sheet, $totalRow);

        return $book;
    }

    /** Menulis ke berkas sementara dan mengembalikan path-nya. */
    public function store(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spl').'.xlsx';

        IOFactory::createWriter($this->spreadsheet(), 'Xlsx')->save($path);

        return $path;
    }

    private function fillHeader(Worksheet $sheet): void
    {
        $sheet->setCellValue('D6', ':  '.$this->user->name);
        $sheet->setCellValue('D7', ':  '.$this->from->format('d/m/Y').' - '.$this->to->format('d/m/Y'));
        $sheet->setCellValue('D8', ':  '.($this->user->division ?? ''));
    }

    /** @param  Collection<int, OvertimeRecord>  $records */
    private function fillRows(Worksheet $sheet, Collection $records, int $totalRow): void
    {
        $projects = KimaiProject::query()
            ->whereIn('kimai_id', $records->pluck('kimai_project_id')->filter()->unique())
            ->pluck('name', 'kimai_id');

        $lastRow = $totalRow - 1;

        for ($row = self::FIRST_ROW; $row <= $lastRow; $row++) {
            $sheet->setCellValue("B{$row}", $row - self::FIRST_ROW + 1);

            // Template hanya me-merge Tanggal (E:F) di baris-baris awal.
            if (! isset($sheet->getMergeCells()["E{$row}:F{$row}"])) {
                $sheet->mergeCells("E{$row}:F{$row}");
            }
        }

        foreach ($records->values() as $i => $record) {
            $row = self::FIRST_ROW + $i;
            $start = $this->dayFraction((string) $record->start_time);
            $end = $this->dayFraction((string) $record->end_time);

            $sheet->setCellValue("C{$row}", $projects[$record->kimai_project_id] ?? $this->user->default_project ?? '');
            $sheet->setCellValue("D{$row}", $record->work_description);
            $sheet->setCellValue("E{$row}", ExcelDate::PHPToExcel($record->overtime_date->startOfDay()));
            // Tidak ada data rencana terpisah; rencana disamakan dengan realisasi.
            $sheet->setCellValue("G{$row}", $start);
            $sheet->setCellValue("H{$row}", $end);
            $sheet->setCellValue("I{$row}", $start);
            $sheet->setCellValue("J{$row}", $end);
            // Jam yang benar-benar dikerjakan, sebelum pembulatan BR-04.
            $sheet->setCellValue("K{$row}", round($record->duration_raw_minutes / 60, 2));

            if (filled($record->evidence_url)) {
                $sheet->setCellValue("L{$row}", $this->evidenceLabel($record->evidence_url));
                $sheet->getCell("L{$row}")->getHyperlink()->setUrl($record->evidence_url);
            }

            $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('d-mmm-yy');
            $sheet->getStyle("G{$row}:J{$row}")->getNumberFormat()->setFormatCode('hh:mm');
            $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("E{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight($this->rowHeight($record->work_description));
        }

        $sheet->setCellValue("K{$totalRow}", '=SUM(K'.self::FIRST_ROW.":K{$lastRow})");
    }

    private function fillFooter(Worksheet $sheet, int $totalRow): void
    {
        // Jarak blok tanda tangan dari baris Total tetap seperti di template (37 → 40/45/46).
        $titleRow = $totalRow + 3;
        $nameRow = $totalRow + 8;
        $roleRow = $totalRow + 9;

        $sheet->setCellValue("C{$nameRow}", "({$this->user->name})");
        $sheet->setCellValue("C{$roleRow}", $this->user->position ?? '');

        foreach (['D' => 1, 'E' => 2] as $column => $n) {
            $name = $this->user->{"approver{$n}_name"};

            if (filled($name)) {
                $sheet->setCellValue("{$column}{$nameRow}", "({$name})");
                $sheet->setCellValue("{$column}{$roleRow}", $this->user->{"approver{$n}_position"} ?? '');
            }
        }

        $this->placeSignature($sheet, $titleRow + 1);
    }

    private function placeSignature(Worksheet $sheet, int $row): void
    {
        $path = $this->user->signature_path;
        $disk = Storage::disk('local');

        if (blank($path) || ! $disk->exists($path)) {
            return;
        }

        $drawing = new Drawing;
        $drawing->setName('Tanda tangan');
        $drawing->setPath($disk->path($path));
        $drawing->setResizeProportional(true);
        $drawing->setHeight(90);
        $drawing->setCoordinates("C{$row}");
        $drawing->setOffsetX(40);
        $drawing->setWorksheet($sheet);
    }

    /** "21:30:00" → 0.8958…, bentuk jam yang dipahami Excel. */
    private function dayFraction(string $time): float
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return ($h * 60 + $m) / 1440;
    }

    /**
     * Template menampilkan nama berkas evidence yang ditautkan, bukan URL panjang.
     * Bila URL tidak berujung nama berkas, URL-nya sendiri yang ditampilkan.
     */
    private function evidenceLabel(string $url): string
    {
        $segment = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return str_contains($segment, '.') ? $segment : $url;
    }

    private function rowHeight(string $description): float
    {
        $lines = collect(preg_split('/\R/', trim($description)))
            ->sum(fn (string $line) => max(1, (int) ceil(mb_strlen($line) / self::CHARS_PER_LINE)));

        return max(1, $lines) * self::LINE_HEIGHT;
    }
}
