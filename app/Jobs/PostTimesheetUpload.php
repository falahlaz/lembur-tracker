<?php

namespace App\Jobs;

use App\Domain\Timesheet\UploadPoster;
use App\Enums\UploadStatus;
use App\Models\TimesheetUpload;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Mengirim satu upload ke Kimai di latar belakang.
 *
 * SATU PERCOBAAN, sengaja berbeda dari SyncKimaiTimesheets yang memakai
 * tries = 4. Sync boleh di-retry karena menariknya idempoten (SY-13). POST ke
 * Kimai TIDAK: tidak ada idempotency key, jadi satu balasan 5xx di tengah 60
 * pengiriman akan membuat percobaan berikutnya mengulang dari awal dan
 * memproduksi entri ganda yang tidak bisa dibatalkan.
 *
 * Pengulangannya ditangani di tempat yang memang tahu apa yang sudah masuk:
 * status per entri di database. User menekan "Lanjutkan", dan pengirim hanya
 * pernah mengambil entri yang masih `pending`.
 *
 * §9 Otorisasi — job menerima model User dan model TimesheetUpload, bukan id dari
 * input, dan tetap memeriksa kepemilikan sebelum mengerjakan apa pun.
 */
class PostTimesheetUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly User $user,
        public readonly TimesheetUpload $upload,
    ) {}

    public static function lockKey(User $user): string
    {
        return "timesheet-upload:{$user->id}";
    }

    public static function pendingKey(User $user): string
    {
        return "timesheet-upload-pending:{$user->id}";
    }

    /**
     * Jendela antara "job sudah di-dispatch" dan "worker mengambilnya" tidak
     * ditutupi kunci maupun status upload. Tanpa penanda ini, klik kedua di detik
     * itu akan terlihat sah oleh UI.
     */
    public static function markPending(User $user): void
    {
        Cache::put(self::pendingKey($user), true, 120);
    }

    public static function isPendingFor(User $user): bool
    {
        return Cache::has(self::pendingKey($user));
    }

    public static function isRunningFor(User $user): bool
    {
        return Cache::has(self::lockKey($user));
    }

    public function handle(UploadPoster $poster): void
    {
        // Upload milik orang lain tidak pernah dikerjakan, meskipun job-nya
        // entah bagaimana sampai ke sini.
        if ($this->upload->user_id !== $this->user->id) {
            return;
        }

        if (! $this->user->hasKimaiConnection()) {
            return;
        }

        $upload = $this->upload->fresh();

        if ($upload === null || $upload->status !== UploadStatus::Queued) {
            return;
        }

        Cache::forget(self::pendingKey($this->user));

        $lock = Cache::lock(self::lockKey($this->user), (int) config('kimai.lock_ttl'));

        // Kuncinya sendiri yang menjaga satu upload aktif per user; uniqueId()
        // tidak dipakai karena tanpa ShouldBeUnique ia memang tidak berefek apa-apa
        // — persis seperti di SyncKimaiTimesheets.
        if (! $lock->get()) {
            return;
        }

        try {
            $poster->post($upload);
        } finally {
            $lock->release();
        }
    }

    /** Upload yang tertinggal di `queued` akan mengunci tombolnya selamanya. */
    public function failed(?Throwable $e): void
    {
        Cache::forget(self::pendingKey($this->user));

        $upload = $this->upload->fresh();

        if ($upload === null || $upload->status->isFinished()) {
            return;
        }

        $upload->forceFill([
            'status' => UploadStatus::Failed->value,
            'error_message' => 'Upload berhenti di tengah jalan. Entri yang belum terkirim bisa dilanjutkan.',
            'finished_at' => now(),
        ])->save();
    }
}
