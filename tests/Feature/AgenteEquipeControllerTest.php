<?php

namespace Tests\Feature;

use App\Models\AcessoAgente;
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

    /**
     * Sugestão 2 (2026-08-20): bloco de acessos concedidos — nunca guarda
     * senha, só o identificador.
     */
    public function test_admin_registra_acesso_sem_guardar_senha(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);

        $response = $this->actingAs($this->admin())->post("/admin/equipe/{$agente->id}/acessos", [
            'servico'       => 'Gmail',
            'identificador' => 'nathanelllfernandees@gmail.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('acessos_agente', [
            'user_id' => $agente->id, 'servico' => 'Gmail', 'identificador' => 'nathanelllfernandees@gmail.com', 'ativo' => true,
        ]);
    }

    public function test_admin_desativa_e_reativa_um_acesso(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);
        $acesso    = AcessoAgente::create(['user_id' => $agente->id, 'servico' => 'Gmail', 'identificador' => 'x@x.com', 'ativo' => true]);

        $this->actingAs($this->admin())->post("/admin/equipe/{$agente->id}/acessos/{$acesso->id}/toggle");
        $this->assertFalse($acesso->fresh()->ativo);

        $this->actingAs($this->admin())->post("/admin/equipe/{$agente->id}/acessos/{$acesso->id}/toggle");
        $this->assertTrue($acesso->fresh()->ativo);
    }

    /**
     * Sugestão 3/5 (2026-08-20): cargo sem ninguém ocupando aparece com
     * selo "Dormente", e cargo com cargo_pai aparece recuado embaixo dele.
     */
    public function test_cargo_sem_agente_mostra_selo_dormente(): void
    {
        Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'x', 'ordem' => 1]);

        $response = $this->actingAs($this->admin())->get('/admin/equipe/cargos');

        $response->assertOk();
        $response->assertSee('Dormente');
    }

    public function test_cargo_com_pai_aparece_junto_do_pai(): void
    {
        $pai   = Cargo::create(['nome' => 'Diretora de Marketing', 'descricao' => 'x', 'ordem' => 1]);
        $filho = Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'y', 'ordem' => 2, 'cargo_pai_id' => $pai->id]);

        $response = $this->actingAs($this->admin())->get('/admin/equipe/cargos');

        $response->assertOk();
        $response->assertSeeInOrder(['Diretora de Marketing', 'Gestor de SEO']);
    }

    /**
     * Redesenho 2026-08-20: cargo marcado como visível aparece na tela de
     * Suporte pro cliente escolher — admin liga/desliga isso.
     */
    public function test_admin_alterna_visibilidade_do_cargo_pro_cliente(): void
    {
        $cargo = Cargo::create(['nome' => 'Suporte', 'descricao' => 'x', 'ordem' => 1, 'visivel_para_clientes' => false]);

        $this->actingAs($this->admin())->post("/admin/equipe/cargos/{$cargo->id}/toggle-visivel");
        $this->assertTrue($cargo->fresh()->visivel_para_clientes);

        $this->actingAs($this->admin())->post("/admin/equipe/cargos/{$cargo->id}/toggle-visivel");
        $this->assertFalse($cargo->fresh()->visivel_para_clientes);
    }

    public function test_admin_registra_analise_de_viabilidade_do_feedback(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = $this->agenteLeadCerto($leadCerto);
        $cargo     = Cargo::create(['nome' => 'Suporte', 'descricao' => 'x', 'ordem' => 1, 'visivel_para_clientes' => true]);
        $feedback  = \App\Models\FeedbackAgente::create([
            'user_id' => $agente->id, 'cargo_id' => $cargo->id, 'tenant_id' => Tenant::factory()->create()->id,
            'mensagem' => 'Gostaria de poder exportar relatório em PDF', 'resposta' => 'x',
        ]);

        $response = $this->actingAs($this->admin())->post("/admin/equipe/feedback/{$feedback->id}/analise", [
            'status'                          => 'concluido',
            'relatorio_analise'               => 'Faz sentido, vários clientes pediram algo parecido.',
            'implementacao_faz_sentido'       => '1',
            'tempo_estimado_execucao'         => '2 semanas',
            'empresas_beneficiadas_estimado'  => 8,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('feedbacks_agente', [
            'id' => $feedback->id, 'status' => 'concluido',
            'implementacao_faz_sentido' => true, 'empresas_beneficiadas_estimado' => 8,
        ]);
    }
}
