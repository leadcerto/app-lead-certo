<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achado real (2026-08-19, Leonardo): o sistema hoje só distingue oficial/não-oficial
 * + qual empresa (provider) — não existe campo dizendo se o app físico por trás da
 * conexão é o WhatsApp Business ou o WhatsApp Messenger comum. Isso gerava confusão
 * real na tela de Configurações (os dois não-oficiais apareciam juntos no mesmo bloco
 * genérico "WhatsApp Não-Oficial"), com risco de escanear o QR code errado no app errado.
 *
 * A API Oficial da Meta só existe para números WhatsApp Business — por isso o default
 * aqui é 'business' (cobre o backfill do canal Covercut existente sem ambiguidade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->enum('app', ['business', 'messenger'])->default('business')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_canais', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
