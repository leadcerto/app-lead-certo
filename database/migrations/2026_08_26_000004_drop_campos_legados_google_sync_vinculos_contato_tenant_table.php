<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última task do plano de sync bidirecional Google Contatos — roda só
 * depois de todo call site migrado pra campos_pendentes_auditoria/
 * campos_editados_humano/google_valores_enviados (Tasks 1-8). Ver checagem
 * de pré-condição na Task 9 do plano
 * (docs/superpowers/plans/2026-08-26-sync-bidirecional-google-contatos.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['google_given_name', 'nome_sugerido', 'auditoria_pendente']);
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->string('google_given_name', 200)->nullable();
            $table->string('nome_sugerido', 200)->nullable();
            $table->boolean('auditoria_pendente')->default(false);
        });
    }
};
