<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do Leonardo (30/09): separar dois papéis pra canal Messenger próprio
 * — número compartilhado com a plataforma oficial (ex.: mesmo número da
 * Covercut) só pode entrar em grupo pra extrair participante, nunca enviar
 * mensagem de verdade; número dedicado, sem vínculo com oficial nem outra
 * empresa, é que manda mensagem real de prospecção. Achado de segurança que
 * motivou isso: WhatsappCanalController::store() vincula todo canal novo a
 * todos os Kanbans do tenant automaticamente, deixando um canal de extração
 * elegível a ser sorteado pra envio sem essa trava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->boolean('somente_extracao')->default(false)->after('desativado_em');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->dropColumn('somente_extracao');
        });
    }
};
