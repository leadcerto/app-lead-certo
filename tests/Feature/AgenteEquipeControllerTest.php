<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\ServicoExecutado;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página de perfil do agente, pedida pelo Leonardo em 2026-08-20 —
 * identidade, cargos (um agente pode ter vários) e serviços executados.
 */
class AgenteEquipeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['tenant_id' => null, 'perfil' => 'admin', 'ativo' => true]);
    }

    private function agenteLeadCerto(Tenant $leadCerto): User
    {
        return User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'diretor_marketing', 'nome' => 'Nathanel Fernandes']);
    }

    public function test_admin_ve_a_lista_de_agentes_com_cargos(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);
        $cargo     = Cargo::create(['nome' => 'Diretora de Marketing', 'descricao' => 'x', 'ordem' => 1]);
        $agente->cargos()->attach($cargo->id);

        $response = $this->actingAs($this->admin())->get('/admin/equipe');

        $response->assertOk();
        $response->assertSee('Nathanel Fernandes');
        $response->assertSee('Diretora de Marketing');
    }

    public function test_perfil_normal_nao_acessa_a_equipe(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/admin/equipe');

        $response->assertStatus(403);
    }

    public function test_admin_vincula_agente_a_varios_cargos_ao_mesmo_tempo(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);
        $cargo1    = Cargo::create(['nome' => 'Diretora de Marketing', 'descricao' => 'x', 'ordem' => 1]);
        $cargo2    = Cargo::create(['nome' => 'Gestor Comercial', 'descricao' => 'y', 'ordem' => 2]);

        $response = $this->actingAs($this->admin())->post("/admin/equipe/{$agente->id}/cargos", [
            'cargo_ids' => [$cargo1->id, $cargo2->id],
        ]);

        $response->assertRedirect();
        $this->assertCount(2, $agente->fresh()->cargos);
    }

    public function test_admin_registra_servico_executado(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);

        $response = $this->actingAs($this->admin())->post("/admin/equipe/{$agente->id}/servicos", [
            'descricao'           => 'Analisou o ticket do Hugo Alonso e identificou rejeição de área alucinada',
            'motivo'              => 'Venda perdida por erro do agente',
            'grau_dificuldade'    => 'alto',
            'tempo_gasto_minutos' => 45,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('servicos_executados', [
            'user_id' => $agente->id,
            'grau_dificuldade' => 'alto',
            'tempo_gasto_minutos' => 45,
        ]);
    }

    public function test_admin_cria_cargo_novo_na_estrutura(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/equipe/cargos', [
            'nome'      => 'Gestor de SEO',
            'descricao' => 'Tráfego orgânico via busca.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cargos', ['nome' => 'Gestor de SEO']);
    }

    public function test_pagina_do_agente_mostra_resumo_de_servicos(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);
        ServicoExecutado::create([
            'user_id' => $agente->id, 'descricao' => 'Teste', 'grau_dificuldade' => 'baixo',
            'executado_em' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get("/admin/equipe/{$agente->id}");

        $response->assertOk();
        $response->assertSee('Teste');
    }
}
