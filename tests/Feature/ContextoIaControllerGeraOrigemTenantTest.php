<?php

namespace Tests\Feature;

use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-22: ContextoIaController::gerar() chamava
 * OpenRouterService::chat() sem origem nem tenantId — mesmo já tendo
 * $tenantId disponível localmente. Sem isso, a chamada nunca resolve o
 * agente IA customizado do tenant e não fica rastreável em ia_usages.
 */
class ContextoIaControllerGeraOrigemTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerar_repassa_origem_e_tenant_id_pro_chat(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => \App\Models\Contato::factory()->create()->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        for ($i = 0; $i < 6; $i++) {
            Mensagem::create([
                'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'remetente' => $i % 2 === 0 ? 'lead' : 'humano',
                'tipo' => 'texto', 'conteudo' => "Mensagem de teste {$i}", 'enviado_em' => now(),
            ]);
        }

        $tenantIdRecebido = null;

        $this->mock(OpenRouterService::class, function ($mock) use (&$tenantIdRecebido) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages, $tier, $maxTokens, $origem, $tenantId) use (&$tenantIdRecebido) {
                    $tenantIdRecebido = $tenantId;
                    return $origem === 'gerar_contexto_ia';
                })
                ->andReturn('Base de conhecimento gerada.');
        });

        $response = $this->actingAs($user)->postJson('/api/painel/contexto-ia/gerar');

        $response->assertOk();
        $this->assertSame($tenant->id, $tenantIdRecebido);
    }
}
