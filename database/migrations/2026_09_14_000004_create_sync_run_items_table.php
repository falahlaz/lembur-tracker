<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F-13 — detail per entri, supaya setiap "dilewati" punya alasan yang terbaca. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kimai_timesheet_id');
            $table->string('action');
            $table->string('reason')->nullable();
            // Record hasilnya boleh hilang (soft delete/hard delete) tanpa menghapus
            // jejak sync-nya — karena itu nullOnDelete, bukan cascade.
            $table->foreignId('overtime_record_id')->nullable()
                ->constrained('overtime_records')->nullOnDelete();
            $table->date('overtime_date')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();

            $table->index(['sync_run_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_items');
    }
};
