<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fase 5 do plano do canal WhatsApp Messenger próprio (23/09) — as 3
        // regras da Seção 8 do manual de envio que ainda não tinham código:
        // máximo 10 frios em sequência sem pausa, mínimo 30s entre conversas
        // novas frias, pausa de 2h após 50 frias no dia (ver
        // AquecimentoWhatsappService::respeitaRegrasDeIntervaloParaFrio()).
        Schema::table('whatsapp_envios_diarios', function (Blueprint $table) {
            $table->unsignedInteger('sequencia_frios_atual')->default(0)->after('contador_quente');
            $table->timestamp('ultima_conversa_fria_em')->nullable()->after('sequencia_frios_atual');
            $table->timestamp('frio_50_atingido_em')->nullable()->after('ultima_conversa_fria_em');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_envios_diarios', function (Blueprint $table) {
            $table->dropColumn(['sequencia_frios_atual', 'ultima_conversa_fria_em', 'frio_50_atingido_em']);
        });
    }
};
