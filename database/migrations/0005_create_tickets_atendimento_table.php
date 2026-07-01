<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_atendimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('consumidor_id')->constrained('consumidores');
            $table->enum('coluna_kanban', [
                'lead_novo',
                'em_atendimento',
                'aguardando_orcamento',
                'aguardando_lead',
                'encerrado',
            ])->default('lead_novo');
            $table->enum('agente_responsavel', ['bot', 'humano'])->default('bot');
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('etapa_ia', ['etapa_1', 'etapa_2', 'handoff'])->default('etapa_1');
            $table->text('endereco_saida')->nullable();
            $table->text('endereco_destino')->nullable();
            $table->text('lista_itens')->nullable();
            $table->boolean('followup_enviado')->default(false);
            $table->string('tag_desfecho', 100)->nullable();
            $table->timestamp('followup_agendado_em')->nullable();
            $table->enum('status', ['aberto', 'encerrado'])->default('aberto');
            $table->timestamp('aberto_em')->useCurrent();
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'coluna_kanban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_atendimento');
    }
};
