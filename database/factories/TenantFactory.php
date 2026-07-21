<?php

namespace Database\Factories;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'nome'  => $this->faker->company(),
            'nicho' => 'frete',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            $kanban = Kanban::create([
                'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
            ]);

            foreach (self::colunasPadrao() as $def) {
                KanbanColuna::create([
                    'tenant_id' => $tenant->id,
                    'kanban_id' => $kanban->id,
                    'chave'     => $def['chave'],
                    'label'     => $def['label'],
                    'emoji'     => $def['emoji'],
                    'papel'     => $def['papel'],
                    'ordem'     => $def['ordem'],
                ]);
            }
        });
    }

    /** @return array<int, array{chave: string, label: string, emoji: string, papel: PapelColunaKanban, ordem: int}> */
    public static function colunasPadrao(): array
    {
        return [
            ['chave' => 'lead_novo',            'label' => 'Novo',                 'emoji' => '🟢', 'papel' => PapelColunaKanban::Entrada,            'ordem' => 1],
            ['chave' => 'em_atendimento',       'label' => 'Em Atendimento',       'emoji' => '🔵', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 2],
            ['chave' => 'aguardando_orcamento', 'label' => 'Aguardando Orçamento', 'emoji' => '🟡', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 3],
            ['chave' => 'aguardando_lead',      'label' => 'Aguardando Lead',      'emoji' => '🟠', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 4],
            ['chave' => 'pagamento',            'label' => 'Pagamento',            'emoji' => '💳', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 5],
            ['chave' => 'servico_agendado',     'label' => 'Serviço Agendado',     'emoji' => '📅', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 6],
            ['chave' => 'encerrado',            'label' => 'Encerrado',            'emoji' => '⚫', 'papel' => PapelColunaKanban::Encerramento,        'ordem' => 7],
            ['chave' => 'outros',               'label' => 'Outros / Internos',    'emoji' => '👤', 'papel' => PapelColunaKanban::TransferenciaHumana, 'ordem' => 8],
        ];
    }
}
