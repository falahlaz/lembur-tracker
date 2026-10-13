<?php

namespace App\Jobs;

use App\Domain\Kimai\Exceptions\KimaiTokenInvalid;
use App\Domain\Kimai\KimaiConnection;
use App\Domain\Kimai\KimaiSynchronizer;
use App\Domain\Kimai\SyncRangeResolver;
use App\Enums\SyncTrigger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * SY-17 — klik tombol sync hanya menaruh job ini ke queue dan langsung kembali.
 * Tidak ada permintaan HTTP yang menunggu Kimai di siklus request web.
 *
 * §9 Otorisasi — job menerima model User, bukan `user_id` dari input. Tidak ada
 * endpoint yang menerima user_id, sehingga tidak ada cara menarik data orang lain.
 */
class SyncKimaiTimesheets implements ShouldQueue
{
    use Queueable;

    /** SY-20 — percobaan pertama + 3 retry. */
    public int $tries = 4;

    /**
     * `$from`/`$to` (Y-m-d) hanya diisi oleh aksi "Sync rentang" di Riwayat Sync.
     * Disimpan sebagai string supaya payload queue tetap sederhana.
     */
    public function __construct(
        public readonly User $user,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    /** SY-20 — backoff 10 detik, 60 detik, 180 detik. */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /** SY-18 — satu job aktif per user; kunci melepas sendiri setelah TTL (§10). */
    public function uniqueId(): string
    {
        return self::lockKey($this->user);
    }

    public static function lockKey(User $user): string
    {
        return "kimai-sync:{$user->id}";
    }

    public static function isRunningFor(User $user): bool
    {
        return Cache::has(self::lockKey($user));
    }

    /**
     * Jendela antara "job sudah di-dispatch" dan "worker mengambilnya" tidak
     * ditutupi kunci maupun baris sync_runs. Tanpa penanda ini, klik kedua di
     * detik itu akan terlihat sah oleh UI — meski job keduanya nanti tetap
     * ditolak kunci (SY-18), tombolnya sempat hidup dan membingungkan.
     */
    public static function markPending(User $user): void
    {
        Cache::put(self::pendingKey($user), true, 120);
    }

    public static function isPendingFor(User $user): bool
    {
        return Cache::has(self::pendingKey($user));
    }

    public static function pendingKey(User $user): string
    {
        return "kimai-sync-pending:{$user->id}";
    }

    public function handle(KimaiSynchronizer $synchronizer, KimaiConnection $connection): void
    {
        if (! $this->user->hasKimaiConnection()) {
            return;
        }

        Cache::forget(self::pendingKey($this->user));

        $lock = Cache::lock(self::lockKey($this->user), (int) config('kimai.lock_ttl'));

        // §10 — job yang macet melewati TTL melepaskan kuncinya sendiri, sehingga
        // tombol sync hidup kembali tanpa campur tangan admin.
        if (! $lock->get()) {
            return;
        }

        try {
            if ($this->from !== null && $this->to !== null) {
                $range = app(SyncRangeResolver::class)->custom(
                    CarbonImmutable::parse($this->from),
                    CarbonImmutable::parse($this->to),
                );

                $synchronizer->run($this->user, SyncTrigger::Range, $range);
            } else {
                $synchronizer->run($this->user, SyncTrigger::Manual);
            }
        } catch (KimaiTokenInvalid $e) {
            // SY-22 — token ditolak tidak pernah di-retry: mencobanya lagi hanya
            // menghasilkan 401 yang sama tiga kali.
            $connection->markInvalid($this->user);
            $this->fail($e);
        } finally {
            $lock->release();
        }
    }
}
