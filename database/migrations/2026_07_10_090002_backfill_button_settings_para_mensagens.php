<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Botões passaram de "um conjunto por coluna" para "por mensagem".
     * Aqui migramos o que já estava configurado (kanban_coluna_configs.button_settings)
     * para a última mensagem da sequência mais recente daquela coluna — reproduzindo
     * o comportamento antigo (botões disparavam junto com a última mensagem da
     * sequência) como estado inicial, sem perder nada que o usuário já salvou.
     */
    public function up(): void
    {
        $configs = DB::table('kanban_coluna_configs')
            ->whereNotNull('button_settings')
            ->get();

        foreach ($configs as $config) {
            $botoes = json_decode($config->button_settings, true);
            if (empty($botoes)) {
                continue;
            }

            $sequencia = DB::table('sequencias')
                ->where('tenant_id', $config->tenant_id)
                ->where('coluna_kanban', $config->coluna_kanban)
                ->orderByDesc('id')
                ->first();

            if (! $sequencia) {
                continue;
            }

            $ultimaMensagem = DB::table('sequencia_mensagens')
                ->where('sequencia_id', $sequencia->id)
                ->orderByDesc('ordem')
                ->first();

            if (! $ultimaMensagem || $ultimaMensagem->button_settings !== null) {
                continue;
            }

            DB::table('sequencia_mensagens')
                ->where('id', $ultimaMensagem->id)
                ->update(['button_settings' => $config->button_settings]);
        }
    }

    public function down(): void
    {
        // Backfill não é reversível com segurança (não sabemos quais linhas
        // foram tocadas aqui vs. configuradas manualmente depois).
    }
};
