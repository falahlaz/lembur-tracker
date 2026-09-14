<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PRD Sync Kimai §7 — jejak asal-usul record dan pengaman terhadap sync. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('user_id');
            $table->unsignedBigInteger('kimai_timesheet_id')->nullable()->after('source');
            // Referensi saja (Non-Goal §3) — tidak dikelola sebagai master data.
            $table->unsignedInteger('kimai_project_id')->nullable()->after('kimai_timesheet_id');
            $table->unsignedInteger('kimai_activity_id')->nullable()->after('kimai_project_id');

            // SY-10 — `duration` dari Kimai SUDAH bersih dari `break`, sehingga bisa
            // berbeda dari selisih jam. Nilainya disimpan sendiri karena baik observer
            // maupun OvertimeDayCalculator menghitung ulang durasi dari jam mulai/selesai;
            // tanpa kolom ini, angka dari Kimai akan tertimpa diam-diam begitu record
            // lain di tanggal yang sama tersimpan.
            $table->unsignedInteger('break_minutes')->default(0)->after('duration_effective_minutes');
            $table->unsignedInteger('kimai_duration_minutes')->nullable()->after('break_minutes');

            // SY-14 — sekali menyala tidak pernah padam; sync tidak akan menimpanya lagi.
            $table->boolean('locally_modified')->default(false)->after('kimai_duration_minutes');
            // SY-12 — deep link Kimai adalah pengisi sementara, bukan pengganti SPL.
            $table->boolean('evidence_needs_review')->default(false)->after('locally_modified');
            $table->timestamp('synced_at')->nullable()->after('evidence_needs_review');

            // SY-13 — kunci dedup. NULL berulang tetap diizinkan MySQL maupun SQLite,
            // sehingga record manual tidak saling bentrok.
            $table->unique(['user_id', 'kimai_timesheet_id']);
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kimai_timesheet_id']);
            $table->dropColumn([
                'source', 'kimai_timesheet_id', 'kimai_project_id', 'kimai_activity_id',
                'break_minutes', 'kimai_duration_minutes', 'locally_modified',
                'evidence_needs_review', 'synced_at',
            ]);
        });
    }
};
