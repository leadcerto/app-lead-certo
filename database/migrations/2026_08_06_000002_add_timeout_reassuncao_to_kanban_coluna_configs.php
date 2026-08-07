<?php
// database/migrations/2026_08_06_000002_add_timeout_reassuncao_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Reassunção automática do agente quando o humano assume e some —
            // independente dos Estágios de silêncio e do Auto-mover (que agem
            // do lado do lead, não do atendente). Ver Regra 1/4/8 em
            // docs/superpowers/specs/2026-08-06-regras-atendimento-ia-humano-contexto.md.
            $table->boolean('timeout_reassuncao_ativo')->default(false)->after('auto_mover_mensagem');
            $table->unsignedInteger('timeout_reassuncao_segundos')->nullable()->after('timeout_reassuncao_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['timeout_reassuncao_ativo', 'timeout_reassuncao_segundos']);
        });
    }
};
