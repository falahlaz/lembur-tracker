<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cermin baca-saja daftar project Kimai.
 *
 * Bedanya dengan `overtime_records.kimai_project_id` — yang sengaja dicatat
 * sebagai "referensi saja, tidak dikelola sebagai master data" — tabel ini
 * memang salinan: diisi HANYA oleh sync katalog, tidak pernah disunting manusia,
 * dan boleh dihapus lalu diisi ulang kapan saja tanpa kehilangan apa pun. Kimai
 * tetap satu-satunya sumber kebenaran; salinannya ada supaya legenda bisa dibaca
 * tanpa token pribadi dan tetap berguna saat Kimai tidak terjangkau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kimai_projects', function (Blueprint $table) {
            // Kunci primer tetap milik kita sendiri, dan id Kimai jadi kolom unik.
            // Memakai id Kimai sebagai PK memaksa $incrementing = false di model dan
            // merembes ke kunci baris Filament; `unique` sudah cukup untuk upsert().
            $table->id();
            $table->unsignedInteger('kimai_id')->unique();
            $table->string('name');
            // parentTitle di respons Kimai = nama customer.
            $table->string('customer')->nullable();
            // Kapan baris ini terakhir dipastikan masih ada di Kimai. Dipakai
            // menjawab "terakhir disinkronkan kapan" tanpa tabel metadata terpisah.
            // BUKAN penanda pemangkasan — baris yang hilang dari Kimai dibuang lewat
            // selisih himpunan id, supaya dua sync pada detik yang sama tidak lolos.
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kimai_projects');
    }
};
