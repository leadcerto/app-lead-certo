<?php

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // A exclusão definitiva deixa de ser um único prazo global por tenant
        // (tenants.retencao_conversas_dias, removido na migration seguinte) e
        // passa a ser configurável por coluna. Sem este backfill, qualquer
        // tenant que já tivesse retenção configurada perderia a limpeza
        // automática silenciosamente no dia do deploy. Copia o valor atual
        // pra dentro da(s) coluna(s) de papel Encerramento do tenant — mesmo
        // comportamento de antes (só muda onde o número mora), sem precisar
        // reconfigurar nada.
        Tenant::whereNotNull('retencao_conversas_dias')
            ->where('retencao_conversas_dias', '>', 0)
            ->each(function (Tenant $tenant) {
                $colunasEncerramento = KanbanColuna::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('papel', PapelColunaKanban::Encerramento)
                    ->get();

                foreach ($colunasEncerramento as $coluna) {
                    KanbanColunaConfig::withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => $tenant->id, 'coluna_kanban' => $coluna->chave],
                        [
                            'exclusao_definitiva_ativo' => true,
                            'exclusao_definitiva_dias'  => $tenant->retencao_conversas_dias,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        // Backfill não reverte com segurança (não dá pra distinguir, depois do
        // fato, um exclusao_definitiva_dias preenchido por este backfill de um
        // que o próprio tenant configurou manualmente depois). Reversão manual
        // se necessário.
    }
};
