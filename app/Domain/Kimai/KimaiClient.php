<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\KimaiRejectedRequest;
use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\Exceptions\KimaiUnavailable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP Kimai. Satu-satunya tempat token menyentuh jaringan.
 *
 * §9 — token TIDAK PERNAH masuk pesan exception, log, atau URL. Response
 * dikonversi jadi exception milik sendiri di sini juga, sehingga
 * RequestException bawaan Laravel — yang membawa header Authorization — tidak
 * pernah sampai ke exception handler (SR-2).
 */
class KimaiClient
{
    /**
     * SY-01 — request tanpa parameter `user` otomatis mengembalikan timesheet
     * milik pemilik token, jadi tidak perlu permission view_other_timesheet dan
     * tidak perlu menyimpan Kimai user ID.
     *
     * @return array<int, KimaiTimesheet>
     */
    public function timesheets(string $token, CarbonInterface $begin, CarbonInterface $end): array
    {
        $size = (int) config('kimai.page_size');
        $maxPages = (int) config('kimai.max_pages');
        $entries = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $payload = $this->get($token, '/api/timesheets', [
                'begin' => KimaiTimesheet::formatQueryDate($begin),
                'end' => KimaiTimesheet::formatQueryDate($end),
                'tags' => array_values((array) config('kimai.tags')),
                'size' => $size,
                'page' => $page,
                config('kimai.order_param') => 'begin',
                'order' => 'ASC',
                // SY-06 — hanya timesheet yang sudah berhenti.
                'active' => 0,
            ]);

            foreach ($payload as $row) {
                if (is_array($row)) {
                    $entries[] = KimaiTimesheet::fromPayload($row);
                }
            }

            // Halaman terakhir selalu lebih pendek dari `size`.
            if (count($payload) < $size) {
                break;
            }
        }

        return $entries;
    }

    /** F-11 — tes koneksi termurah yang tetap membuktikan token dipakai. */
    public function ping(string $token): void
    {
        $this->get($token, '/api/timesheets', ['size' => 1]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, mixed>
     */
    private function get(string $token, string $path, array $query): array
    {
        try {
            $response = $this->request($token)->get($path, $query);
        } catch (ConnectionException) {
            // Pesan aslinya memuat URL lengkap saja, bukan header — tetapi tidak
            // ada gunanya diteruskan, jadi dibuang sekalian.
            throw new KimaiUnavailable('Kimai tidak dapat dihubungi.');
        }

        $this->guard($response, $path);

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('kimai.base_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) config('kimai.timeout'))
            // §9 — HTTPS wajib, verifikasi sertifikat tidak boleh dimatikan.
            // Dinyatakan eksplisit supaya tidak ada yang "sementara" mematikannya.
            ->withOptions(['verify' => true]);
    }

    private function guard(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        // SY-22 — token ditolak. Tidak pernah di-retry.
        if ($status === 401 || $status === 403) {
            throw new KimaiTokenInvalid('Kimai menolak token.');
        }

        // SY-20 — hanya 5xx yang layak dicoba ulang.
        if ($status >= 500) {
            throw new KimaiUnavailable("Kimai membalas HTTP {$status}.");
        }

        // Path saja, tanpa query dan tanpa header — cukup untuk admin menelusuri.
        throw new KimaiRejectedRequest($status, $path);
    }
}
