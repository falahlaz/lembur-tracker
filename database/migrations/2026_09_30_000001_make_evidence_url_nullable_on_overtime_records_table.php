<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence (SPL/timesheet di OneDrive) biasanya baru ada belakangan, jadi
 * lembur boleh dicatat dulu tanpa link dan dilengkapi manual nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('evidence_url', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('overtime_records')->whereNull('evidence_url')->update(['evidence_url' => '']);

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('evidence_url', 2048)->nullable(false)->change();
        });
    }
};
