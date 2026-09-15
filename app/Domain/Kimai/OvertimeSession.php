<?php

namespace App\Domain\Kimai;

use App\Domain\Lembur\DurationCalculator;
use Carbon\CarbonImmutable;

/**
 * SY-23 — satu sesi lembur, hasil peleburan beberapa entri timesheet Kimai.
 *
 * Kimai membatasi satu timesheet maksimal 2 jam, jadi lembur 8 jam datang sebagai
 * empat entri. Kelas ini yang membuat keempatnya kembali terbaca sebagai satu sesi,
 * sehingga BR-02/BR-07 menghitung tier di atas durasi yang utuh.
 *
 * Anggota SELALU terurut menurut `begin`. Banyak method di bawah membaca anggota
 * pertama dan terakhir, jadi urutan itu bagian dari kontraknya, bukan kebetulan.
 */
final readonly class OvertimeSession
{
    /** @param array<int, KimaiTimesheet> $entries */
    private function __construct(
        public string $groupKey,
        public CarbonImmutable $anchorDate,
        public array $entries,
    ) {}

    /** @param array<int, KimaiTimesheet> $entries */
    public static function make(string $groupKey, CarbonImmutable $anchorDate, array $entries): self
    {
        usort(
            $entries,
            fn (KimaiTimesheet $a, KimaiTimesheet $b) => [$a->begin, $a->id] <=> [$b->begin, $b->id],
        );

        return new self($groupKey, $anchorDate->startOfDay(), array_values($entries));
    }

    /** Sesi yang sama dengan anggota berbeda — dipakai saat rekonsiliasi (SY-25). */
    public function withEntries(array $entries): self
    {
        return self::make($this->groupKey, $this->anchorDate, $entries);
    }

    public function first(): KimaiTimesheet
    {
        return $this->entries[0];
    }

    public function last(): KimaiTimesheet
    {
        return $this->entries[array_key_last($this->entries)];
    }

    public function startTime(): string
    {
        return $this->first()->startTime();
    }

    public function endTime(): string
    {
        return $this->last()->endTime();
    }

    /**
     * SY-09/SY-10 — dijumlahkan, BUKAN dihitung dari selisih jam. `duration` tiap
     * entri sudah bersih dari `break` masing-masing, dan jeda antar entri sengaja
     * tidak ikut terhitung.
     */
    public function durationMinutes(): int
    {
        return array_sum(array_map(fn (KimaiTimesheet $e) => $e->durationMinutes(), $this->entries));
    }

    /** Rentang jam dinding dari entri pertama sampai terakhir, sadar tengah malam. */
    public function spanMinutes(): int
    {
        return DurationCalculator::rawMinutes($this->startTime(), $this->endTime());
    }

    /**
     * SY-24 — jeda antar entri disimpan sebagai `break_minutes`, bukan dibuang.
     *
     * Dengan begitu KEDUA cabang OvertimeRecord::rawMinutes() tetap menghasilkan
     * angka yang sama: cabang Kimai memakai jumlah durasi langsung, dan cabang
     * fallback menghitung `selisih jam - break` yang persis sama besarnya. Durasi
     * record tidak melompat naik hanya karena user membetulkan deskripsinya.
     */
    public function breakMinutes(): int
    {
        return max(0, $this->spanMinutes() - $this->durationMinutes());
    }

    /** @return array<int, int> */
    public function timesheetIds(): array
    {
        return array_map(fn (KimaiTimesheet $e) => $e->id, $this->entries);
    }

    public function projectId(): ?int
    {
        return $this->first()->projectId;
    }

    public function activityId(): ?int
    {
        return $this->first()->activityId;
    }

    /**
     * SY-09 — deskripsi seluruh anggota, unik dan urut waktu. Entri yang deskripsinya
     * sama persis (lanjutan pekerjaan yang sama) tidak diulang.
     */
    public function workDescription(): string
    {
        $lines = [];

        foreach ($this->entries as $entry) {
            if ($entry->description !== '' && ! in_array($entry->description, $lines, true)) {
                $lines[] = $entry->description;
            }
        }

        if ($lines === []) {
            return $this->first()->workDescription();
        }

        return mb_substr(implode("\n", $lines), 0, 1000);
    }

    /**
     * SY-12 — deep link entri pertama saja. Ini pengisi sementara yang memang wajib
     * diganti user dengan link SPL, jadi menampung N link tidak ada gunanya.
     */
    public function evidenceUrl(): string
    {
        return $this->first()->evidenceUrl();
    }
}
