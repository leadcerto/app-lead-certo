<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdr_delay_segundos era smallint unsigned (máx 65535 ~ 18h). A tela agora
 * permite escolher a unidade "hora", e um valor tipo "20 horas" (72000s)
 * estourava esse limite — mesma classe de bug do enum coluna_kanban sem
 * 'pagamento' corrigido nesta mesma sessão. Alargado pra int unsigned,
 * igual ao delay_segundos de sequencia_mensagens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->unsignedInteger('sdr_delay_segundos')->default(45)->change();
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('sdr_delay_segundos')->default(45)->change();
        });
    }
};
