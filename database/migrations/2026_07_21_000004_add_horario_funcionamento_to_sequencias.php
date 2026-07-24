<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencias', function (Blueprint $table) {
            $table->boolean('horario_ativo')->default(false)->after('ativo');
            $table->time('horario_inicio')->nullable()->after('horario_ativo');
            $table->time('horario_fim')->nullable()->after('horario_inicio');
            $table->foreignId('sequencia_repouso_id')->nullable()->after('horario_fim')
                ->constrained('sequencias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sequencias', function (Blueprint $table) {
            $table->dropForeign(['sequencia_repouso_id']);
            $table->dropColumn(['horario_ativo', 'horario_inicio', 'horario_fim', 'sequencia_repouso_id']);
        });
    }
};
