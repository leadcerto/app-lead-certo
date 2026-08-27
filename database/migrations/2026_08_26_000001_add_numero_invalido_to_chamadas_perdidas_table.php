<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do Leonardo (2026-08-26): números de spam/telemarketing que ligam pra
 * Secretária Eletrônica não têm WhatsApp — o sistema mandava a mensagem assim
 * mesmo e marcava `mensagem_enviada = true`, deixando o ticket esperando resposta
 * de um número que nunca vai receber nada. Ver
 * CovercutChannelService::ultimoEnvioFalhouPorNumeroInvalido().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chamadas_perdidas', function (Blueprint $table) {
            $table->boolean('numero_invalido')->default(false)->after('mensagem_enviada_em');
        });
    }

    public function down(): void
    {
        Schema::table('chamadas_perdidas', function (Blueprint $table) {
            $table->dropColumn('numero_invalido');
        });
    }
};
