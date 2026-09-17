<?php

namespace App\Domain\Timesheet;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Kimai\KimaiClient;
use App\Domain\Kimai\KimaiTimesheet;
use App\Enums\UploadEntryStatus;
use App\Models\TimesheetUploadEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Menandai entri yang slotnya sudah terisi, SEBELUM satu pun POST dikirim.
 *
 * Kimai tidak punya idempotency key: mengirim ulang berkas yang sama menghasilkan
 * entri ganda, dan tidak ada cara membatalkannya selain menghapus satu per satu.
 * Importer lama memang tidak punya pengaman apa pun untuk ini.
 *
 * Bentrok di dalam berkas yang sama sudah ditangani parser; di sini yang diperiksa
 * dunia luar: entri yang sudah ada di Kimai, dan entri yang sudah pernah kita
 * kirim sendiri.
 */
class DuplicateDetector
{
    public function __construct(private readonly KimaiClient $client) {}

    public function mark(User $user, ParsedWorkbook $book): DuplicateCheckResult
    {
        $entries = $book->postable();

        if ($entries === []) {
            return DuplicateCheckResult::checked(0, 0);
        }

        // Didahulukan karena gratis dan tidak bisa gagal: kalau Kimai sedang tidak
        // terjangkau, ini tetap menangkap pengiriman ulang berkas yang sama.
        $local = $this->markPreviouslyPosted($user, $entries);

        $range = $this->range($book);

        try {
            $existing = $this->client->timesheetsInRange(
                token: (string) $user->kimai_api_token,
                begin: $range[0],
                end: $range[1],
                // TANPA filter tag: slot 09:00–10:00 yang sudah terisi entri Daily
                // tanpa tag tetap slot yang terpakai. Tanpa filter project juga —
                // satu jam yang sama tidak bisa dikerjakan di dua project sekaligus.
                tags: [],
                // Timer yang masih berjalan pun menempati jamnya.
                onlyStopped: false,
            );
        } catch (KimaiException $e) {
            return DuplicateCheckResult::unavailable($e->userMessage(), $local);
        }

        return DuplicateCheckResult::checked($this->markAgainstKimai($entries, $existing), $local);
    }

    /**
     * @param  array<int, ParsedEntry>  $entries
     * @param  array<int, KimaiTimesheet>  $existing
     */
    private function markAgainstKimai(array $entries, array $existing): int
    {
        $occupied = [];

        foreach ($existing as $entry) {
            $occupied[] = [
                'begin' => $entry->begin->getTimestamp(),
                // Timer yang belum berhenti dianggap masih berjalan sampai sekarang.
                'end' => ($entry->end ?? CarbonImmutable::now(config('kimai.timezone')))->getTimestamp(),
                'id' => $entry->id,
            ];
        }

        $found = 0;

        foreach ($entries as $entry) {
            if (! $entry->isPostable()) {
                continue;
            }

            // Dibandingkan sebagai instan absolut, tidak pernah sebagai string jam.
            // Perbandingan string akan tumbang di setiap slot yang melewati tengah
            // malam, dan tumbangnya diam-diam.
            $begin = $entry->beginAt->getTimestamp();
            $end = $entry->endAt->getTimestamp();

            foreach ($occupied as $slot) {
                if ($slot['begin'] === $begin && $slot['end'] === $end) {
                    $entry->skip("Sudah ada di Kimai (#{$slot['id']}).", kimaiId: $slot['id']);
                    $found++;

                    continue 2;
                }

                if ($slot['begin'] < $end && $slot['end'] > $begin) {
                    // Tumpang tindih sebagian bisa saja disengaja, jadi user boleh
                    // menimpanya — beda dari yang persis sama.
                    $entry->skip(
                        "Bentrok sebagian dengan timesheet Kimai #{$slot['id']}.",
                        overridable: true,
                        kimaiId: $slot['id'],
                    );
                    $found++;

                    continue 2;
                }
            }
        }

        return $found;
    }

    /**
     * Slot yang sudah pernah BERHASIL kita kirim dari upload sebelumnya. Murni
     * database, jadi tetap bekerja saat Kimai tidak bisa dihubungi.
     *
     * @param  array<int, ParsedEntry>  $entries
     */
    private function markPreviouslyPosted(User $user, array $entries): int
    {
        $posted = TimesheetUploadEntry::query()
            ->where('user_id', $user->id)
            ->where('status', UploadEntryStatus::Posted->value)
            ->get(['begin_at', 'end_at', 'activity_id', 'kimai_timesheet_id'])
            ->keyBy(fn (TimesheetUploadEntry $e) => $this->key(
                $e->begin_at->getTimestamp(),
                $e->end_at->getTimestamp(),
                (int) $e->activity_id,
            ));

        if ($posted->isEmpty()) {
            return 0;
        }

        $found = 0;

        foreach ($entries as $entry) {
            if (! $entry->isPostable()) {
                continue;
            }

            $match = $posted->get($this->key(
                $entry->beginAt->getTimestamp(),
                $entry->endAt->getTimestamp(),
                (int) $entry->activityId,
            ));

            if ($match !== null) {
                $entry->skip('Sudah pernah diupload dari sini.', kimaiId: $match->kimai_timesheet_id);
                $found++;
            }
        }

        return $found;
    }

    private function key(int $begin, int $end, int $activity): string
    {
        return "{$begin}|{$end}|{$activity}";
    }

    /**
     * Batas bawah dimundurkan sehari: parameter begin/end Kimai menyaring
     * berdasarkan waktu MULAI entri, jadi entri yang mulai pukul 22:00 kemarin dan
     * baru selesai pukul 02:00 hari ini tidak akan terlihat tanpa pelebaran ini —
     * padahal ia jelas menempati jam yang mau kita isi.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(ParsedWorkbook $book): array
    {
        $zone = config('kimai.timezone');

        return [
            $book->rangeStart()->setTimezone($zone)->subDay()->startOfDay(),
            $book->rangeEnd()->setTimezone($zone)->addDay()->endOfDay(),
        ];
    }
}
