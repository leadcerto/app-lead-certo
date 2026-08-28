<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 5. Generaliza google_given_name/nome_sugerido/auditoria_pendente (que
 * cobriam só o campo nome) pra os 4 campos sincronizados. As colunas antigas
 * saem numa migration separada, ao final do plano (Task 9), só depois que
 * todo call site tiver migrado pras novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->json('google_valores_enviados')->nullable()->after('google_etag');
            $table->json('campos_editados_humano')->nullable()->after('google_valores_enviados');
            $table->json('campos_pendentes_auditoria')->nullable()->after('campos_editados_humano');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['google_valores_enviados', 'campos_editados_humano', 'campos_pendentes_auditoria']);
        });
    }
};
