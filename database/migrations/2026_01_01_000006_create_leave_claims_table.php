<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('claim_date');
            $table->string('claim_type');                       // BR-18
            $table->time('arrival_time')->nullable();           // hanya untuk late_arrival
            $table->unsignedInteger('minutes_required');
            $table->decimal('quota_weight', 3, 1);              // BR-19: 1.0 / 0.5

            $table->string('status')->default('draft');
            // BR-23 — ditandai bila lembur sumbernya dibatalkan setelah saldo terpakai.
            $table->boolean('needs_review')->default(false);

            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // BR-21 (tidak boleh tumpang tindih) ditegakkan di level APLIKASI, bukan
            // unique index — aturannya hanya berlaku untuk status aktif, dan predikat
            // parsial semacam itu tidak portabel antara SQLite dan MySQL.
            $table->index(['user_id', 'claim_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_claims');
    }
};
