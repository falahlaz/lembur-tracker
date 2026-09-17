<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sheet kini memakai NAMA activity, bukan id-nya.
 *
 * Nama disimpan berdampingan dengan id, bukan menggantikannya: ganti project di
 * pratinjau berarti nama yang sama harus diresolusi ulang ke id yang berbeda, dan
 * itu hanya mungkin kalau nama aslinya masih ada. Sel format lama
 * (`Activity ID: N`) menyimpan id dengan nama NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_upload_entries', function (Blueprint $table) {
            $table->string('activity_name')->nullable()->after('activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_upload_entries', function (Blueprint $table) {
            $table->dropColumn('activity_name');
        });
    }
};
