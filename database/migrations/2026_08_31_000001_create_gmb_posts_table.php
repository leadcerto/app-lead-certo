<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('perfil_gmb_id')->constrained('perfis_gmb')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            // Formato e Conteúdo
            $table->enum('tipo', ['novidade', 'oferta', 'evento'])->default('novidade');
            $table->string('titulo')->nullable();
            $table->text('texto');
            $table->string('imagem_url', 500)->nullable();

            // Chamada para Ação (CTA)
            $table->enum('cta_tipo', ['NENHUM', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'])->default('LEARN_MORE');
            $table->string('cta_url', 500)->nullable();

            // Dados Específicos para Ofertas e Eventos
            $table->dateTime('data_inicio_evento')->nullable();
            $table->dateTime('data_fim_evento')->nullable();
            $table->string('codigo_cupom', 100)->nullable();
            $table->string('link_resgate', 500)->nullable();
            $table->text('termos_condicoes')->nullable();

            // Agendamento e Ciclo de Vida
            $table->dateTime('data_agendada');
            $table->dateTime('publicado_em')->nullable();
            $table->enum('status', ['rascunho', 'agendado', 'publicando', 'publicado', 'falha', 'cancelado'])->default('agendado');

            // Retornos da API do Google
            $table->string('google_post_id')->nullable();
            $table->string('google_post_url', 500)->nullable();
            $table->text('log_erro')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            // Inteligência Artificial e Metadados
            $table->boolean('gerado_por_ia')->default(false);
            $table->text('prompt_ia_utilizado')->nullable();

            $table->timestamps();

            // Índices de performance para busca do scheduler
            $table->index(['status', 'data_agendada'], 'idx_gmb_posts_agendamento');
            $table->index(['tenant_id', 'perfil_gmb_id'], 'idx_gmb_posts_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_posts');
    }
};
