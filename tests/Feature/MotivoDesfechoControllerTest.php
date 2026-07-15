<?php

namespace Tests\Feature;

use App\Models\MotivoDesfecho;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotivoDesfechoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_apenas_os_motivos_do_proprio_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        MotivoDesfecho::create(['tenant_id' => $tenantA->id, 'chave' => 'venda_fechada', 'label' => 'Venda fechada', 'e_venda' => true, 'ordem' => 1]);
        MotivoDesfecho::create(['tenant_id' => $tenantB->id, 'chave' => 'outro_motivo', 'label' => 'Motivo do outro tenant', 'ordem' => 1]);

        $user = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/motivos-desfecho');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['label' => 'Venda fechada']);
    }

    public function test_dono_cria_um_motivo_novo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Cliente mudou de cidade',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('motivos_desfecho', [
            'tenant_id' => $tenant->id,
            'chave'     => 'cliente_mudou_de_cidade',
            'label'     => 'Cliente mudou de cidade',
            'e_venda'   => false,
        ]);
    }

    public function test_nao_deixa_criar_motivo_duplicado_no_mesmo_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        MotivoDesfecho::create(['tenant_id' => $tenant->id, 'chave' => 'preco_alto', 'label' => 'Preço alto', 'ordem' => 1]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Preço alto',
        ]);

        $response->assertStatus(422);
    }

    public function test_dono_edita_um_motivo(): void
    {
        $tenant  = Tenant::factory()->create();
        $motivo  = MotivoDesfecho::create(['tenant_id' => $tenant->id, 'chave' => 'outro', 'label' => 'Outro', 'ordem' => 1]);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/motivos-desfecho/{$motivo->id}", [
            'label'   => 'Não atendemos essa região',
            'e_venda' => false,
        ]);

        $response->assertOk();
        $this->assertSame('Não atendemos essa região', $motivo->fresh()->label);
    }

    public function test_dono_exclui_um_motivo(): void
    {
        $tenant = Tenant::factory()->create();
        $motivo = MotivoDesfecho::create(['tenant_id' => $tenant->id, 'chave' => 'outro', 'label' => 'Outro', 'ordem' => 1]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/motivos-desfecho/{$motivo->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('motivos_desfecho', ['id' => $motivo->id]);
    }

    public function test_nao_deixa_editar_motivo_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $motivoB = MotivoDesfecho::create(['tenant_id' => $tenantB->id, 'chave' => 'outro', 'label' => 'Outro', 'ordem' => 1]);
        $user    = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/motivos-desfecho/{$motivoB->id}", [
            'label' => 'Tentativa de invasão',
        ]);

        $response->assertStatus(404);
    }

    public function test_vendedor_consegue_ver_a_lista_mas_nao_gerenciar(): void
    {
        $tenant = Tenant::factory()->create();
        MotivoDesfecho::create(['tenant_id' => $tenant->id, 'chave' => 'outro', 'label' => 'Outro', 'ordem' => 1]);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($vendedor)->getJson('/api/painel/kanban/motivos-desfecho')->assertOk();
        $this->actingAs($vendedor)->postJson('/api/painel/kanban/motivos-desfecho', ['label' => 'Novo'])->assertStatus(403);
    }
}
