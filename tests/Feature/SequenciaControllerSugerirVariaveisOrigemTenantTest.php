<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-22: SequenciaController::sugerirVariaveis() chamava
 * OpenRouterService::chat() sem origem nem tenantId — mesmo já tendo
 * $sequencia->tenant_id disponível localmente. Sem isso, a chamada nunca
 * resolve o agente IA customizado do tenant e não fica rastreável em
 * ia_usages.
 */
class SequenciaControllerSugerirVariaveisOrigemTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_sugerir_variaveis_repassa_origem_e_tenant_id_pro_chat(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas',
            'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá! Tudo bem?', 'delay_segundos' => 0,
            'ativo' => true, 'obrigatorio' => true,
        ]);

        $tenantIdRecebido = null;

        $this->mock(OpenRouterService::class, function ($mock) use (&$tenantIdRecebido) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages, $tier, $maxTokens, $origem, $tenantId) use (&$tenantIdRecebido) {
                    $tenantIdRecebido = $tenantId;
                    return $origem === 'sugerir_variaveis_sequencia';
                })
                ->andReturn('[]');
        });

        $response = $this->actingAs($user)->postJson("/api/painel/sequencias/{$sequencia->id}/sugerir-variaveis");

        $response->assertOk();
        $this->assertSame($tenant->id, $tenantIdRecebido);
    }
}
