<?php

namespace App\Domain\Timesheet;

use App\Enums\UploadStatus;
use App\Jobs\PostTimesheetUpload;
use App\Models\TimesheetUpload;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * UP-10 — upload yang macet melewati TTL kunci ditandai gagal.
 *
 * Kembaran KimaiSynchronizer::failStaleRuns(). §10 sudah mensyaratkannya untuk
 * sync sejak awal, tetapi jalur upload tidak pernah mendapat padanannya — dan
 * akibatnya lebih parah di sini: `scopeActive` membuat sedangBerjalan() terus
 * bernilai true, sehingga tombol Kirim mati PERMANEN dan tombol Batalkan tidak
 * ikut dirender. Satu job yang hilang (worker berhenti, atau baris cache_locks
 * basi yang menelannya tanpa jejak karena tries = 1) mengunci halaman ini
 * selamanya, dan satu-satunya jalan keluarnya adalah mengubah baris database
 * dengan tangan.
 *
 * Dipanggil saat UI memeriksa status, bukan lewat scheduler: yang butuh
 * jawabannya hanya layar yang sedang dibuka.
 */
class UploadRecovery
{
    /** @return int cacah upload yang baru saja ditutup paksa. */
    public static function failStaleUploads(User $user): int
    {
        $batas = now()->subSeconds((int) config('kimai.lock_ttl'));

        $ditutup = TimesheetUpload::query()
            ->where('user_id', $user->id)
            ->active()
            // `queued` belum pernah punya started_at — itu baru diisi
            // UploadPoster::post() saat entri pertama hendak dikirim. Karena itu
            // umurnya dinilai dari started_at kalau ada, created_at kalau tidak.
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('started_at')->where('started_at', '<', $batas))
                ->orWhere(fn ($q) => $q->whereNull('started_at')->where('created_at', '<', $batas))
            )
            ->update([
                'status' => UploadStatus::Failed->value,
                // Menyebut queue worker dengan sengaja: itulah petunjuk yang
                // selama ini hilang saat halaman cuma diam.
                'error_message' => 'Upload berhenti sebelum sempat dikirim — kemungkinan queue worker '
                    .'tidak berjalan. Entri yang belum terkirim masih bisa dilanjutkan.',
                'finished_at' => now(),
            ]);

        if ($ditutup > 0) {
            // Statusnya saja tidak cukup. sedangBerjalan() membaca TIGA penanda,
            // dan dua sisanya hidup di cache: penanda `pending` (120 detik) dan
            // kunci per-user (TTL kunci, 10 menit). Kunci yatim itu justru yang
            // paling sering jadi sebab job tertelan, jadi membiarkannya berarti
            // halaman tetap menulis "Mengirim…" untuk upload yang barusan kita
            // nyatakan gagal — layar yang membantah dirinya sendiri.
            Cache::forget(PostTimesheetUpload::pendingKey($user));
            Cache::lock(PostTimesheetUpload::lockKey($user))->forceRelease();
        }

        return $ditutup;
    }
}
