<?php

namespace App\Domain\Kimai;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Satu entri timesheet dari Kimai, sudah dinormalisasi ke WIB.
 *
 * SY-16 — inilah satu-satunya tempat string tanggal dari Kimai diterjemahkan.
 * Respons membawa offset (`2026-09-03T20:00:00+0700`) sedangkan parameter query
 * tidak; asimetri itu sumber bug timezone yang paling mungkin terjadi. Aturannya:
 * parse BESERTA offset, lalu konversi ke zona aplikasi. Tidak ada substr() pada
 * string tanggal di mana pun — itu cara sebagian besar bug "lembur masuk ke
 * tanggal yang salah" terjadi.
 */
final readonly class KimaiTimesheet
{
    public function __construct(
        public int $id,
        public CarbonImmutable $begin,
        public ?CarbonImmutable $end,
        public int $durationSeconds,
        public int $breakSeconds,
        public string $description,
        public ?int $projectId,
        public ?int $activityId,
        /** @var array<int, string> */
        public array $tags,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, ?string $timezone = null): self
    {
        $timezone ??= config('kimai.timezone');

        return new self(
            id: (int) ($payload['id'] ?? 0),
            begin: self::parse($payload['begin'] ?? null, $timezone) ?? CarbonImmutable::now($timezone),
            end: self::parse($payload['end'] ?? null, $timezone),
            durationSeconds: (int) ($payload['duration'] ?? 0),
            breakSeconds: (int) ($payload['break'] ?? 0),
            description: trim((string) ($payload['description'] ?? '')),
            projectId: isset($payload['project']) ? self::id($payload['project']) : null,
            activityId: isset($payload['activity']) ? self::id($payload['activity']) : null,
            tags: array_values(array_map(
                fn ($tag) => (string) (is_array($tag) ? ($tag['name'] ?? '') : $tag),
                (array) ($payload['tags'] ?? []),
            )),
        );
    }

    /**
     * SY-05 — pencocokan tag case-insensitive dan di-trim, sehingga `overtime`,
     * `Overtime`, dan `OVERTIME ` diperlakukan sama. Diperiksa ULANG di sini
     * meskipun filter sudah dikirim ke API: perilaku filter tag berbeda antar
     * versi Kimai dan pernah menjadi bug, jadi filter server tidak dipercaya.
     *
     * @param  array<int, string>  $wanted
     */
    public function hasAnyTag(array $wanted): bool
    {
        $mine = array_map(fn (string $t) => mb_strtolower(trim($t)), $this->tags);

        foreach ($wanted as $tag) {
            if (in_array(mb_strtolower(trim($tag)), $mine, true)) {
                return true;
            }
        }

        return false;
    }

    /** SY-06 — timer masih berjalan; dilewati tanpa dicatat sebagai error. */
    public function isRunning(): bool
    {
        return $this->end === null;
    }

    /** SY-11 — entri lintas tengah malam diikatkan ke tanggal `begin` (BR-03). */
    public function overtimeDate(): CarbonImmutable
    {
        return $this->begin->startOfDay();
    }

    public function startTime(): string
    {
        return $this->begin->format('H:i');
    }

    public function endTime(): string
    {
        return ($this->end ?? $this->begin)->format('H:i');
    }

    /** SY-09 — `duration / 60`, dibulatkan ke bawah. */
    public function durationMinutes(): int
    {
        return intdiv($this->durationSeconds, 60);
    }

    public function breakMinutes(): int
    {
        return intdiv($this->breakSeconds, 60);
    }

    /** SY-09 — deskripsi kosong tetap menghasilkan teks yang bisa dibaca manusia. */
    public function workDescription(): string
    {
        return $this->description !== ''
            ? $this->description
            : "Lembur dari Kimai #{$this->id}";
    }

    /** SY-12 — deep link ke timesheet sumbernya. */
    public function evidenceUrl(): string
    {
        return rtrim((string) config('kimai.base_url'), '/')."/en/timesheet/{$this->id}/edit";
    }

    private static function parse(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            // Offset pada nilai respons ikut terbaca di sini, lalu dibuang setelah
            // dikonversi. Melewatkan langkah konversi berarti jam 00:30 WIB bisa
            // mendarat di tanggal sebelumnya.
            return CarbonImmutable::parse((string) $value)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /** Kimai mengirim project/activity kadang sebagai id, kadang sebagai objek. */
    private static function id(mixed $value): ?int
    {
        if (is_array($value)) {
            return isset($value['id']) ? (int) $value['id'] : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** SY-16 — parameter query dikirim sebagai waktu lokal TANPA offset. */
    public static function formatQueryDate(CarbonInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }
}
