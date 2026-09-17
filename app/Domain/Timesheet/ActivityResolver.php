<?php

namespace App\Domain\Timesheet;

/**
 * Menerjemahkan nama activity di sheet menjadi id Kimai.
 *
 * Dipisahkan dari parser karena parser murni — ia tidak menyentuh jaringan —
 * sementara daftar activity hanya bisa didapat dari Kimai, dan bergantung pada
 * project mana yang dipilih.
 */
class ActivityResolver
{
    /**
     * Menandai entri di tempat. Mengembalikan jumlah yang GAGAL diresolusi.
     *
     * @param  array<int, ParsedEntry>  $entries
     * @param  array<int, array{id: int, name: string, project: ?int}>  $activities
     */
    public function resolve(array $entries, array $activities, string $projectLabel): int
    {
        $index = $this->index($activities);
        $gagal = 0;

        foreach ($entries as $entry) {
            if ($entry->activityName === null || $entry->activityId !== null) {
                continue;
            }

            $cocok = $this->lookup($index, $entry->activityName);

            if ($cocok === []) {
                $entry->skip(sprintf(
                    'Activity "%s" tidak ada di project %s.',
                    $entry->activityName,
                    $projectLabel,
                ));
                $gagal++;

                continue;
            }

            if (count($cocok) > 1) {
                // Menebak salah satu berarti jam kerja masuk ke activity yang
                // keliru tanpa ada yang tahu. Lebih baik berhenti dan menyebutkan
                // keduanya.
                $entry->skip(sprintf(
                    'Activity "%s" cocok ke lebih dari satu (id %s) — perjelas namanya.',
                    $entry->activityName,
                    implode(', ', $cocok),
                ));
                $gagal++;

                continue;
            }

            $entry->activityId = $cocok[0];
        }

        return $gagal;
    }

    /**
     * Tiga tingkat kunci untuk satu activity: apa adanya, huruf kecil, dan
     * dinormalkan. Nilainya daftar id, supaya nama yang ambigu ketahuan alih-alih
     * saling menimpa diam-diam.
     *
     * @param  array<int, array{id: int, name: string, project: ?int}>  $activities
     * @return array<string, array<int, int>>
     */
    private function index(array $activities): array
    {
        $index = [];

        foreach ($activities as $activity) {
            $name = trim((string) $activity['name']);

            if ($name === '') {
                continue;
            }

            foreach ($this->keys($name) as $level => $key) {
                $index[$level][$key][] = (int) $activity['id'];
            }
        }

        return $index;
    }

    /** @return array<int, int> id yang cocok pada tingkat pertama yang menghasilkan sesuatu */
    private function lookup(array $index, string $name): array
    {
        $name = trim($name);

        foreach ($this->keys($name) as $level => $key) {
            $hit = $index[$level][$key] ?? [];

            if ($hit !== []) {
                return array_values(array_unique($hit));
            }
        }

        return [];
    }

    /**
     * Kunci per tingkat kecocokan, dari paling ketat ke paling longgar.
     *
     * Tingkat ketiga membuang spasi, underscore, dan strip sehingga
     * "31_DEV_FEATURE" dan "31 dev feature" dianggap sama — itu perbedaan yang
     * lahir dari menyalin nama dengan tangan, bukan perbedaan activity.
     *
     * @return array<int, string>
     */
    private function keys(string $name): array
    {
        return [
            0 => $name,
            1 => mb_strtolower($name),
            2 => preg_replace('/[\s_\-]+/u', '', mb_strtolower($name)),
        ];
    }
}
