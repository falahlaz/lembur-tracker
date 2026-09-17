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
use Illuminate\Support\Str;

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
        // Perilakunya tidak berubah sedikit pun — hanya pindah badan ke
        // timesheetsInRange(), yang dua filternya bisa dilepas untuk keperluan lain.
        return $this->timesheetsInRange(
            token: $token,
            begin: $begin,
            end: $end,
            tags: array_values((array) config('kimai.tags')),
            onlyStopped: true,
        );
    }

    /**
     * Varian yang filter tag dan filter `active`-nya bisa dilepas.
     *
     * Dibutuhkan pemeriksaan duplikat sebelum upload: sebuah slot 09:00–10:00 yang
     * sudah terisi entri Daily TANPA tag tetap slot yang terpakai, dan entri yang
     * timernya masih berjalan pun tetap menempati jamnya. Karena itu filter yang
     * tepat untuk sync justru keliru di sini — dan sebaliknya, sehingga keduanya
     * dipisah alih-alih salah satu mengalah.
     *
     * @param  array<int, string>  $tags  kosong berarti tanpa filter tag sama sekali
     * @return array<int, KimaiTimesheet>
     */
    public function timesheetsInRange(
        string $token,
        CarbonInterface $begin,
        CarbonInterface $end,
        array $tags = [],
        bool $onlyStopped = true,
    ): array {
        $size = (int) config('kimai.page_size');
        $maxPages = (int) config('kimai.max_pages');
        $entries = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $query = [
                'begin' => KimaiTimesheet::formatQueryDate($begin),
                'end' => KimaiTimesheet::formatQueryDate($end),
                'size' => $size,
                'page' => $page,
                config('kimai.order_param') => 'begin',
                'order' => 'ASC',
            ];

            // Ditambahkan hanya kalau memang diminta. Mengirim `tags` kosong dan
            // berharap ia terbuang sendiri saat query di-encode adalah taruhan
            // pada perilaku Guzzle yang bisa berubah antar versi.
            if ($tags !== []) {
                $query['tags'] = $tags;
            }

            if ($onlyStopped) {
                // SY-06 — hanya timesheet yang sudah berhenti.
                $query['active'] = 0;
            }

            $payload = $this->get($token, '/api/timesheets', $query);

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

    /**
     * Membuat satu entri timesheet.
     *
     * Body-nya disusun pemanggil (ParsedEntry / TimesheetUploadEntry), yang memegang
     * aturan ketat Kimai soal field apa saja yang boleh ikut.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> entri yang terbentuk, termasuk `id`
     */
    public function createTimesheet(string $token, array $payload): array
    {
        return $this->post($token, '/api/timesheets', $payload);
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $token, string $path, array $payload): array
    {
        try {
            $response = $this->request($token)->post($path, $payload);
        } catch (ConnectionException) {
            throw new KimaiUnavailable('Kimai tidak dapat dihubungi.');
        }

        // Berbeda dari GET: 400 di sini berarti SATU entri ditolak dengan alasan
        // tertentu, dan alasan itulah satu-satunya bahan untuk memperbaikinya.
        $this->guard($response, $path, withDetail: true);

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

    private function guard(Response $response, string $path, bool $withDetail = false): void
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
        throw new KimaiRejectedRequest($status, $path, $withDetail ? $this->validationDetail($response) : null);
    }

    /**
     * Alasan penolakan dari badan respons, seringkas mungkin.
     *
     * §9 — yang diambil HANYA teks statis buatan server ("This form should not
     * contain extra fields", "This value is not valid."), bukan seluruh badan
     * respons, dan panjangnya dipotong. Token hidup di header dan tidak pernah
     * dipantulkan balik, tetapi membatasi diri pada dua field ini membuat tidak
     * ada jalan bagi isi request ikut terbawa ke log.
     */
    private function validationDetail(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $pesan = [];

        if (is_string($body['message'] ?? null) && trim($body['message']) !== '') {
            $pesan[] = trim($body['message']);
        }

        foreach ((array) data_get($body, 'errors.children', []) as $field => $child) {
            $first = data_get($child, 'errors.0');

            if (is_string($first) && trim($first) !== '') {
                $pesan[] = is_string($field) ? "{$field}: ".trim($first) : trim($first);
            }
        }

        if ($pesan === []) {
            return null;
        }

        return Str::limit(implode(' ', array_unique($pesan)), 200);
    }
}
