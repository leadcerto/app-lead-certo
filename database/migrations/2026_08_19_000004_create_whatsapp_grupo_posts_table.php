<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de quando o bot de aquecimento postou uma reação casual em cada grupo
 * — garante o teto de no máximo 1 post por grupo por dia (postar demais levanta
 * suspeita igual quanto ficar mudo). Mensagens de outras pessoas no grupo nunca
 * passam por aqui — o webhook já ignora isGroup=true antes de qualquer
 * processamento (UazapiWebhookController), essa tabela é só do que NÓS postamos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_grupo_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_canal_id')->constrained('whatsapp_canais')->cascadeOnDelete();
            $table->string('grupo_chatid');
            $table->string('conteudo', 500);
            $table->timestamp('postado_em');

            $table->index(['whatsapp_canal_id', 'grupo_chatid', 'postado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_grupo_posts');
    }
};
