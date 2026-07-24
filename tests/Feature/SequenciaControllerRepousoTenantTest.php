<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaControllerRepousoTenantTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);
    }

    public function test_store_rejeita_sequencia_repouso_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);

        $repousoDeB = Sequencia::create([
            'tenant_id' => $tenantB->id, 'nome' => 'Repouso B', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);

        $response = $this->actingAs($userA)->postJson('/api/painel/sequencias', [
            'nome'                 => 'Sequência A',
            'coluna_kanban'        => 'lead_novo',
            'sequencia_repouso_id' => $repousoDeB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sequencia_repouso_id');
    }

    public function test_store_aceita_sequencia_repouso_do_mesmo_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);

        $repousoDeA = Sequencia::create([
            'tenant_id' => $tenantA->id, 'nome' => 'Repouso A', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);

        $response = $this->actingAs($userA)->postJson('/api/painel/sequencias', [
            'nome'                 => 'Sequência A',
            'coluna_kanban'        => 'lead_novo',
            'sequencia_repouso_id' => $repousoDeA->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_update_rejeita_sequencia_repouso_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);

        $sequenciaA = Sequencia::create([
            'tenant_id' => $tenantA->id, 'nome' => 'Sequência A', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        $repousoDeB = Sequencia::create([
            'tenant_id' => $tenantB->id, 'nome' => 'Repouso B', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);

        $response = $this->actingAs($userA)->putJson("/api/painel/sequencias/{$sequenciaA->id}", [
            'sequencia_repouso_id' => $repousoDeB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sequencia_repouso_id');
    }

    public function test_update_rejeita_sequencia_repouso_igual_a_si_mesma(): void
    {
        $tenantA = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);

        $sequenciaA = Sequencia::create([
            'tenant_id' => $tenantA->id, 'nome' => 'Sequência A', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);

        $response = $this->actingAs($userA)->putJson("/api/painel/sequencias/{$sequenciaA->id}", [
            'sequencia_repouso_id' => $sequenciaA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sequencia_repouso_id');
    }
}
