<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardControllerAlertaCreditoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_expoe_alerta_quando_openrouter_esta_sem_credito(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        Cache::put(OpenRouterService::CACHE_KEY_SEM_CREDITO, [
            'desde'        => '2026-09-15T18:00:00+00:00',
            'ultima_falha' => '2026-09-16T02:00:00+00:00',
            'mensagem'     => 'This request requires more credits.',
        ], now()->addDay());

        $response = $this->actingAs($user)->getJson('/api/painel/dashboard');

        $response->assertOk();
        $response->assertJsonPath('alertas.openrouter_sem_credito.mensagem', 'This request requires more credits.');
        $response->assertJsonPath('alertas.openrouter_sem_credito.desde', '2026-09-15T18:00:00+00:00');
    }

    public function test_dashboard_nao_mostra_alerta_quando_openrouter_esta_ok(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->getJson('/api/painel/dashboard');

        $response->assertOk();
        $response->assertJsonPath('alertas.openrouter_sem_credito', null);
    }

    public function test_pagina_do_dashboard_renderiza_com_o_bloco_de_alerta_de_credito(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('openrouter_sem_credito', false);
    }
}
