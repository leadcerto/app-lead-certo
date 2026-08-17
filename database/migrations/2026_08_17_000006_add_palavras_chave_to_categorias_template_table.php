<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Palavras-chave que dão direção de conteúdo pro gerador de texto por IA
     * (TemplateAvaliacaoIaService) — cada empresa define as suas por categoria.
     */
    public function up(): void
    {
        Schema::table('categorias_template', function (Blueprint $table) {
            $table->json('palavras_chave')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('categorias_template', function (Blueprint $table) {
            $table->dropColumn('palavras_chave');
        });
    }
};
