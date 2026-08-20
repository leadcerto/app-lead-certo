<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesenho visual pedido pelo Leonardo 2026-08-20: bloco por agente com
 * foto + composer estilo WhatsApp interno (texto, imagem, arquivo, áudio
 * transcrito) + descrição amigável (não técnica) do que o agente faz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            // Texto em primeira pessoa, pro cliente ler ("Vou te ajudar..."),
            // separado de `descricao` (que continua técnica, uso interno).
            $table->text('descricao_cliente')->nullable()->after('descricao');
        });

        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->string('midia_url', 500)->nullable()->after('mensagem');
            $table->enum('tipo_midia', ['imagem', 'audio', 'arquivo'])->nullable()->after('midia_url');
        });
    }

    public function down(): void
    {
        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->dropColumn(['midia_url', 'tipo_midia']);
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn('descricao_cliente');
        });
    }
};
