<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data kepala & kaki Surat Perintah Lembur (SPL) yang tidak ada di catatan lembur:
 * divisi, jabatan, dua penyetuju, dan tanda tangan. Diisi user sendiri di Profil
 * sekali saja, lalu dipakai setiap SPL diunduh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('division')->nullable()->after('default_late_arrival_time');
            $table->string('position')->nullable()->after('division');
            // Pengisi kolom Project untuk lembur yang tidak berasal dari Kimai.
            $table->string('default_project')->nullable()->after('position');
            $table->string('approver1_name')->nullable()->after('default_project');
            $table->string('approver1_position')->nullable()->after('approver1_name');
            $table->string('approver2_name')->nullable()->after('approver1_position');
            $table->string('approver2_position')->nullable()->after('approver2_name');
            // Path relatif di disk `local` (privat) — tidak pernah disajikan publik.
            $table->string('signature_path')->nullable()->after('approver2_position');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'division', 'position', 'default_project',
                'approver1_name', 'approver1_position',
                'approver2_name', 'approver2_position',
                'signature_path',
            ]);
        });
    }
};
