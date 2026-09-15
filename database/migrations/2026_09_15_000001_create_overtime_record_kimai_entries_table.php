<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SY-23 — satu record lembur bisa berasal dari BANYAK entri timesheet Kimai.
 *
 * Kimai membatasi satu timesheet maksimal 2 jam, jadi lembur 8 jam datang sebagai
 * empat entri. Kolom skalar `overtime_records.kimai_timesheet_id` tidak bisa mewakili
 * hubungan N:1 itu, dan unique index di atasnya justru menghalanginya.
 *
 * Tabel ini yang sekarang memegang dedup SY-13. Sengaja tabel, bukan kolom JSON:
 * jaminan "satu entri Kimai hanya boleh menempel di satu record" harus ditegakkan
 * database, dan unique index di atas kolom JSON tidak portabel antara MySQL dan
 * SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_record_kimai_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kimai_timesheet_id');

            // Jam asli entri, LENGKAP dengan tanggalnya. `overtime_records` hanya
            // menyimpan `H:i`, sehingga anggota yang jatuh setelah tengah malam tidak
            // bisa direkonstruksi dari sana — padahal rekonsiliasi sync membutuhkannya.
            $table->dateTime('begin');
            $table->dateTime('end');

            // SY-10 — `duration` dari Kimai sudah bersih dari `break` masing-masing.
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('break_minutes')->default(0);

            // Deskripsi milik entri ini sendiri, bukan hasil gabungan. Tanpa ini,
            // menyusun ulang deskripsi record saat anggota bertambah akan menumpuk
            // teks yang sudah pernah digabung.
            $table->text('description')->nullable();

            $table->timestamps();

            // SY-13 — kunci dedup, pindahan dari `overtime_records`.
            $table->unique(['user_id', 'kimai_timesheet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_record_kimai_entries');
    }
};
