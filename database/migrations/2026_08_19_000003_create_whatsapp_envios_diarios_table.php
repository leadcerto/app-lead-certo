<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador diário de envio por canal não-oficial, separado por tipo de contato
 * (frio = nunca respondeu / quente = já respondeu antes) — é contra esse contador
 * que o AquecimentoWhatsappService valida o limite do dia antes de qualquer envio.
 * Uma linha por canal por dia, zera sozinho ao virar o dia (linha nova).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_envios_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_canal_id')->constrained('whatsapp_canais')->cascadeOnDelete();
            $table->date('data');
            $table->unsignedInteger('contador_frio')->default(0);
            $table->unsignedInteger('contador_quente')->default(0);
            $table->timestamps();

            $table->unique(['whatsapp_canal_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_envios_diarios');
    }
};
