<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Campanhas de Mineração ────────────────────────────────────────────
        // Growth Manager define aqui O QUE e ONDE os agentes vão minerar.
        Schema::create('campanhas_mineracao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nome', 200);
            $table->text('descricao')->nullable();

            // O alvo: o que procurar e onde
            $table->string('nicho', 300)->nullable();         // "clínicas de estética"
            $table->string('regiao_alvo', 300)->nullable();   // "São Paulo, SP"
            $table->text('palavras_chave')->nullable();        // keywords para mineradores de busca

            $table->enum('status', ['rascunho', 'ativa', 'pausada', 'concluida'])->default('rascunho');
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();

            $table->unsignedInteger('meta_contatos')->nullable();  // objetivo de quantos contatos
            $table->unsignedInteger('contatos_importados')->default(0); // contador automático

            // Configurações extras por tipo de campanha (JSON livre)
            $table->json('configuracoes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // ── Agentes Mineradores (credenciais M2M) ─────────────────────────────
        // Cada agente de IA tem sua própria chave. Não são logins humanos.
        Schema::create('agentes_mineradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('campanha_id')
                  ->nullable()
                  ->constrained('campanhas_mineracao')
                  ->nullOnDelete();

            $table->string('nome', 200);                  // "Minerador Instagram - Frete do Leo"
            $table->enum('tipo', [
                'instagram', 'facebook', 'google', 'email',
                'whatsapp', 'linkedin', 'tiktok', 'custom',
            ])->default('custom');

            // Chave de API gerada no cadastro. Armazenada como hash SHA-256.
            // O valor real é exibido UMA ÚNICA VEZ no cadastro.
            $table->string('api_key_prefix', 8)->index();   // "mk_A1B2C3" — para lookup
            $table->string('api_key_hash', 64)->unique();   // SHA-256 da chave completa

            $table->json('escopo')->nullable();              // ['gravar_contatos', 'ler_campanha']
            $table->json('configuracoes')->nullable();        // config específica do tipo

            $table->enum('status', ['ativo', 'inativo', 'suspenso'])->default('ativo');
            $table->timestamp('ultima_execucao_em')->nullable();
            $table->unsignedInteger('contatos_importados')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentes_mineradores');
        Schema::dropIfExists('campanhas_mineracao');
    }
};
