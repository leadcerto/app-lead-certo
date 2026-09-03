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

    public function test_formatar_nome_para_google_isola_primeiro_nome_id_e_sobrenome(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 14380,
            'nome'      => 'Adalberto Martins',
            'sobrenome' => null,
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Adalberto', $entry['givenName']);
        $this->assertSame('14380', $entry['middleName']);
        $this->assertSame('Martins', $entry['familyName']);
    }

    public function test_formatar_nome_para_google_com_nome_composto(): void
    {
        $contato = Contato::factory()->create([
            'id'        => 5500,
            'nome'      => 'Maria Clara dos Santos',
            'sobrenome' => null,
        ]);

        $google = app(GoogleService::class);
        $entry  = $google->formatarNomeParaGoogle($contato);

        $this->assertSame('Maria', $entry['givenName']);
        $this->assertSame('5500', $entry['middleName']);
        $this->assertSame('Clara Dos Santos', $entry['familyName']);
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
