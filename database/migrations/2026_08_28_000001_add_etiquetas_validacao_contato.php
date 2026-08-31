<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $agora = now();

        DB::table('etiquetas')->insertOrIgnore([
            ['tenant_id' => null, 'nome' => 'Novos Leads',     'slug' => 'novos_leads',      'cor' => '#3B82F6', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Leads em Análise','slug' => 'leads_em_analise', 'cor' => '#F59E0B', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Lead Certo',      'slug' => 'lead_certo',       'cor' => '#10B981', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Lead Inválido',   'slug' => 'lead_invalido',    'cor' => '#EF4444', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        DB::table('etiquetas')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'])
            ->delete();
    }
};
