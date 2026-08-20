<?php

namespace Tests\Feature;

use App\Models\FeedbackAgente;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do Leonardo 2026-08-20: campo onde a empresa logada fala direto
 * com um agente — deliberadamente simples, resposta padrão sempre igual,
 * não passa pela IA de atendimento.
 */
class FeedbackAgenteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_de_qualquer_perfil_manda_feedback_pro_agente(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'dono', 'nome' => 'Adriana Aviag']);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->post("/equipe/{$agente->id}/conversar", [
            'mensagem' => 'O sistema está travando quando eu tento mover o card.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('feedbacks_agente', [
            'user_id'       => $agente->id,
            'tenant_id'     => $tenant->id,
            'autor_user_id' => $user->id,
            'mensagem'      => 'O sistema está travando quando eu tento mover o card.',
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
        ]);
    }

    public function test_ve_o_historico_so_da_propria_empresa(): void
    {
        $leadCerto = Tenant::factory()->create(['id' => 2]);
        $agente    = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'dono']);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        FeedbackAgente::create([
            'user_id' => $agente->id, 'tenant_id' => $tenantA->id,
            'mensagem' => 'Mensagem da empresa A', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);
        FeedbackAgente::create([
            'user_id' => $agente->id, 'tenant_id' => $tenantB->id,
            'mensagem' => 'Mensagem da empresa B', 'resposta' => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        $response = $this->actingAs($userA)->get("/equipe/{$agente->id}/conversar");

        $response->assertOk();
        $response->assertSee('Mensagem da empresa A');
        $response->assertDontSee('Mensagem da empresa B');
    }
}
