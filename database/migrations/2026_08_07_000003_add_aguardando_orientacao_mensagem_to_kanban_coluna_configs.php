<?php
// database/migrations/2026_08_07_000003_add_aguardando_orientacao_mensagem_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Regra 9 — mensagem padrão configurável por coluna, mandada uma
            // única vez se o lead insistir enquanto o agente aguarda orientação.
            $table->text('aguardando_orientacao_mensagem')->nullable()->after('timeout_reassuncao_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('aguardando_orientacao_mensagem');
        });
    }
};
