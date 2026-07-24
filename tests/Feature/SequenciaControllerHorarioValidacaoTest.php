<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaControllerHorarioValidacaoTest extends TestCase
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

    public function test_store_rejeita_janela_noturna_invertida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'           => 'Repouso invertido',
            'coluna_kanban'  => 'lead_novo',
            'horario_ativo'  => true,
            'horario_inicio' => '22:00',
            'horario_fim'    => '06:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('horario_fim');
    }

    public function test_store_rejeita_horario_inicio_igual_a_fim(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'           => 'Janela zero',
            'coluna_kanban'  => 'lead_novo',
            'horario_ativo'  => true,
            'horario_inicio' => '09:00',
            'horario_fim'    => '09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('horario_fim');
    }

    public function test_store_aceita_janela_valida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'           => 'Horário comercial',
            'coluna_kanban'  => 'lead_novo',
            'horario_ativo'  => true,
            'horario_inicio' => '08:00',
            'horario_fim'    => '18:00',
        ]);

        $response->assertStatus(201);
    }
}
