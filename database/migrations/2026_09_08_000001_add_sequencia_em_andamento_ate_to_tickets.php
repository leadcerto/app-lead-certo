<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna sequencia_em_andamento_ate em tickets_atendimento.
 *
 * Propósito: bloquear o SdrResponderJob (IA) de responder enquanto a
 * sequência automática ainda está em curso. Sem essa trava, o lead que
 * respondia antes de a sequência terminar recebia resposta da IA logo
 * em seguida — o comportamento correto é aguardar a sequência concluir
 * antes de qualquer interação da IA.
 *
 * O campo é preenchido por SequenciaService::iniciarParaTicket() com a
 * data/hora do fim estimado da última mensagem da sequência. O
 * SdrResponderJob e o SdrResponderService checam este campo antes de
 * chamar a IA e cancelam se ainda dentro do prazo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->timestamp('sequencia_em_andamento_ate')
                ->nullable()
                ->after('ultima_mensagem_lead_em')
                ->comment('Se preenchido, a IA não responde antes deste timestamp (sequência em andamento)');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('sequencia_em_andamento_ate');
        });
    }
};
