<?php
// database/migrations/2026_08_07_000005_add_origem_and_alertado_em_to_kanban_coluna_historico.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_historico', function (Blueprint $table) {
            // Regra 13 (Bloco 4) — quem causou essa entrada na coluna. Só
            // marcado 'humano' pelos dois endpoints de movimentação manual
            // (KanbanController::mover/moverParaOutros, Task 6); todo o
            // resto (token de coluna da IA, followup automático, webhook,
            // botões) assume 'ia'. Linhas criadas antes deste bloco ficam
            // nulas — sem backfill, só passa a valer daqui pra frente.
            $table->string('origem', 10)->nullable()->after('coluna_anterior');
            // Regra 3 (Bloco 4) — marca que o comando kanban:monitorar já
            // alertou por essa permanência específica na coluna (dedup:
            // reseta sozinho quando uma nova linha é criada pra esse ticket).
            $table->timestamp('alertado_em')->nullable()->after('entrou_em');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_historico', function (Blueprint $table) {
            $table->dropColumn(['origem', 'alertado_em']);
        });
    }
};
