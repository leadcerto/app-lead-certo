<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela pivô central: une PerfilGmb × TemplateAvaliacao × User (avaliador).
     * Cada registro é uma tarefa agendada para um avaliador (funcionário da
     * empresa terceirizada) ligar para um cliente real que já usou o serviço
     * do perfil, oferecer o texto sugerido, e o próprio cliente decidir se
     * publica (ou não) a avaliação na conta dele no Google.
     *
     * Status: pendente → enviado → concluido
     */
    public function up(): void
    {
        Schema::create('agendamentos_avaliacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignId('perfil_id')
                  ->constrained('perfis_gmb')
                  ->cascadeOnDelete();

            $table->foreignId('template_id')
                  ->constrained('templates_avaliacao')
                  ->cascadeOnDelete();

            $table->foreignId('avaliador_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->date('data_agendada');

            $table->enum('status', ['pendente', 'enviado', 'concluido'])
                  ->default('pendente');

            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            // Índices para consultas frequentes
            $table->index(['tenant_id', 'data_agendada', 'status'], 'idx_agend_tenant_data_status');
            $table->index(['avaliador_id', 'data_agendada', 'status'], 'idx_agend_avaliador_data_status');
            $table->index(['perfil_id', 'data_agendada'], 'idx_agend_perfil_data');
            $table->index(['perfil_id', 'template_id'], 'idx_agend_perfil_template');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agendamentos_avaliacao');
    }
};
