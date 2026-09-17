<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Satu baris per upload workbook timesheet, mengikuti bentuk sync_runs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            // sha256 isi berkas — dipakai memperingatkan "berkas ini sudah pernah
            // diunggah", bukan memblokirnya: mengunggah ulang berkas yang sama
            // memang sah setelah entri lamanya dihapus di Kimai.
            $table->string('file_hash', 64)->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            // Project yang BENAR-BENAR dipakai, setelah dikonfirmasi user — bukan
            // yang terbaca dari workbook. Keduanya bisa berbeda.
            $table->unsignedInteger('project_id')->nullable();
            $table->string('status')->default('draft');
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->unsignedInteger('count_parsed')->default(0);
            $table->unsignedInteger('count_skipped')->default(0);
            $table->unsignedInteger('count_posted')->default(0);
            $table->unsignedInteger('count_failed')->default(0);
            // Kimai bisa tidak terjangkau saat pratinjau. Kalau begitu, pemeriksaan
            // duplikat TIDAK boleh terlihat seperti lulus.
            $table->boolean('duplicates_checked')->default(false);
            $table->text('issues')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_uploads');
    }
};
