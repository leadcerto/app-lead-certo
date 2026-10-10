<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado na revisão final de 09/10/2026: a varredura original de
 * "visualização aberta / edição restrita a admin" não cobriu Sequências,
 * uma sub-tela dentro de Configurações do Kanban.
 */
class SequenciaPermissaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(Tenant $tenant, string $perfil): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => $perfil,
            'ativo'     => true,
        ]);
    }

    private function criarSequencia(Tenant $tenant): Sequencia
    {
        return Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
    }

    public function test_vendedor_visualiza_sequencias_e_mensagens(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuario($tenant, 'vendedor');
        $sequencia = $this->criarSequencia($tenant);

        $this->actingAs($user)->getJson('/api/painel/sequencias')->assertOk();
        $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequencia->id}/mensagens")->assertOk();
    }

    public function test_dono_nao_edita_mais_sequencias_nem_mensagens(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuario($tenant, 'dono');
        $sequencia = $this->criarSequencia($tenant);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome' => 'Nova', 'coluna_kanban' => 'lead_novo',
        ])->assertStatus(403);

        $this->actingAs($user)->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá!', 'delay_segundos' => 0,
        ])->assertStatus(403);

        $this->actingAs($user)->putJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}", [
            'conteudo' => 'Atualizado', 'delay_segundos' => 0,
        ])->assertStatus(403);

        $this->actingAs($user)->deleteJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}")
            ->assertStatus(403);
    }

    public function test_admin_continua_editando_sequencias_e_mensagens(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuario($tenant, 'admin');
        $sequencia = $this->criarSequencia($tenant);

        $this->actingAs($user)->postJson('/api/painel/sequencias', [
            'nome' => 'Nova', 'coluna_kanban' => 'lead_novo',
        ])->assertStatus(201);

        $this->actingAs($user)->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá!', 'delay_segundos' => 0,
        ])->assertCreated();
    }

    public function test_perfil_sem_acesso_a_kanban_continua_barrado(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuario($tenant, 'auditor');
        $sequencia = $this->criarSequencia($tenant);

        $this->actingAs($user)->getJson('/api/painel/sequencias')->assertStatus(403);
        $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequencia->id}/mensagens")->assertStatus(403);
    }
}
