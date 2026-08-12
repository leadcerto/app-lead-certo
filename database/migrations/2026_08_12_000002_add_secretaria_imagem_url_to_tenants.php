<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Imagem opcional anexada à mensagem de abertura da Secretária Eletrônica
            // (pedido do Leonardo, 2026-08-12). URL pública em storage/public, mesmo
            // padrão de KanbanController::enviarMidia().
            $table->string('secretaria_mensagem_inicial_imagem_url', 500)->nullable()->after('secretaria_mensagem_inicial');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('secretaria_mensagem_inicial_imagem_url');
        });
    }
};
