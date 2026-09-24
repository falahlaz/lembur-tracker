<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dari mana entri sebuah upload berasal: workbook yang diunggah, atau isian form
 * halaman Isi Timesheet. Tiap halaman hanya memulihkan draf miliknya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_uploads', function (Blueprint $table) {
            $table->string('source')->default('workbook')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_uploads', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
