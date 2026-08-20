<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do Leonardo 2026-08-20: campo na página do agente onde empresas
 * logadas conversam direto com ele — não passa pela IA de atendimento
 * (SdrResponderService/TicketAtendimento), é deliberadamente simples: a
 * empresa escreve, o sistema anota e responde com uma frase padrão dizendo
 * que o assunto vai pra próxima reunião da equipe. Objetivo: feedback,
 * satisfação, problemas, soluções e próximos passos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks_agente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // o agente (Adriana, Nathanel...)
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete(); // empresa que mandou
            $table->foreignId('autor_user_id')->nullable()->constrained('users')->nullOnDelete(); // quem exatamente mandou
            $table->text('mensagem');
            $table->text('resposta');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks_agente');
    }
};
