<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigFollowupEstagiosTest extends TestCase
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

    public function test_show_retorna_defaults_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');

        $response->assertOk();
        $response->assertJson([
            'followup_estagio1_segundos' => 3600,
            'followup_estagio2_segundos' => 7200,
            'followup_estagio3_segundos' => 21600,
        ]);
    }

    public function test_update_persiste_os_3_thresholds_customizados(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'followup_estagio1_segundos' => 1800,
            'followup_estagio2_segundos' => 3600,
            'followup_estagio3_segundos' => 10800,
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'lead_novo')->first();
        $this->assertSame(1800, $config->followup_estagio1_segundos);
        $this->assertSame(3600, $config->followup_estagio2_segundos);
        $this->assertSame(10800, $config->followup_estagio3_segundos);
    }

    public function test_rejeita_valor_abaixo_do_minimo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'followup_estagio1_segundos' => 10,
        ]);

        $response->assertStatus(422);
    }
}
