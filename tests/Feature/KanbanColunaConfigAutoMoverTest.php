<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigAutoMoverTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_persiste_configuracao_de_auto_mover(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'auto_mover_ativo'          => true,
            'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos'       => 259200,
            'auto_mover_mensagem'       => 'Encerrando por falta de resposta.',
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')->first();
        $this->assertTrue($config->auto_mover_ativo);
        $this->assertSame('encerrado', $config->auto_mover_coluna_destino);
        $this->assertSame(259200, $config->auto_mover_segundos);
        $this->assertSame('Encerrando por falta de resposta.', $config->auto_mover_mensagem);
    }

    public function test_rejeita_coluna_destino_invalida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'auto_mover_coluna_destino' => 'coluna_inexistente',
        ]);

        $response->assertStatus(422);
    }

    public function test_show_retorna_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/aguardando_orcamento');

        $response->assertOk();
        $response->assertJson([
            'auto_mover_ativo'          => false,
            'auto_mover_coluna_destino' => '',
            'auto_mover_segundos'       => 259200,
            'auto_mover_mensagem'       => '',
        ]);
    }

    public function test_auto_mover_coluna_destino_aceita_coluna_customizada(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'coluna_customizada', 'label' => 'Minha Coluna',
            'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'auto_mover_ativo'          => true,
            'auto_mover_coluna_destino' => 'coluna_customizada',
            'auto_mover_segundos'       => 3600,
        ]);

        $response->assertOk();
    }
}
