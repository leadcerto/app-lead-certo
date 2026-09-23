<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Achado real 22-23/09: o agendador (cron) da VPS ficou ~45h totalmente
 * parado desde a migração de VPS (21/09) sem ninguém perceber — nenhum dos
 * 16 comandos agendados rodava, silenciosamente (ver leadcerto/_docs/
 * PENDENCIAS.md). Esse alerta é pra não depender de ninguém notar
 * manualmente de novo: um heartbeat gravado a cada 5min (routes/console.php)
 * denuncia no dashboard se o agendador parar de rodar.
 */
class DashboardControllerAlertaAgendadorParadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_expoe_alerta_quando_agendador_esta_parado_ha_mais_de_20min(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $ultimoHeartbeat = now()->subMinutes(45);
        Cache::forever('scheduler:ultimo_heartbeat', $ultimoHeartbeat->toIso8601String());

        $response = $this->actingAs($user)->getJson('/api/painel/dashboard');

        $response->assertOk();
        $response->assertJsonPath('alertas.agendador_parado.ultimo_heartbeat', $ultimoHeartbeat->toIso8601String());
        $this->assertGreaterThanOrEqual(45, $response->json('alertas.agendador_parado.minutos_atras'));
    }

    public function test_dashboard_nao_mostra_alerta_quando_agendador_esta_ativo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        Cache::forever('scheduler:ultimo_heartbeat', now()->subMinutes(3)->toIso8601String());

        $response = $this->actingAs($user)->getJson('/api/painel/dashboard');

        $response->assertOk();
        $response->assertJsonPath('alertas.agendador_parado', null);
    }

    public function test_dashboard_nao_mostra_alerta_quando_agendador_nunca_teve_heartbeat(): void
    {
        // Sem chave de cache nenhuma (ex: app recém-instalado/testes) — não deve
        // alertar por ausência, só por atraso confirmado, pra não dar falso
        // positivo nos primeiros minutos depois de subir o ambiente.
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->getJson('/api/painel/dashboard');

        $response->assertOk();
        $response->assertJsonPath('alertas.agendador_parado', null);
    }
}
