<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BR-09 — periode berjalan dari tanggal 19 bulan sebelumnya s/d tanggal 18 bulan berjalan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('label');   // contoh: "Maret 2026"
            $table->timestamps();

            $table->unique(['period_start', 'period_end']);
            $table->index('period_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
