<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-12 — satu batch per record lembur yang memenuhi tier. Satuannya SELALU menit;
 * konversi ke "1 hari / datang siang" hanya terjadi di lapisan tampilan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('overtime_record_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('earned_minutes');
            $table->unsignedInteger('consumed_minutes')->default(0);   // dipotong permanen
            $table->unsignedInteger('held_minutes')->default(0);       // BR-20 ditahan klaim aktif

            $table->date('earned_date');    // = overtime_date
            $table->date('expires_at');     // BR-13, clamping akhir bulan

            $table->string('status')->default('active');
            $table->timestamps();

            // Satu record lembur menghasilkan paling banyak satu batch (BR-07).
            $table->unique('overtime_record_id');
            $table->index(['user_id', 'status']);
            // Urutan FIFO BR-14: expires_at ASC, lalu earned_date ASC.
            $table->index(['user_id', 'expires_at', 'earned_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
