<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->foreignId('kanban_coluna_id')->nullable()->after('coluna_kanban')
                ->constrained('kanban_colunas')->nullOnDelete();
        });

        $tenants = DB::table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            $kanbanId = DB::table('kanbans')->where('tenant_id', $tenant->id)->where('tipo', 'vendas')->value('id');

            if (! $kanbanId) {
                continue;
            }

            $objetivosSemKanbanColunaId = DB::table('kanban_coluna_objetivos')
                ->where('tenant_id', $tenant->id)
                ->whereNull('kanban_coluna_id')
                ->get(['id', 'coluna_kanban']);

            foreach ($objetivosSemKanbanColunaId as $objetivo) {
                $colunaId = DB::table('kanban_colunas')
                    ->where('kanban_id', $kanbanId)
                    ->where('chave', $objetivo->coluna_kanban)
                    ->value('id');

                if ($colunaId) {
                    DB::table('kanban_coluna_objetivos')->where('id', $objetivo->id)->update(['kanban_coluna_id' => $colunaId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_coluna_id');
        });
    }
};
