<?php

namespace App\Console\Commands;

use App\Domain\Kimai\Exceptions\KimaiException;
use App\Domain\Kimai\KimaiCatalogSync;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Menarik katalog Kimai (project + activity) ke cermin lokal dari baris perintah.
 *
 * Kembarannya tombol "Sync Katalog Kimai" di halaman Legenda. Ada dua alasan
 * command ini tetap dibuat meski tombolnya sudah cukup: deploy baru bisa mengisi
 * legendanya tepat setelah `php artisan migrate`, jadi halaman itu sudah berguna
 * sebelum ada yang login; dan kalau panelnya sedang tidak bisa dibuka, ini jalan
 * masuk yang tidak lewat browser.
 *
 * SENGAJA TIDAK DIJADWALKAN. Tidak ada entri di routes/console.php, dan memang
 * tidak boleh ada: project dan activity berganti hitungan bulan sekali, jadi
 * menjalankannya tiap hari hanya membuang permintaan ke instance yang dipakai
 * seluruh tim demi jawaban yang hampir selalu "tidak ada perubahan".
 */
class SyncKimaiCatalog extends Command
{
    protected $signature = 'lemburku:kimai:catalog
        {--user= : Admin yang tokennya dipakai (id atau email)}';

    protected $description = 'Menarik daftar project dan activity Kimai ke cermin lokal (legenda).';

    public function handle(KimaiCatalogSync $sync): int
    {
        $admin = $this->resolveAdmin();

        if ($admin === null) {
            return self::FAILURE;
        }

        $this->info("Menarik katalog dengan token milik {$admin->name} <{$admin->email}>…");

        try {
            $hasil = $sync->run($admin);
        } catch (KimaiException $e) {
            // userMessage() sudah bebas token dan bisa ditindaklanjuti (§9).
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info($hasil->summary());
        $this->table(
            ['Tabel', 'Baru', 'Diperbarui', 'Dihapus', 'Total'],
            [
                ['Project', $hasil->projects->baru, $hasil->projects->diperbarui, $hasil->projects->dihapus, $hasil->projects->total],
                ['Activity', $hasil->activities->baru, $hasil->activities->diperbarui, $hasil->activities->dihapus, $hasil->activities->total],
            ],
        );

        return self::SUCCESS;
    }

    /** Admin ber-token yang dipilih, atau null kalau tidak ada yang bisa dipakai. */
    private function resolveAdmin(): ?User
    {
        $target = (string) $this->option('user');

        if ($target !== '') {
            $user = User::query()
                ->where('id', is_numeric($target) ? (int) $target : 0)
                ->orWhere('email', $target)
                ->first();

            if ($user === null) {
                $this->error("User \"{$target}\" tidak ditemukan.");

                return null;
            }

            if (! $user->hasKimaiConnection()) {
                $this->error("{$user->email} belum menyimpan API key Kimai di halaman Preferensi.");

                return null;
            }

            return $user;
        }

        // Tanpa --user: admin pertama yang punya token. Katalog yang terlihat
        // bergantung pada permission pemilik token, dan admin paling mungkin
        // melihat seluruhnya.
        $admin = User::query()
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->whereNotNull('kimai_api_token')
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            $this->warn('Tidak ada admin aktif yang sudah menyimpan API key Kimai.');
            $this->line('Simpan API key lewat halaman Preferensi, atau sebutkan user lain dengan --user=.');
        }

        return $admin;
    }
}
