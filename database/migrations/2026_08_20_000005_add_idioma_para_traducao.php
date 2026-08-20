<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 11 do roteiro de 2026-08-20 — idioma por card com tradução
 * bidirecional real (não é só um selo estático):
 * - `tickets_atendimento.idioma_lead`: idioma detectado do lead (código
 *   ISO 639-1, ex. 'pt', 'en', 'es') — null enquanto ainda não detectado
 *   ou enquanto for português (não precisa marcar o caso comum).
 * - `mensagens.idioma`: idioma em que o campo `conteudo` está escrito de
 *   verdade (o que foi realmente enviado/recebido pelo WhatsApp).
 * - `mensagens.conteudo_pt`: tradução em português, sempre populada
 *   quando `idioma` não é 'pt' — pro atendente ler tudo em português no
 *   Kanban independente da direção da mensagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->string('idioma_lead', 5)->nullable()->after('etapa_ia');
        });

        Schema::table('mensagens', function (Blueprint $table) {
            $table->string('idioma', 5)->nullable()->after('conteudo');
            $table->text('conteudo_pt')->nullable()->after('idioma');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('idioma_lead');
        });

        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropColumn(['idioma', 'conteudo_pt']);
        });
    }
};
