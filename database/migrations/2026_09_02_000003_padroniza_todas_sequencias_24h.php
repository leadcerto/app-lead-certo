<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Padroniza todas as sequências para 24 horas por padrão (horario_ativo = false),
     * permitindo que cada coluna/sequência receba horários específicos apenas quando
     * o usuário configurar explicitamente.
     */
    public function up(): void
    {
        DB::table('sequencias')
            ->where('horario_ativo', true)
            ->whereNull('sequencia_repouso_id')
            ->update([
                'horario_ativo' => false,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
