<?php

namespace App\Domain\Timesheet;

use Carbon\CarbonImmutable;

/**
 * Satu sel terisi di workbook — calon satu entri timesheet Kimai.
 *
 * Bukan readonly penuh: pemeriksaan bentrok (di dalam berkas maupun terhadap
 * Kimai) menandai entri SESUDAH parsing, dan menyalin seluruh objek hanya untuk
 * menambahkan satu alasan lebih berisik daripada gunanya.
 */
final class ParsedEntry
{
    public function __construct(
        /** 'Daily' | 'Overtime' — nilai DB bahasa Inggris, seperti enum lain di app ini. */
        public readonly string $sheet,
        /** 'Overtime!F14' — jejak balik ke sel aslinya, supaya kesalahan bisa ditelusuri. */
        public readonly string $cellRef,
        /** Label kolom A apa adanya, untuk ditampilkan di pratinjau. */
        public readonly string $slotLabel,
        /** Tanggal KOLOM. Beda dari tanggal endAt untuk slot yang tutup jam 24. */
        public readonly CarbonImmutable $workDate,
        public readonly CarbonImmutable $beginAt,
        public readonly CarbonImmutable $endAt,
        /** Terisi kalau sel memakai format lama `Activity ID: N`, atau setelah nama diresolusi. */
        public ?int $activityId,
        /** Terisi kalau sel memakai `Activity: <nama>`; diresolusi jadi id oleh ActivityResolver. */
        public readonly ?string $activityName,
        public readonly string $description,
        /** 'Overtime' atau NULL — tidak pernah string kosong, lihat toKimaiPayload(). */
        public readonly ?string $tag,
        /** @var array<int, string> */
        public array $errors = [],
        public ?string $skipReason = null,
        public bool $overridable = false,
        public ?int $conflictingKimaiId = null,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isPostable(): bool
    {
        // activityId null berarti namanya belum (atau tidak bisa) diresolusi;
        // entri seperti itu tidak boleh pernah sampai ke toKimaiPayload().
        return $this->isValid() && $this->skipReason === null && $this->activityId !== null;
    }

    public function durationMinutes(): int
    {
        return (int) $this->beginAt->diffInMinutes($this->endAt);
    }

    public function skip(string $reason, bool $overridable = false, ?int $kimaiId = null): void
    {
        $this->skipReason = $reason;
        $this->overridable = $overridable;
        $this->conflictingKimaiId = $kimaiId;
    }

    /**
     * SATU-SATUNYA tempat body POST Kimai disusun.
     *
     * API-nya galak dan kegalakannya tidak intuitif, jadi aturannya dikurung di
     * sini alih-alih dipercayakan pada pemanggil:
     *
     *   - Field di luar kelima ini ditolak: "This form should not contain extra
     *     fields". `billable` termasuk — server yang menentukannya sendiri.
     *   - `tags: []` ditolak: "This value is not valid." Kalau tidak ada tag,
     *     key-nya harus HILANG, bukan array atau string kosong.
     *   - `tags` adalah STRING dipisah koma, meskipun GET mengembalikannya
     *     sebagai array.
     *   - Offset ditulis `+0700`, bukan `+07:00`; toIso8601String() memberi yang
     *     kedua dan tidak bisa dipakai di sini.
     *
     * @return array{begin: string, end: string, project: int, activity: int, description: string, tags?: string}
     */
    public function toKimaiPayload(int $projectId): array
    {
        $payload = [
            'begin' => $this->beginAt->format('Y-m-d\TH:i:sO'),
            'end' => $this->endAt->format('Y-m-d\TH:i:sO'),
            'project' => $projectId,
            'activity' => (int) $this->activityId,
            'description' => $this->description,
        ];

        if (filled($this->tag)) {
            $payload['tags'] = $this->tag;
        }

        return $payload;
    }
}
