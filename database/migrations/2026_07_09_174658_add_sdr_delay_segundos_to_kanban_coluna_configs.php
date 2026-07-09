<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coluna já existia em produção (criada direto no banco, fora do fluxo de migrations).
 * Esta migration só formaliza no git para que qualquer ambiente novo (staging, restore)
 * tenha o schema completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kanban_coluna_configs', 'sdr_delay_segundos')) {
            Schema::table('kanban_coluna_configs', function (Blueprint $table) {
                $table->unsignedSmallInteger('sdr_delay_segundos')->default(45)->after('ia_ativo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kanban_coluna_configs', 'sdr_delay_segundos')) {
            Schema::table('kanban_coluna_configs', function (Blueprint $table) {
                $table->dropColumn('sdr_delay_segundos');
            });
        }
    }
};
