<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Transferência automática de coluna por silêncio prolongado —
            // independente dos estágios de mensagem (followup_estagio*_segundos).
            $table->boolean('auto_mover_ativo')->default(false)->after('followup_estagio3_segundos');
            $table->string('auto_mover_coluna_destino')->nullable()->after('auto_mover_ativo');
            $table->unsignedInteger('auto_mover_segundos')->nullable()->after('auto_mover_coluna_destino');
            $table->text('auto_mover_mensagem')->nullable()->after('auto_mover_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['auto_mover_ativo', 'auto_mover_coluna_destino', 'auto_mover_segundos', 'auto_mover_mensagem']);
        });
    }
};
