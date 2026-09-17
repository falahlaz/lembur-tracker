<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Models\KimaiActivity;
use App\Models\KimaiProject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Menarik seluruh katalog Kimai — project dan activity — ke cermin lokal.
 *
 * Dijalankan MANUAL oleh admin, bukan terjadwal. Project dan activity berganti
 * hitungan bulan sekali; scheduler harian untuk itu hanya menambah satu bagian
 * yang bisa rusak diam-diam tanpa ada yang menunggunya, sementara satu tombol
 * sudah menjawab kebutuhan yang sebenarnya ("ada activity baru, tolong muncul").
 *
 * Pakai token milik admin yang menekannya: daftar yang terlihat bergantung pada
 * team dan permission pemilik token, dan admin adalah yang paling mungkin melihat
 * seluruhnya.
 */
class KimaiCatalogSync
{
    /** Batas aman jumlah baris per pernyataan upsert di SQLite. */
    private const CHUNK = 500;

    public function __construct(private readonly KimaiClient $client) {}

    /** @throws KimaiException */
    public function run(User $admin): KimaiCatalogSyncResult
    {
        $token = (string) $admin->kimai_api_token;
        $stamp = CarbonImmutable::now();

        // SELURUH pengambilan diselesaikan sebelum satu baris pun ditulis. Kalau
        // Kimai mati di tengah jalan, exception-nya naik sebelum transaksi dibuka —
        // sehingga langkah pemangkasan tidak pernah jalan, dan cermin yang lama
        // tetap utuh alih-alih terpangkas habis oleh daftar yang cuma separuh.
        $projects = $this->client->projects($token);
        $activities = $this->ambilActivity($token);

        return DB::transaction(function () use ($projects, $activities, $stamp) {
            return new KimaiCatalogSyncResult(
                projects: $this->tulis(
                    KimaiProject::class,
                    $projects,
                    fn (array $row) => [
                        'kimai_id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'customer' => $row['customer'],
                        'synced_at' => $stamp,
                    ],
                    ['name', 'customer', 'synced_at'],
                ),
                activities: $this->tulis(
                    KimaiActivity::class,
                    $activities,
                    fn (array $row) => [
                        'kimai_id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                        'project_id' => $row['project'],
                        'synced_at' => $stamp,
                    ],
                    ['name', 'project_id', 'synced_at'],
                ),
                syncedAt: $stamp,
            );
        });
    }

    /**
     * Activity diambil DUA KALI lalu digabung: tanpa filter, dan khusus yang global.
     *
     * Persis alasan yang sudah ditulis di KimaiClient::activities() — apakah
     * permintaan tanpa filter ikut memuat activity global BERBEDA antar versi Kimai,
     * dan dokumentasi instance ini tidak bisa diakses dari luar. Menebak salah
     * berarti seluruh activity global hilang dari legenda tanpa ada yang sadar.
     * Satu permintaan tambahan yang mungkin mubazir jauh lebih murah daripada itu;
     * dedupe per id membuat tumpang tindihnya tidak berbahaya.
     *
     * @return array<int, array{id: int, name: string, project: ?int}>
     *
     * @throws KimaiException
     */
    private function ambilActivity(string $token): array
    {
        $merged = [];

        foreach ([
            $this->client->activities($token),
            $this->client->activities($token, globalsOnly: true),
        ] as $batch) {
            foreach ($batch as $activity) {
                $merged[$activity['id']] = $activity;
            }
        }

        return array_values($merged);
    }

    /**
     * Upsert satu tabel cermin lalu buang baris yang sudah tidak dikembalikan Kimai.
     *
     * Selisihnya dihitung SEBELUM menulis, karena upsert() tidak bisa membedakan
     * baris yang baru dari yang diperbarui — MySQL bahkan menghitung satu pembaruan
     * sebagai dua baris terpengaruh. Membacanya lebih dulu murah: katalog nyata
     * berukuran puluhan sampai ratusan baris, bukan puluhan ribu.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<string, mixed>  $petakan
     * @param  array<int, string>  $kolomDiperbarui
     */
    private function tulis(
        string $model,
        array $rows,
        callable $petakan,
        array $kolomDiperbarui,
    ): KimaiCatalogTally {
        $baris = array_map($petakan, $rows);

        $lama = $model::query()->get()->keyBy('kimai_id');

        $baru = 0;
        $diperbarui = 0;

        foreach ($baris as $row) {
            $sebelumnya = $lama->get($row['kimai_id']);

            if ($sebelumnya === null) {
                $baru++;

                continue;
            }

            foreach ($kolomDiperbarui as $kolom) {
                // synced_at berubah di setiap sync; kalau ikut dihitung, tidak akan
                // pernah ada sync yang dilaporkan "tidak ada perubahan".
                if ($kolom === 'synced_at') {
                    continue;
                }

                if ($sebelumnya->{$kolom} != $row[$kolom]) {
                    $diperbarui++;

                    break;
                }
            }
        }

        foreach (array_chunk($baris, self::CHUNK) as $chunk) {
            $model::query()->upsert($chunk, ['kimai_id'], $kolomDiperbarui);
        }

        // Yang dibuang adalah id yang TIDAK ikut dikembalikan Kimai kali ini —
        // dihitung dari selisih himpunan id, bukan dari stempel waktu.
        //
        // Sempat memakai `where('synced_at', '<', $stamp)` dan itu salah: dua sync
        // yang jatuh pada detik yang sama membuat stempel lama PERSIS sama dengan
        // $stamp, perbandingannya tidak pernah benar, dan baris yang sudah hilang
        // dari Kimai bertahan selamanya di legenda. Selisih id tidak bergantung jam
        // sama sekali. Jumlahnya puluhan sampai ratusan, jadi dipotong per CHUNK
        // saja sudah cukup menjaga parameter terikat tetap wajar.
        $hilang = $lama->keys()->diff(array_column($baris, 'kimai_id'))->values();

        $dihapus = 0;

        foreach (array_chunk($hilang->all(), self::CHUNK) as $chunk) {
            $dihapus += $model::query()->whereIn('kimai_id', $chunk)->delete();
        }

        return new KimaiCatalogTally(
            baru: $baru,
            diperbarui: $diperbarui,
            dihapus: $dihapus,
            total: $model::query()->count(),
        );
    }
}
