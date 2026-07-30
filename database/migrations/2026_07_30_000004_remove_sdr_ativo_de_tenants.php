<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Achado real (Leonardo, 2026-07-30): este campo (toggle "Agente IA (SDR)"
        // na tela de Configurações) nunca foi checado em NENHUM ponto de envio de
        // mensagem desde a migração pra "agente por Kanban" (kanban_coluna_configs.
        // ia_ativo) — ligar/desligar não tinha efeito nenhum. Removido junto com o
        // controller/rota/UI correspondentes.
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('sdr_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('sdr_ativo')->default(false);
        });
    }
};
