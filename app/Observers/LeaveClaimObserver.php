<?php

namespace App\Observers;

use App\Domain\Lembur\LeaveTimesheetSync;
use App\Models\LeaveClaim;

/**
 * CT-05 — menjaga entri cuti di Kimai saat klaim dihapus atau dipulihkan.
 *
 * Observer ini SENGAJA tidak punya `saved()`. Pengajuan klaim dipicu eksplisit
 * dari CreateLeaveClaim dan EditLeaveClaim, sejajar dengan LeaveAllocator::hold()
 * yang sudah dipanggil di sana — dua akibat dari keputusan yang sama sebaiknya
 * tidak hidup di dua mekanisme berbeda.
 *
 * Lebih penting lagi: `saved` menyala di jalur yang sama sekali bukan "user
 * mengajukan klaim". LeaveAllocator::settle() menyimpan klaim DI DALAM sebuah
 * transaksi dari perintah harian, BalanceReconciler::void() menyimpannya untuk
 * menyalakan needs_review, dan EditLeaveClaim sendiri memanggil update() lagi
 * sesudah afterSave(). Menaruh permintaan HTTP di sana berarti jaringan di dalam
 * transaksi, dan puluhan POST tak diminta setiap kali perintah harian jalan.
 *
 * Yang tersisa di sini hanyalah dua hal yang memang tidak punya titik panggil
 * lain, dan keduanya jarang.
 */
class LeaveClaimObserver
{
    public function __construct(private readonly LeaveTimesheetSync $sync) {}

    /**
     * Soft delete tidak memicu cascade, jadi barisnya selamat dan id Kimai masih
     * terbaca. Dikerjakan di `deleting`, bukan `deleted`, karena rencananya perlu
     * disusun selagi klaimnya masih utuh.
     */
    public function deleting(LeaveClaim $claim): void
    {
        $this->withdraw($claim);
    }

    /**
     * Force delete melenyapkan barisnya lewat cascadeOnDelete, dan bersamanya id
     * entri Kimai — selamanya. Ini satu-satunya kesempatan menariknya kembali.
     */
    public function forceDeleting(LeaveClaim $claim): void
    {
        $this->withdraw($claim);
    }

    /** Klaim yang dipulihkan berhak atas timesheetnya lagi, kalau statusnya masih aktif. */
    public function restored(LeaveClaim $claim): void
    {
        $this->sync->sync($claim);
    }

    /**
     * Kegagalan di sini tidak boleh menggagalkan penghapusan klaimnya. Baris yang
     * tertinggal akan tersapu cascade; yang bisa dilakukan user cuma menghapus
     * entrinya sendiri di Kimai, dan itu lebih baik daripada klaim yang menolak
     * hilang.
     */
    private function withdraw(LeaveClaim $claim): void
    {
        $this->sync->withdraw($claim);
    }
}
