<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            $kanbanId = DB::table('kanbans')->where('tenant_id', $tenant->id)->where('tipo', 'vendas')->value('id');

            if (! $kanbanId) {
                continue;
            }

            $configsSemKanbanColunaId = DB::table('kanban_coluna_configs')
                ->where('tenant_id', $tenant->id)
                ->whereNull('kanban_coluna_id')
                ->get(['id', 'coluna_kanban']);

            foreach ($configsSemKanbanColunaId as $config) {
                $colunaId = DB::table('kanban_colunas')
                    ->where('kanban_id', $kanbanId)
                    ->where('chave', $config->coluna_kanban)
                    ->value('id');

                if ($colunaId) {
                    DB::table('kanban_coluna_configs')->where('id', $config->id)->update(['kanban_coluna_id' => $colunaId]);
                }
            }
        }
    }

    public function down(): void
    {
        // Backfill não-destrutivo — down() intencionalmente não reverte kanban_coluna_id,
        // mesmo padrão da migration de 2026-07-17 que já fazia esse mesmo tipo de backfill.
    }
};
