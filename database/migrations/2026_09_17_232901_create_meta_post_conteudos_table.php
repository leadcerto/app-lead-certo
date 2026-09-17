<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_post_conteudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('categoria', 100)->default('geral');
            $table->string('titulo', 150);

            $table->text('texto');
            $table->string('imagem_url', 500)->nullable();

            $table->enum('cta_tipo', ['NENHUM', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'])->default('NENHUM');
            $table->string('cta_url', 500)->nullable();

            $table->enum('modo_gatilho', ['nenhum', 'qualquer_comentario', 'palavra_chave'])->default('nenhum');
            $table->json('palavras_chave')->nullable();
            $table->string('resposta_publica_comentario', 500)->nullable();
            $table->text('mensagem_direct')->nullable();

            $table->boolean('ativo')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'categoria']);
            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_post_conteudos');
    }
};
