<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do Leonardo (30/09): desativar um canal (ex.: Uazapi que não vai
 * mais ser usado, ver `_docs/PENDENCIAS.md`) sem apagar — pra poder
 * reativar depois se voltar a funcionar satisfatoriamente. Campo separado
 * de `status` (que reflete o estado real da conexão reportado pela
 * própria Uazapi/Baileys via webhook) porque uma decisão manual de
 * desativar não pode ser revertida sozinha por um evento de reconexão —
 * só reativa quando alguém limpar este campo de propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->timestamp('desativado_em')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->dropColumn('desativado_em');
        });
    }
};
