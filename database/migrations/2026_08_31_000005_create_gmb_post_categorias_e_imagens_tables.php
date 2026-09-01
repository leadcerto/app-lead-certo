<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gmb_post_categorias')) {
            Schema::create('gmb_post_categorias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('nome', 100);
                $table->string('slug', 100);
                $table->json('palavras_chave')->nullable();
                $table->boolean('ativo')->default(true);
                $table->timestamps();

                $table->index(['tenant_id', 'slug']);
            });
        }

        if (!Schema::hasTable('gmb_post_imagens')) {
            Schema::create('gmb_post_imagens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('titulo', 150)->nullable();
                $table->string('palavras_chave', 255)->nullable();
                $table->string('imagem_url', 500);
                $table->string('nome_arquivo_original', 255)->nullable();
                $table->string('nome_arquivo_seo', 255)->nullable();
                $table->unsignedBigInteger('tamanho_bytes')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_post_imagens');
        Schema::dropIfExists('gmb_post_categorias');
    }
};
