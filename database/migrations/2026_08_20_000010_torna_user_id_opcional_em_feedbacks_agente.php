<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesenho por setor (2026-08-20): o cliente escolhe um SETOR (cargo_id),
 * não uma pessoa — `user_id` (quem responde de verdade hoje) passa a ser
 * derivado de quem ocupa o cargo, e pode ficar nulo se o setor estiver
 * marcado como visível mas dormente (sem ninguém ocupando ainda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
