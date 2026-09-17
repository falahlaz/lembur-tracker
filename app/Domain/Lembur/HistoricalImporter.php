<?php

namespace App\Domain\Lembur;

use App\Enums\OvertimeStatus;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Support\ExcelValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * OQ-4 — impor lembur historis dari sebelum sistem ini dipakai.
 *
 * Selalu dua langkah: `parse()` menghasilkan pratinjau tanpa menulis apa pun,
 * `commit()` baru menyimpan. Impor adalah operasi yang paling mahal untuk
 * dibatalkan — data lama masuk, saldo terbentuk, sebagian langsung hangus —
 * jadi user harus melihat hasilnya lebih dulu.
 *
 * Akrual dan kedaluwarsa dihitung mundur memakai tanggal aslinya, sehingga
 * batch yang masa berlakunya sudah lewat langsung berstatus hangus dan ikut
 * terhitung di tab "Saldo hangus" — bukan muncul sebagai saldo hidup palsu.
 */
class HistoricalImporter
{
    public const HEADERS = ['tanggal', 'jam_mulai', 'jam_selesai', 'deskripsi', 'evidence_url', 'status', 'catatan'];

    public function __construct(
        private readonly OvertimeDayCalculator $calculator,
        private readonly ExpiryCalculator $expiry,
        private readonly RuleResolver $rules,
    ) {}

    /**
     * @param  array<int, array<int|string, mixed>>  $rows  baris mentah, baris pertama = header
     * @return Collection<int, ImportedRow>
     */
    public function parse(User $user, array $rows): Collection
    {
        $rows = array_values(array_filter($rows, fn ($r) => array_filter($r, fn ($v) => filled($v)) !== []));

        if ($rows === []) {
            return collect();
        }

        $header = array_map(
            fn ($h) => str($h)->lower()->trim()->replace(' ', '_')->toString(),
            array_values($rows[0]),
        );

        $parsed = collect();
        // Tanggal+jam yang sudah dipakai baris lain di berkas yang sama, supaya
        // duplikat di dalam satu berkas ikut ketahuan, bukan hanya bentrok dengan DB.
        $seen = [];

        foreach (array_slice($rows, 1) as $index => $raw) {
            $data = $this->associate($header, array_values($raw));
            $parsed->push($this->buildRow($user, $data, $index + 2, $seen));
        }

        return $parsed;
    }

    /**
     * @param  Collection<int, ImportedRow>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function commit(User $user, Collection $rows): array
    {
        $valid = $rows->filter(fn (ImportedRow $r) => $r->isValid());

        DB::transaction(function () use ($user, $valid) {
            foreach ($valid as $row) {
                OvertimeRecord::query()->create([
                    'user_id' => $user->id,
                    'overtime_date' => $row->date,
                    'start_time' => $row->startTime,
                    'end_time' => $row->endTime,
                    'work_description' => $row->description,
                    'evidence_url' => $row->evidenceUrl,
                    'status' => $row->status ?? OvertimeStatus::Approved->value,
                    'notes' => $row->notes,
                    'created_by_id' => auth()->id(),
                ]);
            }
        });

        // Batch yang masa berlakunya sudah lewat ditandai hangus sekarang juga,
        // supaya tidak sempat tampil sebagai saldo aktif yang menyesatkan.
        app(BalanceMaintenance::class)->markExpiredBatches();

        return [
            'imported' => $valid->count(),
            'skipped' => $rows->count() - $valid->count(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function buildRow(User $user, array $data, int $line, array &$seen): ImportedRow
    {
        $date = $this->normaliseDate($data['tanggal'] ?? null);
        $start = $this->normaliseTime($data['jam_mulai'] ?? null);
        $end = $this->normaliseTime($data['jam_selesai'] ?? null);

        $errors = $this->validateRow($data, $date, $start, $end);

        // Duplikat, baik terhadap database maupun terhadap baris lain di berkas ini.
        if ($errors === [] && $date !== null && $start !== null) {
            $key = $date.' '.$start;

            if (isset($seen[$key])) {
                $errors[] = "Duplikat dengan baris {$seen[$key]} di berkas ini.";
            } elseif (OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->whereDate('overtime_date', $date)
                ->where('start_time', $start)
                ->exists()) {
                $errors[] = 'Sudah ada lembur tercatat di tanggal dan jam ini.';
            } else {
                $seen[$key] = $line;
            }
        }

        $preview = null;
        $expired = false;

        if ($errors === [] && $date !== null && $start !== null && $end !== null) {
            $carbonDate = Date::parse($date);
            $preview = $this->calculator->preview($user, $carbonDate, $start, $end);
            $expired = $preview->qualifies()
                && $this->expiry->expiryFor($carbonDate, $this->rules->forDate($carbonDate))->lt(today());
        }

        return new ImportedRow(
            lineNumber: $line,
            date: $date,
            startTime: $start,
            endTime: $end,
            description: $this->stringOrNull($data['deskripsi'] ?? null),
            evidenceUrl: $this->stringOrNull($data['evidence_url'] ?? null),
            status: $this->normaliseStatus($data['status'] ?? null),
            notes: $this->stringOrNull($data['catatan'] ?? null),
            errors: $errors,
            preview: $preview,
            alreadyExpired: $expired,
        );
    }

    /** @return array<string> */
    private function validateRow(array $data, ?string $date, ?string $start, ?string $end): array
    {
        $errors = [];

        if ($date === null) {
            $errors[] = 'Tanggal kosong atau tidak bisa dibaca.';
        } elseif (Date::parse($date)->gt(today())) {
            $errors[] = 'Tanggal lembur berada di masa depan.';
        }

        if ($start === null) {
            $errors[] = 'Jam mulai kosong atau tidak bisa dibaca.';
        }

        if ($end === null) {
            $errors[] = 'Jam selesai kosong atau tidak bisa dibaca.';
        }

        if ($start !== null && $start === $end) {
            $errors[] = 'Jam mulai dan selesai sama, durasinya nol.';
        }

        $description = $this->stringOrNull($data['deskripsi'] ?? null);
        if ($description === null || mb_strlen($description) < 10) {
            $errors[] = 'Deskripsi pekerjaan minimal 10 karakter.';
        }

        $url = $this->stringOrNull($data['evidence_url'] ?? null);
        if ($url === null || Validator::make(['u' => $url], ['u' => 'url'])->fails()) {
            $errors[] = 'URL evidence tidak valid.';
        }

        return $errors;
    }

    /** @param  array<string>  $header */
    private function associate(array $header, array $values): array
    {
        $data = [];

        foreach ($header as $i => $key) {
            $data[$key] = $values[$i] ?? null;
        }

        return $data;
    }

    /**
     * Seluruh penanganannya — serial Excel, "15/01/2026", fallback Carbon —
     * hidup di ExcelValue supaya importer timesheet memakai yang sama persis.
     */
    private function normaliseDate(mixed $value): ?string
    {
        return ExcelValue::toDate($value)?->toDateString();
    }

    private function normaliseTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        // Excel menyimpan jam sebagai PECAHAN satu hari, jadi selalu di bawah 1.
        // "19.00" juga lolos is_numeric() tetapi jelas bukan itu maksudnya —
        // tanpa batas ini ia berubah jadi 00:00 tanpa suara.
        if (is_numeric($value) && (float) $value < 1) {
            $minutes = (int) round(((float) $value) * 24 * 60);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }

        $text = trim((string) $value);

        if (preg_match('/^(\d{1,2})[:.](\d{2})/', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];

            return $hour <= 23 && $minute <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
        }

        try {
            return Date::parse($text)->format('H:i');
        } catch (Throwable) {
            return null;
        }
    }

    private function normaliseStatus(mixed $value): ?string
    {
        if (blank($value)) {
            // Data historis pada umumnya sudah selesai diproses HRD.
            return OvertimeStatus::Approved->value;
        }

        $text = str($value)->lower()->trim()->toString();

        return match ($text) {
            'dicatat', 'recorded' => OvertimeStatus::Recorded->value,
            'diajukan', 'submitted' => OvertimeStatus::Submitted->value,
            'ditolak', 'rejected' => OvertimeStatus::Rejected->value,
            default => OvertimeStatus::Approved->value,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
