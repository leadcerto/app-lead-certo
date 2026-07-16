<?php

namespace Tests\Feature;

use App\Models\IaUsage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IaUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agrupa_uso_por_dia_modelo_e_tier(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $hoje = now()->startOfDay();

        // Duas chamadas do mesmo modelo/tier hoje — devem virar 1 linha agregada.
        IaUsage::create([
            'tenant_id' => $tenant->id, 'modelo' => 'gpt-teste', 'tier' => 'simples',
            'tokens_input' => 100, 'tokens_output' => 50, 'latencia_ms' => 800,
            'created_at' => $hoje->copy()->addHours(9),
        ]);
        IaUsage::create([
            'tenant_id' => $tenant->id, 'modelo' => 'gpt-teste', 'tier' => 'simples',
            'tokens_input' => 200, 'tokens_output' => 100, 'latencia_ms' => 1200,
            'created_at' => $hoje->copy()->addHours(10),
        ]);

        // Modelo diferente, mesmo dia — vira outra linha.
        IaUsage::create([
            'tenant_id' => $tenant->id, 'modelo' => 'gpt-complexo', 'tier' => 'complexo',
            'tokens_input' => 500, 'tokens_output' => 300, 'latencia_ms' => 3000,
            'created_at' => $hoje->copy()->addHours(11),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/ia-monitor');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment([
            'modelo'             => 'gpt-teste',
            'tier'               => 'simples',
            'chamadas'           => 2,
            'tokens_input'       => 300,
            'tokens_output'      => 150,
            'latencia_media_ms'  => 1000,
        ]);
        $response->assertJson(['total_hoje' => 3, 'total_7_dias' => 3]);
    }

    public function test_nao_conta_uso_fora_da_janela_de_dias(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        IaUsage::create([
            'tenant_id' => $tenant->id, 'modelo' => 'gpt-antigo', 'tier' => 'simples',
            'tokens_input' => 10, 'tokens_output' => 10, 'latencia_ms' => 500,
            'created_at' => now()->subDays(40),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/ia-monitor');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJson(['total_hoje' => 0, 'total_7_dias' => 0]);
    }

    public function test_dono_so_ve_uso_do_proprio_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);

        IaUsage::create([
            'tenant_id' => $tenantA->id, 'modelo' => 'modelo-a', 'tier' => 'simples',
            'tokens_input' => 10, 'tokens_output' => 10, 'latencia_ms' => 500,
            'created_at' => now(),
        ]);
        IaUsage::create([
            'tenant_id' => $tenantB->id, 'modelo' => 'modelo-b', 'tier' => 'simples',
            'tokens_input' => 10, 'tokens_output' => 10, 'latencia_ms' => 500,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/ia-monitor');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['modelo' => 'modelo-a']);
        $response->assertJsonMissing(['modelo' => 'modelo-b']);
    }

    public function test_admin_ve_uso_de_todos_os_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin   = User::factory()->create(['tenant_id' => null, 'perfil' => 'admin', 'ativo' => true]);

        IaUsage::create([
            'tenant_id' => $tenantA->id, 'modelo' => 'modelo-a', 'tier' => 'simples',
            'tokens_input' => 10, 'tokens_output' => 10, 'latencia_ms' => 500,
            'created_at' => now(),
        ]);
        IaUsage::create([
            'tenant_id' => $tenantB->id, 'modelo' => 'modelo-b', 'tier' => 'simples',
            'tokens_input' => 10, 'tokens_output' => 10, 'latencia_ms' => 500,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/painel/ia-monitor');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }
}
