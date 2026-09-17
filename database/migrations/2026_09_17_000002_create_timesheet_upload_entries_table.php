<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris per sel terisi. Ini sekaligus pratinjau DAN catatan hasilnya —
 * dengan begitu "apa yang akan dikirim" dan "apa yang sudah dikirim" tidak pernah
 * punya dua salinan yang bisa berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_upload_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sheet');
            $table->string('cell_ref');
            $table->string('slot_label');
            // Tanggal KOLOM, bukan tanggal end_at: slot "10 PM - 12 PM" bekerja di
            // tanggal 18 tetapi berakhir 19 pukul 00:00. KimaiTimesheet::overtimeDate()
            // juga memakai tanggal begin, jadi perjalanan pulangnya sepakat.
            $table->date('work_date');
            $table->dateTime('begin_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('activity_id')->nullable();
            $table->text('description');
            // 'Overtime' atau NULL — tidak pernah string kosong; Kimai menolak tag kosong.
            $table->string('tag')->nullable();
            $table->string('status')->default('pending');
            $table->string('skip_reason')->nullable();
            $table->boolean('overridable')->default(false);
            $table->unsignedBigInteger('conflicting_kimai_id')->nullable();
            $table->unsignedBigInteger('kimai_timesheet_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['timesheet_upload_id', 'status']);
            // Dipakai pemeriksaan duplikat lokal: "slot ini sudah pernah terkirim".
            $table->index(['user_id', 'begin_at']);
            // Pagar terakhir supaya satu sel workbook tidak pernah masuk dua kali
            // ke draf yang sama. Namanya ditulis eksplisit — nama bentukan otomatis
            // terlalu panjang untuk MySQL.
            $table->unique(['timesheet_upload_id', 'sheet', 'work_date', 'slot_label'], 'tue_sel_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_upload_entries');
    }
};
