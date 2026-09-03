<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tokens de Acesso OAuth da Meta
        Schema::create('meta_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('meta_user_id')->nullable();
            $table->string('nome_usuario')->nullable();
            $table->text('access_token');
            $table->dateTime('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        // 2. Páginas do Facebook Vinculadas
        Schema::create('meta_paginas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('meta_token_id')->constrained('meta_tokens')->cascadeOnDelete();
            $table->string('facebook_page_id');
            $table->string('nome');
            $table->string('categoria')->nullable();
            $table->text('page_access_token');
            $table->string('foto_url', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'facebook_page_id']);
        });

        // 3. Contas do Instagram Business / Creator
        Schema::create('meta_contas_instagram', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('meta_pagina_id')->constrained('meta_paginas')->cascadeOnDelete();
            $table->string('instagram_business_id');
            $table->string('username');
            $table->string('nome')->nullable();
            $table->string('foto_perfil_url', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'instagram_business_id']);
        });

        // 4. Campanhas de Gatilho Comment-to-DM
        Schema::create('meta_campanhas_gatilho', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome');
            $table->enum('canal_alvo', ['instagram', 'facebook', 'ambos'])->default('ambos');
            $table->foreignId('instagram_conta_id')->nullable()->constrained('meta_contas_instagram')->nullOnDelete();
            $table->foreignId('facebook_pagina_id')->nullable()->constrained('meta_paginas')->nullOnDelete();
            $table->string('post_id_especifico')->nullable(); // se null, aplica para qualquer post da conta
            $table->enum('modo_gatilho', ['qualquer_comentario', 'palavra_chave'])->default('palavra_chave');
            $table->json('palavras_chave')->nullable(); // ex: ["saiba mais", "quero", "tabela", "orcamento"]
            $table->text('resposta_publica_comentario')->nullable(); // ex: "Te chamei no direct! Confira lá 😉"
            $table->text('mensagem_direct'); // primeira mensagem enviada no privado
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_campanhas_gatilho');
        Schema::dropIfExists('meta_contas_instagram');
        Schema::dropIfExists('meta_paginas');
        Schema::dropIfExists('meta_tokens');
    }
};
