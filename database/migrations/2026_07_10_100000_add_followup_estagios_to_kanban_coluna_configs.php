<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Tempo de silêncio (segundos, desde a última mensagem da conversa,
            // de qualquer remetente) até cada estágio de reengajamento disparar.
            // Precisam ficar em ordem crescente: estagio1 < estagio2 < estagio3.
            $table->unsignedInteger('followup_estagio1_segundos')->default(3600)->after('sdr_delay_segundos');
            $table->unsignedInteger('followup_estagio2_segundos')->default(7200)->after('followup_estagio1_segundos');
            $table->unsignedInteger('followup_estagio3_segundos')->default(21600)->after('followup_estagio2_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['followup_estagio1_segundos', 'followup_estagio2_segundos', 'followup_estagio3_segundos']);
        });
    }
};
