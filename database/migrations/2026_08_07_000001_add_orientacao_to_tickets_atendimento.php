<?php
// database/migrations/2026_08_07_000001_add_orientacao_to_tickets_atendimento.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Regra 2 (dúvida do agente) — não-nulo = agente pausado esperando
            // orientação humana. Ver docs/superpowers/specs/2026-08-07-guardrails-resposta-design.md.
            $table->timestamp('aguardando_orientacao_em')->nullable()->after('objetivos_cumpridos');
            // Regra 9 — evita repetir a mensagem de espera a cada mensagem nova
            // do lead durante a mesma pausa.
            $table->boolean('mensagem_espera_enviada')->default(false)->after('aguardando_orientacao_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['aguardando_orientacao_em', 'mensagem_espera_enviada']);
        });
    }
};
