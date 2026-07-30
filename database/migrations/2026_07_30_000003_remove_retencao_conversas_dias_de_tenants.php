<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Substituído por kanban_coluna_configs.exclusao_definitiva_dias (por
        // coluna) — a migration anterior já copiou o valor de cada tenant pra
        // lá antes de remover este campo.
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('retencao_conversas_dias');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('retencao_conversas_dias')->nullable();
        });
    }
};
