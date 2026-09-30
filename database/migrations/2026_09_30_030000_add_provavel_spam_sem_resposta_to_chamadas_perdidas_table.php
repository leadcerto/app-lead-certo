<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pendência registrada em 22/09 (Leonardo): `numero_invalido` já cobre
 * spam/telemarketing que liga de um número sem WhatsApp (Caso A). Falta o
 * Caso B — número TEM WhatsApp, mensagem foi enviada, mas o lead nunca
 * respondeu (padrão típico de quem liga sem intenção real de falar com a
 * empresa). Ver MarcarChamadasSpamSemRespostaCommand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chamadas_perdidas', function (Blueprint $table) {
            $table->boolean('provavel_spam_sem_resposta')->default(false)->after('numero_invalido');
        });
    }

    public function down(): void
    {
        Schema::table('chamadas_perdidas', function (Blueprint $table) {
            $table->dropColumn('provavel_spam_sem_resposta');
        });
    }
};
