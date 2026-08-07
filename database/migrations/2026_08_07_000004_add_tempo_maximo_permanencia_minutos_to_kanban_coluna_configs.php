<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Regra 12 — tempo máximo (em minutos) que um ticket pode ficar
            // nessa coluna antes do comando kanban:monitorar (Regra 3)
            // alertar que travou. Null = coluna não monitorada. Sem valor
            // default de fallback (diferente de timeout_reassuncao_segundos,
            // Bloco 2): não existe um "tempo esperado" genérico que sirva
            // pra qualquer coluna, então null tem que significar "desligado"
            // de verdade, sem um número escondido assumindo o controle.
            $table->unsignedInteger('tempo_maximo_permanencia_minutos')->nullable()->after('aguardando_orientacao_mensagem');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('tempo_maximo_permanencia_minutos');
        });
    }
};
