<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido do Leonardo (29/09): grupos/comunidades do WhatsApp que o
     * contato tem em comum com a gente — vai servir de quebra-gelo numa
     * mensagem de prospecção fria futura ("vi que você também está no
     * grupo X"). Guarda o jid (identificador estável do grupo, nunca muda)
     * e o nome do grupo NO MOMENTO da extração (o nome pode ser editado
     * depois, então não serve pra identificar o grupo de forma confiável —
     * só pra escrever a mensagem).
     */
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->json('grupos_whatsapp_em_comum')->nullable()->after('campos_pendentes_auditoria');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn('grupos_whatsapp_em_comum');
        });
    }
};
