<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\OvertimeNotSyncable;
use App\Domain\Lembur\DurationCalculator;
use App\Models\OvertimeRecord;
use Carbon\CarbonImmutable;

/**
 * Meneruskan hapus dan ubah lembur ke Kimai.
 *
 * Dipanggil dari lapisan UI, BUKAN dari observer model: regroup, sync, dan
 * SessionWriter juga menghapus atau mengubah record, dan itu tidak boleh pernah
 * menyentuh Kimai. Kimai dikerjakan LEBIH DULU; kalau gagal, pemanggil membatalkan
 * perubahan lokalnya sehingga app dan Kimai tidak pernah berbeda.
 *
 * Record tanpa entri Kimai (input manual) tidak menyentuh Kimai sama sekali.
 */
class OvertimeKimaiWriter
{
    /** Field lembur yang punya padanan di entri Kimai. */
    private const SYNCED = ['overtime_date', 'start_time', 'end_time', 'work_description'];

    public function __construct(private readonly KimaiClient $client) {}

    /** @return array<int, int> */
    public function timesheetIds(OvertimeRecord $record): array
    {
        $ids = $record->kimaiEntries->pluck('kimai_timesheet_id')->all();

        if ($ids === [] && $record->isFromKimai() && $record->kimai_timesheet_id !== null) {
            $ids = [$record->kimai_timesheet_id];
        }

        return array_map('intval', $ids);
    }

    /** Hapus semua entri Kimai milik record. 404 dianggap sudah terhapus, jadi aman diulang. */
    public function delete(OvertimeRecord $record): void
    {
        $ids = $this->timesheetIds($record);

        if ($ids === []) {
            return;
        }

        $token = $this->token($record);

        foreach ($ids as $id) {
            $this->client->deleteTimesheet($token, $id);
        }
    }

    /**
     * Terapkan isian form ke entri Kimai. Hanya jalan kalau ada field yang punya
     * padanan di Kimai yang benar-benar berubah; status, catatan, dan evidence
     * tidak pernah memanggil Kimai.
     *
     * @param  array<string, mixed>  $data
     */
    public function push(OvertimeRecord $record, array $data): void
    {
        $ids = $this->timesheetIds($record);
        $changed = $this->changedFields($record, $data);

        if ($ids === [] || $changed === []) {
            return;
        }

        $timeChanged = array_intersect($changed, ['overtime_date', 'start_time', 'end_time']) !== [];

        if ($timeChanged && count($ids) > 1) {
            throw new OvertimeNotSyncable(
                'Lembur ini gabungan '.count($ids).' entri Kimai, jadi tanggal dan jamnya tidak bisa '
                .'diubah dari sini. Hapus lembur ini lalu isi ulang, atau ubah jamnya langsung di Kimai.'
            );
        }

        $payload = [];

        if ($timeChanged) {
            $date = (string) ($data['overtime_date'] ?? $record->overtime_date->toDateString());
            $start = $this->clock($data['start_time'] ?? $record->start_time);
            $end = $this->clock($data['end_time'] ?? $record->end_time);

            // Dibangun di zona Kimai: jam dinding yang diketik user adalah jam Kimai.
            $begin = CarbonImmutable::parse("{$date} {$start}", (string) config('kimai.timezone'));

            $payload['begin'] = $begin->format('Y-m-d\TH:i:sO');
            $payload['end'] = $begin->addMinutes(DurationCalculator::rawMinutes($start, $end))->format('Y-m-d\TH:i:sO');
        }

        if (in_array('work_description', $changed, true)) {
            $payload['description'] = trim((string) $data['work_description']);
        }

        $token = $this->token($record);

        foreach ($ids as $id) {
            $updated = KimaiTimesheet::fromPayload($this->client->updateTimesheet($token, $id, $payload));

            // Baris anggota ikut diperbarui supaya dedup sync tetap cocok dengan Kimai.
            $record->kimaiEntries()->where('kimai_timesheet_id', $id)->update([
                'begin' => $updated->begin,
                'end' => $updated->end ?? $updated->begin,
                'duration_minutes' => (int) round($updated->durationSeconds / 60),
                'description' => $updated->description,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function changedFields(OvertimeRecord $record, array $data): array
    {
        $now = [
            'overtime_date' => $record->overtime_date?->toDateString(),
            'start_time' => $this->clock($record->start_time),
            'end_time' => $this->clock($record->end_time),
            'work_description' => trim((string) $record->work_description),
        ];

        $changed = [];

        foreach (self::SYNCED as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $new = match ($field) {
                'overtime_date' => CarbonImmutable::parse($data[$field])->toDateString(),
                'work_description' => trim((string) $data[$field]),
                default => $this->clock($data[$field]),
            };

            if ($new !== $now[$field]) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function clock(mixed $time): string
    {
        return substr((string) $time, 0, 5);
    }

    private function token(OvertimeRecord $record): string
    {
        $user = $record->user;

        if ($user === null || ! $user->hasKimaiConnection()) {
            throw new OvertimeNotSyncable(
                'Lembur ini punya entri di Kimai, tapi pemiliknya belum menghubungkan API key Kimai. '
                .'Hubungkan dulu di Preferensi.'
            );
        }

        return (string) $user->kimai_api_token;
    }
}
