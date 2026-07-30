<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Substitui tenants.retencao_conversas_dias (global) — a exclusão
            // definitiva passa a ser configurável por coluna, contada a partir do
            // fechamento real do ticket (encerrado_em), não da última atualização.
            // Campo _ativo separado (mesmo padrão de auto_mover_ativo) em vez de
            // usar só a nulidade de _dias — evita depender de conseguir mandar
            // null explícito pra "desligar" (o controller filtra valores null do
            // payload antes de salvar).
            $table->boolean('exclusao_definitiva_ativo')->default(false)->after('auto_mover_mensagem');
            $table->unsignedInteger('exclusao_definitiva_dias')->nullable()->after('exclusao_definitiva_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['exclusao_definitiva_ativo', 'exclusao_definitiva_dias']);
        });
    }
};
