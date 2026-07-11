<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Maior estágio de silêncio (0, 1, 2 ou 3) já disparado para este
            // ticket — evita reenviar o mesmo estágio a cada execução do
            // comando conversas:followup. Zerado sempre que o lead responde
            // (ver UazapiWebhookController).
            $table->unsignedTinyInteger('followup_estagio_enviado')->default(0)->after('botoes_ativos');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('followup_estagio_enviado');
        });
    }
};
