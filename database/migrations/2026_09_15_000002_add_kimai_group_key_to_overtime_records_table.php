<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SY-23 — kunci sesi lembur, supaya sync bisa menemukan kembali grup yang sama pada
 * run berikutnya dan MELEBARKANNYA, bukan membuat record kedua.
 *
 * Bentuknya `we:{tanggal}` (jendela weekday 18:00 → 09:00), `wk:{tanggal}` (weekend
 * satu hari penuh), atau `ts:{id}` (entri weekday di dalam jam kerja, berdiri
 * sendiri). Lihat App\Domain\Kimai\SessionGrouper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('kimai_group_key')->nullable()->after('kimai_activity_id');

            // NULL berulang tetap diizinkan MySQL maupun SQLite, sehingga record
            // manual tidak saling bentrok — pola yang sama dengan unique lama.
            $table->unique(['user_id', 'kimai_group_key']);
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            // Dedup SY-13 pindah ke `overtime_record_kimai_entries`. Kolomnya sendiri
            // DIPERTAHANKAN sebagai penanda entri pertama sebuah sesi — dipakai tabel
            // Daftar Lembur sebagai breadcrumb — tetapi tidak lagi unik, karena satu
            // record kini menampung banyak entri.
            $table->dropUnique(['user_id', 'kimai_timesheet_id']);
        });
    }

    /**
     * Mengembalikan unique lama hanya mungkin bila datanya sudah 1:1 lagi. Kalau ada
     * record gabungan yang tersisa, `down()` akan gagal di situ — dan itu memang lebih
     * baik daripada diam-diam membuang baris.
     */
    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kimai_group_key']);
            $table->dropColumn('kimai_group_key');
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->unique(['user_id', 'kimai_timesheet_id']);
        });
    }
};
