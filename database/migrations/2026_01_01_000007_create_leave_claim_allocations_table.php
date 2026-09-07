<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-15 — ledger FIFO. Setiap potongan dicatat satu baris, sehingga setiap klaim
 * dapat ditelusuri kembali ke tanggal lembur asalnya (G-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_claim_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_balance_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('allocated_minutes');
            $table->timestamps();

            $table->unique(['leave_claim_id', 'leave_balance_id']);
            $table->index('leave_balance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_claim_allocations');
    }
};
