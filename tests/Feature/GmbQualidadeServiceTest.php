<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbQualidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmbQualidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    private function criarPerfilComGoogle(Tenant $tenant, array $extra = []): PerfilGmb
    {
        return PerfilGmb::create(array_merge([
            'tenant_id'          => $tenant->id,
            'nome'               => 'Frete Rio — Copacabana',
            'city'               => 'Rio de Janeiro',
            'state'              => 'RJ',
            'link_gmb'           => 'https://maps.google.com/?cid=123',
            'google_location_id' => 'locations/999888777',
            'ativo'              => true,
        ], $extra));
    }

    private function criarTokenGoogle(Tenant $tenant): GoogleToken
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'dono@teste.com',
            'access_token'  => 'token-valido',
            'refresh_token' => 'refresh-123',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['business.manage'],
        ]);
    }

    private function criarPost(PerfilGmb $perfil, string $status, ?\Carbon\Carbon $publicadoEm): GmbPost
    {
        return GmbPost::create([
            'tenant_id'     => $perfil->tenant_id,
            'perfil_gmb_id' => $perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'x',
            'data_agendada' => now(),
            'status'        => $status,
            'publicado_em'  => $publicadoEm,
        ]);
    }

    public function test_sem_nenhum_post_publicado_atividade_fica_zerada(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['atividade']['nota']);
        $this->assertSame('calculado', $score->categorias['atividade']['status']);
        $this->assertSame('erro', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
        $this->assertSame(0, $score->nota_geral);
    }

    public function test_post_publicado_ha_menos_de_7_dias_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(2));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['atividade']['nota']);
        $this->assertSame('ok', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
    }

    public function test_post_publicado_entre_8_e_14_dias_gera_aviso(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(10));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(60, $score->categorias['atividade']['nota']);
        $this->assertSame('aviso', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
        $this->assertNotNull($score->categorias['atividade']['diagnosticos'][0]['acao_url']);
    }

    public function test_post_publicado_ha_mais_de_14_dias_gera_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(30));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(20, $score->categorias['atividade']['nota']);
        $this->assertSame('erro', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
    }

    public function test_post_agendado_nao_publicado_e_ignorado_no_calculo(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'agendado', null);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['atividade']['nota']);
    }

    public function test_categorias_ainda_nao_implementadas_ficam_pendentes_e_fora_da_media(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDay());

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertSame('pendente', $score->categorias['conteudo']['status']);
        $this->assertNull($score->categorias['conteudo']['nota']);
        $this->assertCount(7, $score->categorias);
        // nota_geral = so a media de 'atividade' (100); identidade ('erro') e as pendentes nao entram
        $this->assertSame(100, $score->nota_geral);
    }

    public function test_identidade_com_categoria_secundarias_nome_e_descricao_ideais_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'categories' => [
                    'primaryCategory'      => ['displayName' => 'Transportadora'],
                    'additionalCategories' => [
                        ['displayName' => 'Mudanças'],
                        ['displayName' => 'Fretes'],
                        ['displayName' => 'Logística'],
                    ],
                ],
                'title'   => 'Frete Rio Transportes',
                'profile' => ['description' => str_repeat('Somos especialistas em fretes e mudanças. ', 6)],
            ], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['identidade']['nota']);
        $this->assertSame('calculado', $score->categorias['identidade']['status']);
        $this->assertSame('ok', $score->categorias['identidade']['diagnosticos'][0]['tipo']);
    }

    public function test_identidade_sem_categoria_secundaria_nome_com_separador_e_sem_descricao_da_nota_baixa(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'categories' => ['primaryCategory' => null, 'additionalCategories' => []],
                'title'      => 'Frete Rio - Melhor Transportadora do Rio',
                'profile'    => ['description' => ''],
            ], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['identidade']['nota']);
        $this->assertSame('calculado', $score->categorias['identidade']['status']);
        $this->assertCount(4, $score->categorias['identidade']['diagnosticos']);
    }

    public function test_identidade_sem_google_location_id_fica_status_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant); // sem google_location_id
        session(['tenant_id' => $tenant->id]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertNull($score->categorias['identidade']['nota']);
        $this->assertStringContainsString('ID do Perfil no Google', $score->categorias['identidade']['diagnosticos'][0]['mensagem']);
    }

    public function test_identidade_sem_token_google_fica_status_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        session(['tenant_id' => $tenant->id]);
        // nenhum GoogleToken criado

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertStringContainsString('Nenhuma conta Google conectada', $score->categorias['identidade']['diagnosticos'][0]['mensagem']);
    }

    public function test_identidade_com_api_desativada_explica_como_ativar(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Business Information API has not been used in project 159179119828 before or it is disabled (SERVICE_DISABLED)'],
            ], 403),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertStringContainsString('console.developers.google.com', $score->categorias['identidade']['diagnosticos'][0]['mensagem']);
    }

    public function test_identidade_com_location_nao_encontrada_explica_o_404(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['error' => ['message' => 'Requested entity was not found.']], 404),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertStringContainsString('404', $score->categorias['identidade']['diagnosticos'][0]['mensagem']);
    }

    public function test_identidade_com_quota_excedida_explica_o_429(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['identidade']['status']);
        $this->assertStringContainsString('Quota excedida', $score->categorias['identidade']['diagnosticos'][0]['mensagem']);
    }

    public function test_avaliar_de_novo_atualiza_o_mesmo_registro_em_vez_de_duplicar(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);

        $service = app(GmbQualidadeService::class);
        $service->avaliar($perfil);
        $service->avaliar($perfil);

        $this->assertSame(1, \App\Models\GmbQualidadeScore::count());
    }
}
