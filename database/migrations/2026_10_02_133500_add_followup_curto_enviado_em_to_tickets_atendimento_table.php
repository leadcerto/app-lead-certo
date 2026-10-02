<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achado real 02/10 (Leonardo, tickets #4932 e #5001): o follow-up "curto"
 * (10-90min de silêncio) não tinha nenhuma trava de "já mandei essa
 * cutucada", diferente dos estágios 1/2/3 (que usam
 * `followup_estagio_enviado`). O cron roda a cada 5min e, enquanto a última
 * mensagem continuar sendo do lead dentro da janela de 10-90min, chamava a
 * IA de novo a cada ciclo — até 16 chamadas desnecessárias pro mesmo
 * silêncio (confirmado em produção: pares de mensagens "quase iguais"
 * mandadas minutos apart, e depois de 02/10 — já com o token
 * [SEM_RESPOSTA] — chamadas repetidas à IA sem nenhum envio real, mas
 * ainda queimando crédito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->timestamp('followup_curto_enviado_em')->nullable()->after('followup_estagio_enviado');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('followup_curto_enviado_em');
        });
    }
};
