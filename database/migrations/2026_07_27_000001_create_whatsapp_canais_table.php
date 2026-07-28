<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_canais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('tipo', 20); // 'oficial' | 'nao_oficial'
            $table->string('provider', 20); // 'uazapi' | 'covercut'
            $table->string('status', 20)->default('disconnected'); // 'connected' | 'connecting' | 'disconnected'
            $table->string('phone')->nullable();
            $table->timestamp('connected_since')->nullable();
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_canais');
    }
};
