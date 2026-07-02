<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->uuid('uuid')->unique();                      // ID público usado no widget
            $table->string('nome', 100);
            $table->enum('acao_pos_envio', ['bot_sdr', 'mensagem_unica'])->default('bot_sdr');
            $table->text('mensagem_custom')->nullable();         // usado quando acao = mensagem_unica
            $table->boolean('double_optin')->default(false);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formularios');
    }
};
