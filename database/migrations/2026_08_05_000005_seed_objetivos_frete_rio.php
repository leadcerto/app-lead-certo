<?php
// database/migrations/2026_08_05_000005_seed_objetivos_frete_rio.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migra os 6 itens que eram hardcoded em
     * SdrResponderService::derivarChecklist() (removido nesta mesma entrega,
     * ver Task 3 do plano de 2026-08-05) pro novo formato configurável —
     * pra não perder a funcionalidade que o Frete Rio já tinha.
     */
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('nome', 'Frete Rio')->value('id');

        if (! $tenantId) {
            return; // ambiente sem o tenant Frete Rio (ex.: testes) — nada a fazer
        }

        $itens = [
            'Endereço de embarque confirmado',
            'Endereço de destino confirmado',
            'Lista de itens coletada',
            'Data e horário confirmados',
            'Escadas (lances reais) confirmadas',
            'Desmontagem/embalagem detalhada',
        ];

        $agora = now();

        foreach ($itens as $indice => $texto) {
            DB::table('kanban_coluna_objetivos')->insert([
                'tenant_id'     => $tenantId,
                'coluna_kanban' => 'em_atendimento',
                'texto'         => $texto,
                'ordem'         => $indice + 1,
                'ativo'         => true,
                'created_at'    => $agora,
                'updated_at'    => $agora,
            ]);
        }
    }

    /**
     * Não reverte a exclusão dos registros — reverter uma migration de dados
     * apagaria objetivos que o franqueado pode já ter editado desde então.
     * Se precisar desfazer, apagar manualmente pela tela de configuração.
     */
    public function down(): void
    {
        //
    }
};
