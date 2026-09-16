<?php

namespace App\Domain\Kimai;

use App\Domain\Lembur\DurationCalculator;
use App\Domain\Lembur\RuleResolver;
use Carbon\CarbonImmutable;

/**
 * SY-23 — mengelompokkan entri timesheet Kimai menjadi sesi lembur.
 *
 * Kimai membatasi satu timesheet maksimal 2 jam. Tanpa pengelompokan, lembur 8 jam
 * masuk sebagai empat record terpisah — dan yang melewati tengah malam bahkan pecah
 * ke dua tanggal, sehingga tier-nya salah dan tanggal berikutnya dapat hak yang
 * tidak punya dasar.
 *
 * Batas jamnya dibaca dari `overtime_rules.work_start_time` / `work_end_time`, kolom
 * yang sudah lama ada di skema tapi belum pernah dibaca kode mana pun. Versi aturan
 * selalu diambil dari TANGGAL ANCHOR — versi yang sama yang nanti dipakai
 * OvertimeRecordObserver dan OvertimeDayCalculator untuk record itu, sehingga
 * pengelompokan dan perhitungan tidak mungkin memakai batas yang berbeda (BR-24).
 *
 * Kelas ini murni: tidak menyentuh database selain membaca versi aturan, dan tidak
 * tahu apa-apa soal HTTP.
 */
class SessionGrouper
{
    private const WEEKDAY_PREFIX = 'we:';

    private const WEEKEND_PREFIX = 'wk:';

    private const STANDALONE_PREFIX = 'ts:';

    public function __construct(private readonly RuleResolver $rules) {}

    /**
     * @param  array<int, KimaiTimesheet>  $entries
     * @param  array<int, string>  $knownGroupKeys  kunci grup yang sudah tersimpan di database
     * @return array<string, OvertimeSession> dikunci groupKey
     */
    public function group(array $entries, array $knownGroupKeys = []): array
    {
        $openers = $this->openers($entries, $knownGroupKeys);

        $buckets = [];

        foreach ($entries as $entry) {
            [$key, $anchor] = $this->placement($entry, $openers);

            $buckets[$key] ??= ['anchor' => $anchor, 'entries' => []];
            $buckets[$key]['entries'][] = $entry;
        }

        $sessions = [];

        foreach ($buckets as $key => $bucket) {
            $sessions[$key] = OvertimeSession::make($key, $bucket['anchor'], $bucket['entries']);
        }

        return $sessions;
    }

    /**
     * Entri yang pada putaran ini dimiliki sesi LAIN.
     *
     * Terjadi ketika jam sebuah entri diperbaiki di Kimai sehingga ia keluar dari
     * jendela lamanya. Tanpa daftar ini, sesi lamanya akan tetap memeluknya dan
     * lembur yang sama terhitung di dua record (BR-02).
     *
     * @param  array<string, OvertimeSession>  $sessions  seluruh sesi pada putaran ini
     * @return array<int, int>
     */
    public function claimedElsewhere(array $sessions, OvertimeSession $session): array
    {
        $others = [];

        foreach ($sessions as $other) {
            if ($other->groupKey !== $session->groupKey) {
                $others = array_merge($others, $other->timesheetIds());
            }
        }

        return array_values(array_diff($others, $session->timesheetIds()));
    }

    /**
     * Lintasan 1 — jendela weekday hanya "terbuka" kalau ada entri yang MEMBUKANYA,
     * yaitu entri yang mulai pada atau setelah jam pulang di hari kerja.
     *
     * Tanpa syarat ini, entri Kamis jam 08:00 akan tertarik ke Rabu hanya karena
     * secara harfiah jatuh di jendela "Rabu 18:00 → Kamis 09:00", padahal Rabu malam
     * tidak ada lembur sama sekali. Lembur akan pindah ke tanggal yang salah.
     *
     * `$knownGroupKeys` membawa pembuka yang sudah masuk di sync sebelumnya dan tidak
     * ikut ditarik kali ini — tanpa itu, sync yang memajukan watermark lewat tengah
     * malam membuat entri pagi kehilangan jejak sesinya.
     *
     * @param  array<int, KimaiTimesheet>  $entries
     * @param  array<int, string>  $knownGroupKeys
     * @return array<string, true>
     */
    private function openers(array $entries, array $knownGroupKeys): array
    {
        $openers = $this->normalizeOpeners($knownGroupKeys);

        foreach ($entries as $entry) {
            $date = $entry->begin;

            if ($this->isWeekend($date)) {
                continue;
            }

            if ($this->clockMinutes($date) >= $this->workEndMinutes($date)) {
                $openers[self::WEEKDAY_PREFIX.$date->toDateString()] = true;
            }
        }

        return $openers;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, true>
     */
    private function normalizeOpeners(array $keys): array
    {
        $openers = [];

        foreach ($keys as $key) {
            if (str_starts_with((string) $key, self::WEEKDAY_PREFIX)) {
                $openers[(string) $key] = true;
            }
        }

        return $openers;
    }

    /**
     * Lintasan 2 — cabang pertama yang cocok menang. URUTANNYA yang menegakkan
     * aturan batas hari, jadi jangan ditukar:
     *
     *   Jumat 18:00 → Sabtu 03:00  = SATU sesi di Jumat  (cabang 1 menang atas 2)
     *   Minggu 20:00 → Senin 02:00 = DUA sesi            (cabang 1 gugur: Minggu weekend)
     *
     * @param  array<string, true>  $openers
     * @return array{0: string, 1: CarbonImmutable}
     */
    private function placement(KimaiTimesheet $entry, array $openers): array
    {
        $date = $entry->begin;
        $previous = $date->subDay();

        // 1 — lanjutan jendela weekday yang dibuka kemarin.
        if (! $this->isWeekend($previous)
            && isset($openers[self::WEEKDAY_PREFIX.$previous->toDateString()])
            && $this->clockMinutes($date) < $this->workStartMinutes($previous)) {
            return [self::WEEKDAY_PREFIX.$previous->toDateString(), $previous];
        }

        // 2 — weekend: seluruh hari kalender satu jendela.
        if ($this->isWeekend($date)) {
            return [self::WEEKEND_PREFIX.$date->toDateString(), $date];
        }

        // 3 — jendela weekday yang dibuka hari ini.
        if ($this->clockMinutes($date) >= $this->workEndMinutes($date)) {
            return [self::WEEKDAY_PREFIX.$date->toDateString(), $date];
        }

        // 4 — entri weekday di dalam jam kerja: berdiri sendiri, tidak digabung.
        return [self::STANDALONE_PREFIX.$entry->id, $date];
    }

    /**
     * BR-08 — hari libur nasional sengaja tidak dibedakan dari hari kerja, jadi
     * "weekend" di sini benar-benar hanya Sabtu dan Minggu (lihat OQ-1 di README).
     */
    private function isWeekend(CarbonImmutable $date): bool
    {
        return $date->isSaturday() || $date->isSunday();
    }

    private function clockMinutes(CarbonImmutable $moment): int
    {
        return $moment->hour * 60 + $moment->minute;
    }

    private function workStartMinutes(CarbonImmutable $anchor): int
    {
        return DurationCalculator::toMinutes(
            (string) $this->rules->forDate($anchor->startOfDay())->work_start_time,
        );
    }

    private function workEndMinutes(CarbonImmutable $anchor): int
    {
        return DurationCalculator::toMinutes(
            (string) $this->rules->forDate($anchor->startOfDay())->work_end_time,
        );
    }
}
