<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password yang diberikan admin (user baru maupun hasil reset) hanya sementara:
 * selama kolom ini menyala, user harus menggantinya sebelum bisa memakai panel.
 * Default mati supaya user yang sudah ada tidak mendadak terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
