<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets_atendimento')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->enum('remetente', ['lead', 'bot', 'humano']);
            $table->enum('tipo', ['texto', 'imagem', 'audio', 'video', 'documento'])->default('texto');
            $table->text('conteudo')->nullable();
            $table->string('midia_url', 500)->nullable();
            $table->timestamp('enviado_em')->useCurrent();

            $table->index(['ticket_id', 'enviado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
    }
};
