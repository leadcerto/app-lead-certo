<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisão do Leonardo (2026-08-19): TODOS os números WhatsApp não-oficiais passam
 * por aquecimento pra sempre (não é só onboarding de número novo que depois se
 * desliga) — a rampa sobe de um limite baixo pro limite de regime (Seção 8 do
 * manual de envio) e o teto de regime nunca deixa de ser verificado.
 *
 * 'protegido' = número que não pode ser perdido (ex.: Adriana) — rampa mais lenta,
 * nunca inicia conversa por conta própria.
 * 'descartavel' = número dedicado a prospecção fria — rampa mais rápida, aceita o
 * risco maior de perder o número (tem outro pra colocar no lugar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->enum('perfil_aquecimento', ['protegido', 'descartavel'])->default('protegido')->after('app');
            $table->timestamp('aquecimento_iniciado_em')->nullable()->after('perfil_aquecimento');
        });

        // Backfill: canal já existente conta como "recém-ativado hoje" (mais seguro
        // que assumir que já está aquecido — pior caso é a rampa recomeçar de baixo).
        \Illuminate\Support\Facades\DB::table('whatsapp_canais')
            ->where('tipo', 'nao_oficial')
            ->whereNull('aquecimento_iniciado_em')
            ->update(['aquecimento_iniciado_em' => now()]);
    }

    public function down(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->dropColumn(['perfil_aquecimento', 'aquecimento_iniciado_em']);
        });
    }
};
