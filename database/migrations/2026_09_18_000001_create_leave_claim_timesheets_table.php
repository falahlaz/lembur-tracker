<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CT-01 — satu baris per SLOT JAM cuti pengganti, dari rencana sampai nasibnya
 * di Kimai.
 *
 * Tabel, bukan kolom JSON di `leave_claims`, dengan alasan yang sama yang
 * melahirkan `overtime_record_kimai_entries`: satu klaim menghasilkan 3–5 entri
 * Kimai, dan jaminan "satu entri Kimai hanya menempel di satu klaim" harus
 * ditegakkan database — unique index di atas kolom JSON tidak portabel antara
 * MySQL dan SQLite.
 *
 * Baris ini sekaligus RENCANA dan CATATAN HASIL, seperti
 * `timesheet_upload_entries`. Dengan begitu "apa yang seharusnya ada di Kimai"
 * dan "apa yang sudah benar-benar ada di sana" tidak pernah punya dua salinan
 * yang bisa berbeda diam-diam. Tanpa tabel ini syarat hapus-saat-dibatalkan
 * mustahil dipenuhi: id entri Kimai tidak tersimpan di mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_claim_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_claim_id')->constrained()->cascadeOnDelete();

            // Pemilik KLAIM, bukan yang menekan tombolnya. Entri Kimai lahir dari
            // token orang ini, dan hanya token orang ini yang bisa menghapusnya
            // lagi — lihat catatan soal admin di LeaveTimesheetSync.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('kimai_project_id');

            // Diresolusi saat rencana disusun lalu DIBEKUKAN di sini: pengirim
            // tidak pernah menanyakan katalog lagi, sehingga katalog yang berubah
            // di tengah jalan tidak bisa memindahkan sebagian slot ke activity lain.
            $table->unsignedInteger('kimai_activity_id');

            $table->dateTime('begin_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('duration_minutes');

            $table->string('status')->default('pending');
            $table->unsignedBigInteger('kimai_timesheet_id')->nullable();
            $table->timestamp('posted_at')->nullable();

            // Ditandai penyusun rencana: "entri ini sudah ada di Kimai tetapi tidak
            // lagi dikehendaki". Yang benar-benar menghapusnya adalah pengirim,
            // karena hanya dia yang boleh menyentuh jaringan. Dipisah begitu supaya
            // DELETE yang gagal meninggalkan baris yang masih bisa dicoba lagi,
            // bukan baris yang sudah terlanjur hilang beserta id Kimai-nya.
            $table->timestamp('discarded_at')->nullable();
            $table->timestamp('deleted_from_kimai_at')->nullable();

            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['leave_claim_id', 'status']);

            // Sejalan dengan SY-13: satu entri Kimai tidak boleh diklaim dua baris.
            // NULL berulang diizinkan MySQL maupun SQLite, jadi baris yang belum
            // terkirim tidak saling bentrok di sini.
            $table->unique(['user_id', 'kimai_timesheet_id'], 'lct_kimai_unik');

            // Pagar terakhir agar satu jam tidak pernah dikirim dua kali untuk klaim
            // yang sama. `status` ikut di dalamnya supaya baris `deleted` boleh tetap
            // tinggal sebagai jejak ketika klaim yang sama diajukan ulang pada jam itu.
            $table->unique(['leave_claim_id', 'begin_at', 'status'], 'lct_slot_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_claim_timesheets');
    }
};
