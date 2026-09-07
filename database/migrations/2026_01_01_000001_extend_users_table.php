<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('employee')->after('email');
            // Fase 2 (PRD §5) — kolom disiapkan sejak awal agar penambahan
            // peran Atasan/PM nanti tidak memerlukan migrasi struktural.
            $table->foreignId('manager_id')->nullable()->after('role')
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('manager_id');
            // BR-04 — preferensi pembulatan durasi, default MATI.
            $table->boolean('rounding_enabled')->default(false)->after('is_active');
            $table->json('notification_prefs')->nullable()->after('rounding_enabled');
            $table->time('default_late_arrival_time')->default('13:00:00')->after('notification_prefs');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn([
                'role', 'is_active', 'rounding_enabled',
                'notification_prefs', 'default_late_arrival_time',
            ]);
        });
    }
};
