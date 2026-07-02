<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_registrados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('user_id')->constrained('users');
            $table->string('fcm_token', 255);
            $table->string('dispositivo', 100)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('ultimo_ping_em')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fcm_token'], 'uq_user_fcm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_registrados');
    }
};
