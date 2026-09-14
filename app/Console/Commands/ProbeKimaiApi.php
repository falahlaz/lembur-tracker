<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OQ-1 / SR-1 — dokumentasi instance (`/api/doc`) tidak dapat diakses dari luar,
 * dan kontrak API Kimai berbeda antar versi: nama parameter urutan (`order_by`
 * vs `orderBy`) dan perilaku filter `tags[]` pernah jadi bug.
 *
 * Command ini READ-ONLY: ia tidak menyimpan token, tidak menulis ke database,
 * dan tidak mengubah konfigurasi. Hasilnya dibaca manusia, lalu dituangkan ke
 * config/kimai.php atau .env.
 *
 * Token diminta lewat prompt tersembunyi, bukan argumen CLI — argumen akan
 * tercatat di shell history dan daftar proses.
 */
class ProbeKimaiApi extends Command
{
    protected $signature = 'lemburku:kimai:probe';

    protected $description = 'Memverifikasi kontrak API instance Kimai (OQ-1). Tidak menyimpan apa pun.';

    public function handle(): int
    {
        $base = rtrim((string) config('kimai.base_url'), '/');
        $this->info("Instance: {$base}");

        $token = $this->secret('API token Kimai (tidak ditampilkan, tidak disimpan)');

        if (blank($token)) {
            $this->error('Token kosong. Dibatalkan.');

            return self::FAILURE;
        }

        $this->line('');
        $this->probeVersion($base, $token);
        $this->probeOrderParam($base, $token);
        $this->probeTagFilter($base, $token);

        $this->line('');
        $this->comment('Tuangkan hasilnya ke .env: KIMAI_ORDER_PARAM, KIMAI_TAGS.');

        return self::SUCCESS;
    }

    private function probeVersion(string $base, string $token): void
    {
        $response = $this->fetch($base, $token, '/api/version', []);

        if ($response === null) {
            $this->warn('  /api/version  tidak terjangkau');

            return;
        }

        $body = $response->json();
        $version = is_array($body) ? ($body['version'] ?? $body['versionId'] ?? '?') : '?';

        $this->line("  versi Kimai   {$version} (HTTP {$response->status()})");
    }

    /** Nama parameter urutan berbeda antar versi; yang dibalas 200 itu yang dipakai. */
    private function probeOrderParam(string $base, string $token): void
    {
        foreach (['order_by', 'orderBy'] as $param) {
            $response = $this->fetch($base, $token, '/api/timesheets', [$param => 'begin', 'order' => 'ASC', 'size' => 1]);
            $status = $response?->status() ?? 'gagal';
            $verdict = $response !== null && $response->successful() ? 'DITERIMA' : 'ditolak';

            $this->line(sprintf('  %-13s %s (HTTP %s)', $param, $verdict, $status));
        }
    }

    /**
     * SY-05 — memastikan filter tag benar-benar menyaring di sisi server. Kalau
     * jumlah hasil bertag sama dengan tanpa filter, filter server longgar dan
     * pemeriksaan ulang di aplikasi yang menahannya.
     */
    private function probeTagFilter(string $base, string $token): void
    {
        $tags = (array) config('kimai.tags');

        $all = $this->fetch($base, $token, '/api/timesheets', ['size' => 100]);
        $filtered = $this->fetch($base, $token, '/api/timesheets', ['size' => 100, 'tags' => array_values($tags)]);

        if ($all === null || $filtered === null) {
            $this->warn('  tags[]        tidak bisa diuji');

            return;
        }

        if (! $filtered->successful()) {
            $this->line("  tags[]        DITOLAK (HTTP {$filtered->status()}) — lihat SR-1");

            return;
        }

        $allCount = count((array) $all->json());
        $filteredCount = count((array) $filtered->json());

        $verdict = $filteredCount < $allCount ? 'menyaring' : 'TIDAK menyaring (filter aplikasi yang menahan)';
        $this->line("  tags[]        {$verdict} — {$filteredCount} dari {$allCount}");
    }

    /** @param array<string, mixed> $query */
    private function fetch(string $base, string $token, string $path, array $query): ?Response
    {
        try {
            return Http::baseUrl($base)
                ->withToken($token)
                ->acceptJson()
                ->timeout((int) config('kimai.timeout'))
                ->get($path, $query);
        } catch (Throwable) {
            // Pesan aslinya tidak ditampilkan: ia bisa memuat URL lengkap, dan
            // command ini tidak punya alasan mencetak apa pun selain vonisnya.
            return null;
        }
    }
}
