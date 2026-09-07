<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_version_id')->constrained('overtime_rules');   // BR-24
            $table->foreignId('payroll_period_id')->nullable()->constrained('payroll_periods');

            // Tanggal murni & jam murni — TIDAK pernah dikonversi timezone.
            $table->date('overtime_date');
            $table->time('start_time');
            $table->time('end_time');

            // BR-04 — ketiganya selalu disimpan agar pembulatan tidak pernah tersembunyi.
            $table->unsignedInteger('duration_raw_minutes');
            $table->unsignedInteger('duration_effective_minutes');
            $table->boolean('rounding_applied')->default(false);

            // BR-05/BR-07 — entitlement melekat pada TANGGAL. Bila satu tanggal punya
            // beberapa record, nilainya ditempelkan ke record paling awal dan nol di sisanya.
            $table->unsignedTinyInteger('tier')->default(0);
            $table->unsignedBigInteger('meal_allowance_amount')->default(0);
            $table->unsignedInteger('leave_credit_minutes')->default(0);

            $table->text('work_description');
            $table->string('evidence_url', 2048);
            $table->string('status')->default('recorded');
            $table->text('notes')->nullable();

            // OQ-2 — jejak bila admin mencatat atas nama user lain (backfill).
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();   // NFR retensi — tidak ada hard delete

            $table->unique(['user_id', 'overtime_date', 'start_time']);
            $table->index(['user_id', 'overtime_date']);
            $table->index(['user_id', 'status']);
            $table->index('payroll_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
