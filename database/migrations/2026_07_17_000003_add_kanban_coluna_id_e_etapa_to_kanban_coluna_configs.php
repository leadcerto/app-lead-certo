<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->foreignId('kanban_coluna_id')->nullable()->after('coluna_kanban')
                ->constrained('kanban_colunas')->nullOnDelete();
            $table->string('etapa_ia_ao_mover', 20)->default('etapa_1')->after('ia_contexto');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_coluna_id');
            $table->dropColumn('etapa_ia_ao_mover');
        });
    }
};
