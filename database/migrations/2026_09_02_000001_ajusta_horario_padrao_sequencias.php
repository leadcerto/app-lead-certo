<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ajusta as sequências para que apenas a coluna 'aguardando_lead' tenha restrição
     * de horário (11:00 às 13:00), enquanto todas as demais colunas operem 24 horas por padrão.
     */
    public function up(): void
    {
        // 1. Coluna 'aguardando_lead' mantém ou define a janela 11h-13h
        DB::table('sequencias')
            ->where('coluna_kanban', 'aguardando_lead')
            ->update([
                'horario_ativo'  => true,
                'horario_inicio' => '11:00:00',
                'horario_fim'    => '13:00:00',
            ]);

        // 2. Todas as outras colunas (ex: 'lead_novo', 'novo', 'orcamento', etc.) operam 24 horas
        DB::table('sequencias')
            ->where('coluna_kanban', '!=', 'aguardando_lead')
            ->orWhereNull('coluna_kanban')
            ->update([
                'horario_ativo' => false,
            ]);
    }

    public function down(): void
    {
        // Sem reversão necessária
    }
};
