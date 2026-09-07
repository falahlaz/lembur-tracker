<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-24 — aturan berversi. Nominal, threshold, cut-off, masa berlaku dan kuota
 * disimpan per rentang berlaku, dan setiap record lembur mengunci versi yang
 * dipakai saat dihitung. Revisi SOP tidak menulis ulang sejarah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->unsignedInteger('tier1_min_minutes')->default(240);
            $table->unsignedBigInteger('tier1_meal_amount')->default(50000);
            $table->unsignedInteger('tier1_leave_minutes')->default(240);

            $table->unsignedInteger('tier2_min_minutes')->default(480);
            $table->unsignedBigInteger('tier2_meal_amount')->default(100000);
            $table->unsignedInteger('tier2_leave_minutes')->default(480);

            $table->unsignedTinyInteger('cutoff_day')->default(19);
            $table->unsignedTinyInteger('expiry_months')->default(1);
            $table->decimal('monthly_claim_quota_days', 4, 1)->default(3.0);

            $table->time('work_start_time')->default('09:00:00');
            $table->time('work_end_time')->default('18:00:00');

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_rules');
    }
};
