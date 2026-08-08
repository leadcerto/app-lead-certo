<?php
// database/migrations/2026_08_08_000001_add_tentativas_envio_falhas_to_tickets_atendimento.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Bloco 5 — conta falhas seguidas de "canal recusou o envio"
            // (ex: janela expirada no Covercut). Zerado sempre que uma
            // mensagem é enviada com sucesso. Não incrementa na pausa da
            // Regra 2 (motivo diferente de null) — só nessa falha específica.
            $table->unsignedTinyInteger('tentativas_envio_falhas')->default(0)->after('mensagem_espera_enviada');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('tentativas_envio_falhas');
        });
    }
};
