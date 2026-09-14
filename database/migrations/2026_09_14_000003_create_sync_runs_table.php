<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-13 — "berhasil" saja tidak cukup. User perlu tahu persis apa yang berubah,
 * jadi setiap run dicatat lengkap dengan rentang dan cacahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger')->default('manual');
            $table->string('status')->default('queued');
            $table->dateTime('range_start');
            $table->dateTime('range_end');
            $table->unsignedInteger('count_fetched')->default(0);
            $table->unsignedInteger('count_created')->default(0);
            $table->unsignedInteger('count_updated')->default(0);
            $table->unsignedInteger('count_skipped')->default(0);
            $table->unsignedInteger('count_failed')->default(0);
            // §10 — pesan yang dapat ditindaklanjuti, bukan stack trace.
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Dipakai SY-18 (cek job aktif) dan F-13 (30 run terakhir per user).
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
