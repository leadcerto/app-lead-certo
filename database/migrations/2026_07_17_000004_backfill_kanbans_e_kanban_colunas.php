<?php

use App\Models\KanbanColuna;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUNAS = [
        ['chave' => 'lead_novo',            'label' => 'Novo',                 'emoji' => '🟢', 'papel' => 'entrada',              'ordem' => 1],
        ['chave' => 'em_atendimento',       'label' => 'Em Atendimento',       'emoji' => '🔵', 'papel' => 'em_andamento',         'ordem' => 2],
        ['chave' => 'aguardando_orcamento', 'label' => 'Aguardando Orçamento', 'emoji' => '🟡', 'papel' => 'em_andamento',         'ordem' => 3],
        ['chave' => 'aguardando_lead',      'label' => 'Aguardando Lead',      'emoji' => '🟠', 'papel' => 'em_andamento',         'ordem' => 4],
        ['chave' => 'pagamento',            'label' => 'Pagamento',            'emoji' => '💳', 'papel' => 'em_andamento',         'ordem' => 5],
        ['chave' => 'servico_agendado',     'label' => 'Serviço Agendado',     'emoji' => '📅', 'papel' => 'em_andamento',         'ordem' => 6],
        ['chave' => 'encerrado',            'label' => 'Encerrado',            'emoji' => '⚫', 'papel' => 'encerramento',         'ordem' => 7],
        ['chave' => 'outros',               'label' => 'Outros / Internos',    'emoji' => '👤', 'papel' => 'transferencia_humana', 'ordem' => 8],
    ];

    private const ETAPA_POR_CHAVE = [
        'aguardando_orcamento' => 'handoff',
        'servico_agendado'     => 'handoff',
        'encerrado'            => 'handoff',
    ];

    public function up(): void
    {
        $tenants = DB::table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            $kanbanId = DB::table('kanbans')->where('tenant_id', $tenant->id)->where('tipo', 'vendas')->value('id');

            if (! $kanbanId) {
                $kanbanId = DB::table('kanbans')->insertGetId([
                    'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach (self::COLUNAS as $def) {
                $colunaId = DB::table('kanban_colunas')
                    ->where('kanban_id', $kanbanId)->where('chave', $def['chave'])->value('id');

                if (! $colunaId) {
                    $colunaId = DB::table('kanban_colunas')->insertGetId([
                        'tenant_id' => $tenant->id, 'kanban_id' => $kanbanId,
                        'chave' => $def['chave'], 'label' => $def['label'], 'emoji' => $def['emoji'],
                        'papel' => $def['papel'], 'ordem' => $def['ordem'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                DB::table('kanban_coluna_configs')
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $def['chave'])
                    ->whereNull('kanban_coluna_id')
                    ->update([
                        'kanban_coluna_id'  => $colunaId,
                        'etapa_ia_ao_mover' => self::ETAPA_POR_CHAVE[$def['chave']] ?? 'etapa_1',
                    ]);
            }

            // As inserções/updates acima usam o query-builder direto (DB::table(...)),
            // que não dispara os eventos saved/deleted do Eloquent — os únicos hooks que
            // limpam o cache "kanban_colunas:{tenantId}" (ver KanbanColuna::doTenant()).
            // Sem isso, um tenant cujo cache já estivesse aquecido antes do deploy
            // continuaria servindo resultado stale por até CACHE_TTL_SEGUNDOS.
            KanbanColuna::limparCache($tenant->id);
        }
    }

    public function down(): void
    {
        // Backfill não-destrutivo — down() intencionalmente não remove kanbans/kanban_colunas
        // criados, pra não arriscar apagar dado que passou a ser referenciado por tickets.
    }
};
