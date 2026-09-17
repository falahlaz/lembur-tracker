<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cermin baca-saja daftar activity Kimai — lihat catatan di migrasi kimai_projects. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kimai_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('kimai_id')->unique();
            $table->string('name');
            // NULL berarti activity GLOBAL: berlaku di semua project.
            //
            // Sengaja BUKAN foreignId()->constrained(): sebuah activity bisa
            // menunjuk project yang tidak pernah dikembalikan /api/projects
            // (project tersembunyi bagi token yang menyinkronkan), dan foreign key
            // akan menggagalkan seluruh sync gara-gara satu baris yang justru tidak
            // penting. Relasinya cukup dideklarasikan di model.
            $table->unsignedInteger('project_id')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['project_id', 'name']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kimai_activities');
    }
};
