<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleEtiquetaService;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleEtiquetaServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Achado real 2026-09-22 (Leonardo, caso real "Diego Ognibene" #98325):
     * quando sobrenome está vazio, o nome completo NUNCA é mais cortado no
     * espaço pra virar givenName/familyName separados — isso quebrava a
     * convenção dominante do sistema (nome completo num campo só). O ID do
     * banco continua indo no nome do meio, normalmente.
     */
    public function test_formatar_nome_para_google_mantem_nome_completo_sem_sobrenome_separado(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 14380,
            'nome'      => 'Adalberto Martins',
            'sobrenome' => null,
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Adalberto Martins', $entry['givenName']);
        $this->assertSame('[14380]', $entry['middleName']);
        $this->assertArrayNotHasKey('familyName', $entry);
    }

    public function test_formatar_nome_para_google_usa_sobrenome_separado_quando_preenchido(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 5500,
            'nome'      => 'Maria Clara',
            'sobrenome' => 'dos Santos',
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Maria Clara', $entry['givenName']);
        $this->assertSame('[5500]', $entry['middleName']);
        $this->assertSame('Dos Santos', $entry['familyName']);
    }

    public function test_sincronizar_grupos_padrao_mapeia_etiquetas_no_google(): void
    {
        $tenant = Tenant::factory()->create();
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $token = GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@leadcerto.com',
            'access_token'  => 'tok',
            'refresh_token' => 'ref',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);

        // Mock das chamadas do Google People API
        Http::fake([
            '*contactGroups?pageSize=200*' => Http::response([
                'contactGroups' => [
                    ['name' => '🚩 NOVOS LEADS', 'resourceName' => 'contactGroups/novos_123'],
                    ['name' => '🚩 LEAD CERTO',  'resourceName' => 'contactGroups/lead_certo_456'],
                ],
            ], 200),
            '*contactGroups' => Http::response([
                'resourceName' => 'contactGroups/criado_789',
            ], 200),
        ]);

        $service = app(GoogleEtiquetaService::class);
        $mapeados = $service->sincronizarGrupos($token);

        $this->assertSame('contactGroups/novos_123', $mapeados['novos_leads'] ?? null);
        $this->assertSame('contactGroups/lead_certo_456', $mapeados['lead_certo'] ?? null);

        $this->assertDatabaseHas('etiqueta_google_grupos', [
            'tenant_id'                  => $tenant->id,
            'google_group_resource_name' => 'contactGroups/novos_123',
        ]);
    }
}
