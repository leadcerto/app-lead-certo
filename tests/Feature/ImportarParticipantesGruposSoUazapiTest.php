<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado do planejamento do canal WhatsApp Messenger próprio (23/09): mesmo
 * gap do SincronizarContatosWhatsApp — filtro por tipo='nao_oficial' sem
 * checar provider='uazapi'.
 */
class ImportarParticipantesGruposSoUazapiTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_processa_canal_nao_oficial_de_outro_provider(): void
    {
        Http::fake(['*/group/list' => Http::response(['groups' => []], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'outro_provider_qualquer',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_ainda_processa_canal_uazapi_normalmente(): void
    {
        Http::fake(['*/group/list' => Http::response(['groups' => []], 200)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/group/list'));
    }
}
