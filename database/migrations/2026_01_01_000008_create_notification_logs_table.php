<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F-07 — mencegah reminder terkirim ganda. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('channel')->default('mail');
            $table->timestamp('sent_at');
            $table->timestamps();

            // Kunci idempotensi: satu jenis reminder, satu objek, satu kali.
            $table->unique(['user_id', 'type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
