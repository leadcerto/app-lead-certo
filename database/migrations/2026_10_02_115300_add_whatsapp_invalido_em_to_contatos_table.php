<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achado de 02/10 (Leonardo): a Meta confirma de forma assíncrona (webhook
 * de status, código de erro 131026) quando um número não tem WhatsApp — mas
 * nada guardava esse fato. Sem um registro permanente e global (vale pra
 * qualquer tenant, é uma propriedade do número, não da relação com uma
 * empresa específica), o mesmo número de telemarketing que liga de novo
 * repete o ciclo inteiro (cria ticket, tenta mandar mensagem, espera a Meta
 * confirmar de novo) a cada chamada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->timestamp('whatsapp_invalido_em')->nullable()->after('bloqueado');
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn('whatsapp_invalido_em');
        });
    }
};
