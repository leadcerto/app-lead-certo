<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do Leonardo (2026-08-28, depois do incidente de mensagens fora do
 * horário comercial): toda sequência nova nasce restrita a 11h-13h, sem
 * precisar configurar manualmente. Quem mandar os campos explicitamente
 * continua no controle.
 */
class SequenciaControllerHorarioPadraoTest extends TestCase
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

    public function test_store_sem_horario_nasce_com_janela_11_13_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'          => 'Boas-vindas',
            'coluna_kanban' => 'lead_novo',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'horario_ativo'  => true,
            'horario_inicio' => '11:00',
            'horario_fim'    => '13:00',
        ]);
    }

    public function test_store_com_horario_ativo_false_explicito_respeita_a_escolha(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'          => 'Sem restrição',
            'coluna_kanban' => 'lead_novo',
            'horario_ativo' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['horario_ativo' => false]);
        $this->assertDatabaseHas('sequencias', [
            'nome'           => 'Sem restrição',
            'horario_ativo'  => false,
            'horario_inicio' => null,
            'horario_fim'    => null,
        ]);
    }

    public function test_store_com_janela_explicita_diferente_nao_e_sobrescrita_pelo_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome'           => 'Horário estendido',
            'coluna_kanban'  => 'pagamento',
            'horario_ativo'  => true,
            'horario_inicio' => '09:00',
            'horario_fim'    => '20:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'horario_ativo'  => true,
            'horario_inicio' => '09:00',
            'horario_fim'    => '20:00',
        ]);
    }
}
