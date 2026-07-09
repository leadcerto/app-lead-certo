<?php

namespace Database\Seeders;

use App\Models\Etiqueta;
use Illuminate\Database\Seeder;

class EtiquetaSeeder extends Seeder
{
    public function run(): void
    {
        // Etiquetas do sistema (tenant_id = null) — valem para todos os franqueados.
        // Tenants podem criar etiquetas próprias (tenant_id = X) para sub-categorias
        // específicas do nicho (ex: "Baú 6m", "Ajudante", "PCR Montagem").
        $sistema = [
            ['slug' => 'lead',         'nome' => 'Lead',         'cor' => '#3B82F6'], // azul
            ['slug' => 'cliente',      'nome' => 'Cliente',      'cor' => '#10B981'], // verde
            ['slug' => 'fornecedor',   'nome' => 'Fornecedor',   'cor' => '#8B5CF6'], // roxo
            ['slug' => 'parceiro',     'nome' => 'Parceiro',     'cor' => '#F97316'], // laranja
            ['slug' => 'colaborador',  'nome' => 'Colaborador',  'cor' => '#EC4899'], // rosa
            ['slug' => 'pessoal',      'nome' => 'Pessoal',      'cor' => '#06B6D4'], // ciano
            ['slug' => 'sem_nome',     'nome' => 'Sem Nome',     'cor' => '#F59E0B'], // âmbar
            ['slug' => 'inativo',      'nome' => 'Inativo',      'cor' => '#6B7280'], // cinza
            ['slug' => 'bloqueado',    'nome' => 'Bloqueado',    'cor' => '#EF4444'], // vermelho
        ];

        foreach ($sistema as $dados) {
            Etiqueta::updateOrCreate(
                ['tenant_id' => null, 'slug' => $dados['slug']],
                ['nome' => $dados['nome'], 'cor' => $dados['cor'], 'ativo' => true]
            );
        }
    }
}
