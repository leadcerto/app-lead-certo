<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 150);
            $table->string('nicho', 100)->comment('frete, pizzaria, curso_digital');
            $table->enum('status', ['setup', 'ativo', 'suspenso', 'cancelado'])->default('setup');
            $table->string('whatsapp_status', 20)->default('disconnected');
            $table->string('whatsapp_phone', 30)->nullable();
            $table->timestamp('whatsapp_connected_since')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
