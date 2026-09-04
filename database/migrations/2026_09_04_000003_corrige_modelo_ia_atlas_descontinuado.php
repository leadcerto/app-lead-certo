<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A migration 2026_09_03_000004 criou o agente "Atlas" com o modelo
 * 'anthropic/claude-3.5-sonnet', que a OpenRouter já removeu do catálogo
 * (retorna 404 "No endpoints found"). Isso derrubava o SdrResponder a cada
 * poucos minutos para qualquer ticket atribuído a esse agente. Atualiza
 * qualquer usuário/agente que ainda esteja com o modelo descontinuado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('openrouter_modelo', 'anthropic/claude-3.5-sonnet')
            ->update(['openrouter_modelo' => 'anthropic/claude-sonnet-5']);
    }

    public function down(): void
    {
        // Intencionalmente sem rollback: não há motivo para voltar a um modelo descontinuado.
    }
};
