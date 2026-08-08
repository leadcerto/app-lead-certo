<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Bloco 5 — mesmo padrão de timeout_reassuncao_ativo/segundos
            // (Bloco 2): toggle + valor. Se ninguém orientar uma dúvida
            // pausada (Regra 2) dentro desse prazo, o agente retoma sozinho
            // (ver comando conversas:expirar-pausa-orientacao).
            $table->boolean('duvida_timeout_ativo')->default(false)->after('tempo_maximo_permanencia_minutos');
            $table->unsignedInteger('duvida_timeout_segundos')->nullable()->after('duvida_timeout_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['duvida_timeout_ativo', 'duvida_timeout_segundos']);
        });
    }
};
