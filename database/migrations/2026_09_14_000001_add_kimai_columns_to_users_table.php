<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD Sync Kimai §7 — kredensial Kimai melekat pada user, bukan aplikasi.
 * `base_url` sengaja TIDAK di sini: seluruh tim memakai satu instance, dan
 * menjadikannya field per user hanya menambah permukaan kesalahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // §9 — disimpan dengan cast 'encrypted', tidak pernah plaintext.
            $table->text('kimai_api_token')->nullable()->after('default_late_arrival_time');
            // F-11 — hanya 4 karakter terakhir yang pernah ditampilkan kembali.
            $table->string('kimai_token_last4', 8)->nullable()->after('kimai_api_token');
            $table->timestamp('kimai_token_valid_at')->nullable()->after('kimai_token_last4');
            // SY-21 — dipakai sync otomatis harian (F-14, tahap berikutnya).
            $table->boolean('kimai_auto_sync')->default(false)->after('kimai_token_valid_at');
            // Pengganti SY-02: menandai sampai tanggal berapa Kimai sudah ditarik.
            // Memakai max(overtime_date) akan rusak begitu user mencatat lembur
            // manual hari ini — jendela melompat maju dan entri Kimai yang lebih
            // lama tidak akan pernah tertarik.
            $table->date('kimai_synced_through')->nullable()->after('kimai_auto_sync');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'kimai_api_token', 'kimai_token_last4', 'kimai_token_valid_at',
                'kimai_auto_sync', 'kimai_synced_through',
            ]);
        });
    }
};
