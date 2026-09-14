<?php

namespace App\Domain\Kimai;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Models\User;

/**
 * F-11 — siklus hidup API key milik satu user.
 *
 * §9 — token hanya disimpan bila tes koneksi lulus, tidak pernah ditampilkan
 * kembali selain 4 karakter terakhir, dan menghapus koneksi menghapusnya dari
 * database seketika. Record lembur yang sudah masuk tetap ada.
 */
class KimaiConnection
{
    public function __construct(private readonly KimaiClient $client) {}

    public function test(string $token): ConnectionTestResult
    {
        $token = trim($token);

        if ($token === '') {
            return ConnectionTestResult::failure('API key masih kosong.');
        }

        try {
            $this->client->ping($token);
        } catch (KimaiException $e) {
            // Pesan sudah dibedakan di masing-masing exception: token ditolak,
            // jaringan tidak sampai, atau endpoint salah. Membedakannya penting
            // karena SR-4 — "Kimai butuh VPN" terlihat persis seperti "token
            // salah" kalau pesannya digeneralisasi.
            return ConnectionTestResult::failure($e->userMessage());
        }

        return ConnectionTestResult::success();
    }

    /** Menyimpan token HANYA setelah tes koneksi lulus. */
    public function store(User $user, string $token): ConnectionTestResult
    {
        $token = trim($token);
        $result = $this->test($token);

        if (! $result->ok) {
            return $result;
        }

        // Ditulis eksplisit, bukan lewat update(): kolom token sengaja tidak
        // fillable supaya tidak bisa ikut terbawa mass assignment dari form mana pun.
        $user->kimai_api_token = $token;
        $user->kimai_token_last4 = mb_substr($token, -4);
        $user->kimai_token_valid_at = now();
        $user->save();

        return $result;
    }

    public function forget(User $user): void
    {
        $user->kimai_api_token = null;
        $user->kimai_token_last4 = null;
        $user->kimai_token_valid_at = null;
        $user->kimai_auto_sync = false;
        // Watermark ikut dilupakan: kalau token baru dipasang nanti, sync mulai
        // lagi dari awal periode payroll dan tidak melewatkan apa pun.
        $user->kimai_synced_through = null;
        $user->save();
    }

    /** SY-22 — token ditolak Kimai saat job berjalan. */
    public function markInvalid(User $user): void
    {
        $user->kimai_token_valid_at = null;
        $user->kimai_auto_sync = false;
        $user->save();
    }
}
