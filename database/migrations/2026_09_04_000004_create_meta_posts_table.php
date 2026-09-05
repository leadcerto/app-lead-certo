<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('canal_alvo', ['facebook', 'instagram', 'ambos']);
            $table->foreignId('meta_pagina_id')->nullable()->constrained('meta_paginas')->nullOnDelete();
            $table->foreignId('meta_conta_instagram_id')->nullable()->constrained('meta_contas_instagram')->nullOnDelete();

            $table->text('texto');
            $table->string('imagem_url', 500)->nullable();

            $table->enum('cta_tipo', ['NENHUM', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'])->default('NENHUM');
            $table->string('cta_url', 500)->nullable();

            $table->enum('modo_gatilho', ['nenhum', 'qualquer_comentario', 'palavra_chave'])->default('nenhum');
            $table->json('palavras_chave')->nullable();
            $table->string('resposta_publica_comentario', 500)->nullable();
            $table->text('mensagem_direct')->nullable();

            $table->dateTime('data_agendada');
            $table->dateTime('publicado_em')->nullable();
            $table->enum('status', ['agendado', 'publicando', 'publicado', 'falha', 'cancelado'])->default('agendado');

            $table->string('facebook_post_id')->nullable();
            $table->string('instagram_media_id')->nullable();
            $table->text('log_erro')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            $table->timestamps();

            $table->index(['status', 'data_agendada'], 'idx_meta_posts_agendamento');
            $table->index(['tenant_id'], 'idx_meta_posts_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_posts');
    }
};
