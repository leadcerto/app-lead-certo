<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default true — preserva o comportamento atual (transcrição de áudio
        // e análise de imagem/documento já rodam pra todo mundo hoje) pra
        // nenhum tenant existente perder a funcionalidade silenciosamente. O
        // franqueado desliga explicitamente por coluna quem não quiser.
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->boolean('transcricao_ativa')->default(true)->after('foco_analise_imagem');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('transcricao_ativa');
        });
    }
};
