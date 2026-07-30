<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Substitui tenants.retencao_conversas_dias (prazo único, global): a exclusão
 * definitiva agora é configurável por coluna, junto do resto (auto-mover,
 * estágios de silêncio) — pedido do Leonardo em 2026-07-30 pra ter flexibilidade
 * por tipo de encerramento (ex: venda fechada pode reter mais tempo que
 * "sem interesse").
 */
class KanbanColunaConfigExclusaoDefinitivaTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_persiste_configuracao_de_exclusao_definitiva(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/encerrado', [
            'exclusao_definitiva_ativo' => true,
            'exclusao_definitiva_dias'  => 60,
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'encerrado')->first();
        $this->assertTrue($config->exclusao_definitiva_ativo);
        $this->assertSame(60, $config->exclusao_definitiva_dias);
    }

    public function test_show_retorna_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/encerrado');

        $response->assertOk();
        $response->assertJson([
            'exclusao_definitiva_ativo' => false,
            'exclusao_definitiva_dias'  => 90,
        ]);
    }

    public function test_desativar_exclusao_definitiva_persiste_false(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 60,
        ]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/encerrado', [
            'exclusao_definitiva_ativo' => false,
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'encerrado')->first();
        $this->assertFalse($config->exclusao_definitiva_ativo);
        // Dias permanece guardado (só desativa) — se reativar depois, não perde o valor.
        $this->assertSame(60, $config->exclusao_definitiva_dias);
    }

    public function test_rejeita_dias_fora_do_intervalo_permitido(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/encerrado', [
            'exclusao_definitiva_dias' => 0,
        ]);

        $response->assertStatus(422);
    }
}
