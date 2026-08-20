<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redesenho 2026-08-20 (Leonardo): o cliente fala com um SETOR (cargo
 * marcado como visível), não com uma pessoa por nome — deliberadamente
 * simples na hora (não passa pela IA de atendimento), mas vira um caso
 * rastreável com análise de viabilidade depois.
 */
class FeedbackAgenteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setorSuporte(Tenant $leadCerto): array
    {
        $cargo  = Cargo::create(['nome' => 'Suporte', 'descricao' => 'x', 'ordem' => 1, 'visivel_para_clientes' => true]);
        $agente = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'dono', 'nome' => 'Adriana Aviag']);
        $agente->cargos()->attach($cargo->id);

        return [$cargo, $agente];
    }

    public function test_lista_so_setores_marcados_como_visiveis(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$visivel] = $this->setorSuporte($leadCerto);
        Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'interno', 'ordem' => 2, 'visivel_para_clientes' => false]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/equipe/suporte');

        $response->assertOk();
        $response->assertSee('Suporte');
        $response->assertDontSee('Gestor de SEO');
    }

    public function test_usuario_de_qualquer_perfil_manda_feedback_pro_setor(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor, $agente] = $this->setorSuporte($leadCerto);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/setor/{$setor->id}/conversar", [
            'mensagem' => 'O sistema está travando quando eu tento mover o card.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('feedbacks_agente', [
            'user_id'       => $agente->id,
            'cargo_id'      => $setor->id,
            'tenant_id'     => $tenant->id,
            'autor_user_id' => $user->id,
            'mensagem'      => 'O sistema está travando quando eu tento mover o card.',
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
            'status'        => 'pendente',
        ]);
    }

    public function test_nao_deixa_mandar_pra_cargo_nao_visivel(): void
    {
        $interno = Cargo::create(['nome' => 'Gestor de SEO', 'descricao' => 'x', 'ordem' => 1, 'visivel_para_clientes' => false]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get("/equipe/setor/{$interno->id}/conversar");

        $response->assertStatus(404);
    }

    public function test_ve_o_historico_so_da_propria_empresa(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        [$setor] = $this->setorSuporte($leadCerto);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        FeedbackAgente::create([
            'cargo_id' => $setor->id, 'tenant_id' => $tenantA->id,
            'mensagem' => 'Mensagem da empresa A', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);
        FeedbackAgente::create([
            'cargo_id' => $setor->id, 'tenant_id' => $tenantB->id,
            'mensagem' => 'Mensagem da empresa B', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        $response = $this->actingAs($userA)->get("/equipe/setor/{$setor->id}/conversar");

        $response->assertOk();
        $response->assertSee('Mensagem da empresa A');
        $response->assertDontSee('Mensagem da empresa B');
    }
}
