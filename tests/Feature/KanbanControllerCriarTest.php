<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerCriarTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_kanban_novo_sem_colunas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil de Qualificação — Imersão',
            'nome_curto' => 'Imersão',
        ]);

        $response->assertStatus(201);
        $kanban = Kanban::findOrFail($response->json('id'));
        $this->assertSame('Imersão', $kanban->nome_curto);
        $this->assertSame(0, $kanban->colunas()->count());
        $this->assertNotSame('vendas', $kanban->tipo);
    }

    public function test_nome_curto_vira_tipo_unico_mesmo_repetido(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $primeiro = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil A', 'nome_curto' => 'Funil',
        ])->assertStatus(201)->json();

        $segundo = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil B', 'nome_curto' => 'Funil',
        ])->assertStatus(201)->json();

        $this->assertNotSame(
            Kanban::findOrFail($primeiro['id'])->tipo,
            Kanban::findOrFail($segundo['id'])->tipo
        );
    }

    public function test_nome_curto_com_espaco_e_rejeitado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil', 'nome_curto' => 'Nome Com Espaço',
        ])->assertStatus(422);
    }
}
