<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbQualidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['identidade']['nota']);
        $this->assertSame('calculado', $score->categorias['identidade']['status']);
        $this->assertSame('ok', $score->categorias['identidade']['diagnosticos'][0]['tipo']);
        // Os 4 sub-criterios aparecem sempre, mesmo quando todos passam (formato PageSpeed:
        // auditorias aprovadas ficam visiveis, nao so as reprovadas).
        $this->assertCount(4, $score->categorias['identidade']['diagnosticos']);
        $this->assertSame(4, collect($score->categorias['identidade']['diagnosticos'])->where('tipo', 'ok')->count());
    }

    public function test_identidade_parcial_mostra_diagnostico_por_subcriterio_inclusive_os_que_passaram(): void
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
                // separador " - " no nome -> so este subcriterio falha
                'title'   => 'Frete Rio - Transportes',
                'profile' => ['description' => str_repeat('Somos especialistas em fretes e mudanças. ', 6)],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(80, $score->categorias['identidade']['nota']);
        $diagnosticos = collect($score->categorias['identidade']['diagnosticos']);
        $this->assertCount(4, $diagnosticos);
        $this->assertSame(3, $diagnosticos->where('tipo', 'ok')->count());
        $this->assertSame(1, $diagnosticos->where('tipo', 'aviso')->count());
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
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
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

    public function test_localizacao_com_endereco_completo_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'storefrontAddress' => [
                    'addressLines'       => ['Rua das Flores, 123'],
                    'locality'           => 'Rio de Janeiro',
                    'administrativeArea' => 'RJ',
                    'postalCode'         => '22000-000',
                    'regionCode'         => 'BR',
                ],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['localizacao']['nota']);
        $this->assertSame('ok', $score->categorias['localizacao']['diagnosticos'][0]['tipo']);
    }

    public function test_localizacao_com_endereco_incompleto_lista_um_diagnostico_por_campo(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'storefrontAddress' => [
                    'addressLines' => ['Rua das Flores, 123'],
                    'locality'     => 'Rio de Janeiro',
                ],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(40, $score->categorias['localizacao']['nota']);
        $diagnosticos = collect($score->categorias['localizacao']['diagnosticos']);
        // 5 campos (addressLines, locality, administrativeArea, postalCode, regionCode), um
        // diagnostico cada — formato PageSpeed: os 2 que passaram tambem ficam visiveis.
        $this->assertCount(5, $diagnosticos);
        $this->assertSame(2, $diagnosticos->where('tipo', 'ok')->count());
        $this->assertSame(3, $diagnosticos->where('tipo', 'aviso')->count());
        $cep = $diagnosticos->first(fn ($d) => str_contains($d['mensagem'], 'CEP'));
        $this->assertNotNull($cep);
        $this->assertSame('aviso', $cep['tipo']);
    }

    public function test_localizacao_com_area_de_atendimento_completa_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'serviceArea' => [
                    'businessType' => 'CUSTOMER_LOCATION_ONLY',
                    'places'       => ['placeInfos' => [['placeName' => 'Rio de Janeiro, RJ']]],
                ],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['localizacao']['nota']);
    }

    public function test_localizacao_sem_endereco_nem_area_de_atendimento_fica_zerada(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['localizacao']['nota']);
        $this->assertSame('erro', $score->categorias['localizacao']['diagnosticos'][0]['tipo']);
    }

    public function test_presenca_externa_com_site_cadastrado_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create(['whatsapp_phone' => '21999998888', 'instagram_url' => 'https://instagram.com/freterio']);
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['websiteUri' => 'https://freterio.com.br/barra-da-tijuca'], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['presenca_externa']['nota']);
        $this->assertSame('ok', $score->categorias['presenca_externa']['diagnosticos'][0]['tipo']);
        $this->assertSame('ok', $score->categorias['presenca_externa']['diagnosticos'][1]['tipo']);
        $this->assertSame('ok', $score->categorias['presenca_externa']['diagnosticos'][2]['tipo']);
        $this->assertSame('info', $score->categorias['presenca_externa']['diagnosticos'][3]['tipo']);
    }

    public function test_presenca_externa_sem_site_fica_zerada_mas_sempre_lembra_do_schema_org(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['presenca_externa']['nota']);
        $this->assertCount(4, $score->categorias['presenca_externa']['diagnosticos']);
        $this->assertStringContainsString('Schema.org', $score->categorias['presenca_externa']['diagnosticos'][3]['mensagem']);
        $this->assertNotNull($score->categorias['presenca_externa']['diagnosticos'][3]['acao_url']);
    }

    public function test_saude_risco_com_horario_cadastrado_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'regularHours' => ['periods' => [
                    ['openDay' => 'MONDAY', 'openTime' => '09:00', 'closeDay' => 'MONDAY', 'closeTime' => '18:00'],
                ]],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['saude_risco']['nota']);
        $this->assertSame('ok', $score->categorias['saude_risco']['diagnosticos'][0]['tipo']);
        $this->assertSame('info', $score->categorias['saude_risco']['diagnosticos'][1]['tipo']);
    }

    public function test_saude_risco_sem_horario_cadastrado_fica_zerada(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['saude_risco']['nota']);
        $this->assertSame('erro', $score->categorias['saude_risco']['diagnosticos'][0]['tipo']);
        $this->assertCount(2, $score->categorias['saude_risco']['diagnosticos']);
    }

    public function test_perfil_bom_com_todos_os_dados_ideais_calcula_5_categorias_com_nota_alta(): void
    {
        $tenant = Tenant::factory()->create(['whatsapp_phone' => '21999998888', 'instagram_url' => 'https://instagram.com/freterio']);
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDay());

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'categories' => [
                    'primaryCategory'      => ['displayName' => 'Transportadora'],
                    'additionalCategories' => [['displayName' => 'Mudanças'], ['displayName' => 'Fretes'], ['displayName' => 'Logística']],
                ],
                'title'   => 'Frete Rio Transportes',
                'profile' => ['description' => str_repeat('Somos especialistas em fretes e mudanças. ', 6)],
                'storefrontAddress' => [
                    'addressLines' => ['Rua das Flores, 123'], 'locality' => 'Rio de Janeiro',
                    'administrativeArea' => 'RJ', 'postalCode' => '22000-000', 'regionCode' => 'BR',
                ],
                'websiteUri'   => 'https://freterio.com.br/barra-da-tijuca',
                'regularHours' => ['periods' => [['openDay' => 'MONDAY', 'openTime' => '09:00', 'closeDay' => 'MONDAY', 'closeTime' => '18:00']]],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['atividade']['nota']);
        $this->assertSame(100, $score->categorias['identidade']['nota']);
        $this->assertSame(100, $score->categorias['localizacao']['nota']);
        $this->assertSame(100, $score->categorias['presenca_externa']['nota']);
        $this->assertSame(100, $score->categorias['saude_risco']['nota']);
        $this->assertSame('pendente', $score->categorias['conteudo']['status']);
        // Sem contas do Google Meu Negócio configuradas neste fake -> reputacao fica em erro
        // (cascata da API de reviews), fora da media, igual as outras categorias em erro.
        $this->assertSame('erro', $score->categorias['reputacao']['status']);
        $this->assertSame(100, $score->nota_geral);

        // 1 chamada de localizacao (compartilhada entre as 5 categorias) + 1 chamada de
        // reputacao pra resolver a conta do Google Meu Negocio antes das reviews.
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/v1/locations/999888777')
            && str_contains($req->url(), 'readMask=')
        );
    }

    public function test_perfil_incompleto_calcula_notas_baixas_nas_5_categorias_e_erro_se_api_falhar(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['error' => ['message' => 'internal error']], 500),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        foreach (['identidade', 'localizacao', 'presenca_externa', 'saude_risco'] as $chave) {
            $this->assertSame('erro', $score->categorias[$chave]['status'], "categoria {$chave} deveria estar 'erro'");
            $this->assertNull($score->categorias[$chave]['nota']);
        }
        // atividade eh 0 (sem post), o resto ('erro'/'pendente') fica fora da media
        $this->assertSame(0, $score->nota_geral);
    }

    public function test_fallback_de_token_usa_especificamente_a_conta_central_lead_certo(): void
    {
        // Ordem deliberada: o token de "outro" tenant eh criado (e portanto
        // recebe id mais baixo) ANTES do token central — se o codigo caisse
        // de volta pra "pega o token mais antigo" isso escolheria o token
        // errado. So um lookup por Tenant::CENTRAL_ID passa neste teste.
        $tenantOutro = Tenant::factory()->create();
        $this->criarTokenGoogle($tenantOutro);
        GoogleToken::where('tenant_id', $tenantOutro->id)->update(['access_token' => 'token-de-outro-tenant-nao-deveria-ser-usado']);

        $tenantCentral = Tenant::factory()->create(['id' => Tenant::CENTRAL_ID]);
        $this->criarTokenGoogle($tenantCentral);
        GoogleToken::where('tenant_id', $tenantCentral->id)->update(['access_token' => 'token-central-correto']);

        $tenantSemToken = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenantSemToken);
        session(['tenant_id' => $tenantSemToken->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['title' => 'Frete Rio'], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => []], 200),
        ]);

        app(GmbQualidadeService::class)->avaliar($perfil);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer token-central-correto'));
    }

    private function fakeReviews(array $overrides = []): void
    {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'categories' => [
                    'primaryCategory'      => ['displayName' => 'Transportadora'],
                    'additionalCategories' => [['displayName' => 'Mudanças'], ['displayName' => 'Fretes']],
                ],
                'title'   => 'Frete Rio Transportes',
                'profile' => ['description' => str_repeat('Somos especialistas. ', 20)],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/123']]], 200),
            'mybusiness.googleapis.com/v4/*/reviews*' => Http::response(array_merge([
                'reviews'          => [],
                'averageRating'    => 0,
                'totalReviewCount' => 0,
            ], $overrides), 200),
        ]);
    }

    public function test_reputacao_perfil_bom_com_avaliacoes_ideais_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        $criadaHaUmDia = now()->subDay()->toIso8601String();
        $respondidaMeioDiaDepois = now()->subDay()->addHours(12)->toIso8601String();

        $reviews = [];
        for ($i = 0; $i < 20; $i++) {
            $reviews[] = [
                'reviewId'    => "r{$i}",
                'comment'     => 'Serviço de transportadora excelente em Rio de Janeiro, super rápido.',
                'createTime'  => $criadaHaUmDia,
                'updateTime'  => $criadaHaUmDia,
                'reviewReply' => [
                    'comment'    => 'Obrigado pela confiança na nossa transportadora em Rio de Janeiro!',
                    'updateTime' => $respondidaMeioDiaDepois,
                ],
            ];
        }

        $this->fakeReviews([
            'reviews'          => $reviews,
            'averageRating'    => 4.9,
            'totalReviewCount' => 60,
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['reputacao']['nota']);
        $this->assertSame('calculado', $score->categorias['reputacao']['status']);
        $diagnosticos = collect($score->categorias['reputacao']['diagnosticos']);
        $this->assertCount(7, $diagnosticos);
        $this->assertSame(7, $diagnosticos->where('tipo', 'ok')->count());
    }

    public function test_reputacao_perfil_incompleto_da_notas_baixas_com_7_diagnosticos(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        $reviews = [];
        for ($i = 0; $i < 5; $i++) {
            $reviews[] = [
                'reviewId'   => "r{$i}",
                'comment'    => 'Atendimento ok, nada de mais.',
                'createTime' => now()->subDays(30)->toIso8601String(),
                'updateTime' => now()->subDays(30)->toIso8601String(),
                // sem reviewReply — nenhuma respondida
            ];
        }

        $this->fakeReviews([
            'reviews'          => $reviews,
            'averageRating'    => 3.5,
            'totalReviewCount' => 5,
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('calculado', $score->categorias['reputacao']['status']);
        $diagnosticos = collect($score->categorias['reputacao']['diagnosticos']);
        $this->assertCount(7, $diagnosticos);

        // Volume: 5 avaliacoes -> +5
        $this->assertStringContainsString('Só 5 avaliação', $diagnosticos[0]['mensagem']);
        // Recorrencia: 30 dias -> aviso
        $this->assertSame('aviso', $diagnosticos[1]['tipo']);
        // Nota media 3.5 -> erro
        $this->assertSame('erro', $diagnosticos[2]['tipo']);
        $this->assertStringContainsString('abaixo de 4.0', $diagnosticos[2]['mensagem']);
        // Mencao: comentarios nao citam categoria/cidade -> aviso
        $this->assertSame('aviso', $diagnosticos[3]['tipo']);
        // Taxa de resposta 0% -> erro
        $this->assertSame('erro', $diagnosticos[4]['tipo']);
        // Prazo: nenhuma respondida -> erro
        $this->assertSame('erro', $diagnosticos[5]['tipo']);
        $this->assertStringContainsString('Nenhuma avaliação recente foi respondida', $diagnosticos[5]['mensagem']);
        // Termos na resposta: nenhuma respondida -> aviso
        $this->assertSame('aviso', $diagnosticos[6]['tipo']);

        $this->assertSame(5, $score->categorias['reputacao']['nota']); // so o sub-criterio de volume pontuou
    }

    public function test_reputacao_sem_nenhuma_avaliacao_fica_com_um_diagnostico_so(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        $this->fakeReviews(['reviews' => [], 'averageRating' => 0, 'totalReviewCount' => 0]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['reputacao']['nota']);
        $this->assertSame('calculado', $score->categorias['reputacao']['status']);
        $this->assertCount(1, $score->categorias['reputacao']['diagnosticos']);
        $this->assertStringContainsString('Nenhuma avaliação registrada', $score->categorias['reputacao']['diagnosticos'][0]['mensagem']);
        $this->assertNotNull($score->categorias['reputacao']['diagnosticos'][0]['acao_url']);
    }

    public function test_reputacao_fica_erro_em_cascata_quando_location_falha(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant); // sem google_location_id
        session(['tenant_id' => $tenant->id]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['reputacao']['status']);
        $this->assertStringContainsString('ID do Perfil no Google', $score->categorias['reputacao']['diagnosticos'][0]['mensagem']);
    }

    public function test_reputacao_fica_erro_quando_so_a_chamada_de_reviews_falha(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'title' => 'Frete Rio Transportes',
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('erro', $score->categorias['reputacao']['status']);
        $this->assertStringContainsString('429', $score->categorias['reputacao']['diagnosticos'][0]['mensagem']);
        // As outras categorias que so dependem de buscarDadosLocation() continuam calculadas normalmente:
        $this->assertSame('calculado', $score->categorias['identidade']['status']);
    }

    public function test_reputacao_calcula_prazo_medio_so_com_as_respondidas(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        $criada = now()->subDays(2)->toIso8601String();

        $this->fakeReviews([
            'reviews' => [
                [
                    'reviewId'    => 'r1',
                    'comment'     => 'Bom.',
                    'createTime'  => $criada,
                    'updateTime'  => $criada,
                    'reviewReply' => ['comment' => 'Obrigado!', 'updateTime' => now()->subDays(2)->addHours(10)->toIso8601String()],
                ],
                [
                    'reviewId'    => 'r2',
                    'comment'     => 'Bom tambem.',
                    'createTime'  => $criada,
                    'updateTime'  => $criada,
                    'reviewReply' => ['comment' => 'Valeu!', 'updateTime' => now()->subDays(2)->addHours(30)->toIso8601String()],
                ],
                [
                    // sem resposta - nao entra na media de prazo
                    'reviewId'   => 'r3',
                    'comment'    => 'Ok.',
                    'createTime' => $criada,
                    'updateTime' => $criada,
                ],
            ],
            'averageRating'    => 4.0,
            'totalReviewCount' => 3,
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        // Media (10h + 30h) / 2 = 20h, dentro de 48h -> ok
        $diagnosticoPrazo = collect($score->categorias['reputacao']['diagnosticos'])
            ->first(fn ($d) => str_contains($d['mensagem'], 'Tempo médio de resposta'));
        $this->assertNotNull($diagnosticoPrazo);
        $this->assertSame('ok', $diagnosticoPrazo['tipo']);
        $this->assertStringContainsString('20h', $diagnosticoPrazo['mensagem']);
    }

    public function test_reputacao_mencao_a_categoria_nao_reconhece_texto_sem_acento(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        // Categoria da ficha e "Logística" (com acento), mas o cliente escreveu sem acento.
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'categories' => ['primaryCategory' => ['displayName' => 'Logística']],
            ], 200),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/123']]], 200),
            'mybusiness.googleapis.com/v4/*/reviews*' => Http::response([
                'reviews' => [[
                    'reviewId'   => 'r1',
                    'comment'    => 'Otimo servico de logistica, recomendo.', // sem acentos
                    'createTime' => now()->subDay()->toIso8601String(),
                    'updateTime' => now()->subDay()->toIso8601String(),
                ]],
                'averageRating'    => 5,
                'totalReviewCount' => 1,
            ], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        // v1 nao normaliza acento -> "logistica" (sem acento) nao bate com "Logística" (com acento) -> 0%
        $diagnosticoMencao = collect($score->categorias['reputacao']['diagnosticos'])
            ->first(fn ($d) => str_contains($d['mensagem'], 'citam o serviço ou a cidade'));
        $this->assertStringContainsString('Só 0%', $diagnosticoMencao['mensagem']);
    }

    private function criarPerfilComTenant(array $extraTenant = [], array $extraPerfil = []): PerfilGmb
    {
        $tenant = Tenant::factory()->create($extraTenant);
        $perfil = $this->criarPerfilComGoogle($tenant, $extraPerfil);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);
        return $perfil;
    }

    public function test_presenca_externa_com_pagina_propria_no_site_da_40_pontos(): void
    {
        $perfil = $this->criarPerfilComTenant();

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['websiteUri' => 'https://frete.rio.br/barra-da-tijuca'], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(40, $score->categorias['presenca_externa']['nota']);
        $diagnostico = collect($score->categorias['presenca_externa']['diagnosticos'])->first();
        $this->assertSame('ok', $diagnostico['tipo']);
        $this->assertStringContainsString('página própria', $diagnostico['mensagem']);
    }

    public function test_presenca_externa_com_site_so_a_raiz_da_25_pontos(): void
    {
        foreach (['https://frete.rio.br/', 'https://frete.rio.br'] as $url) {
            $perfil = $this->criarPerfilComTenant();

            Http::fake([
                'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['websiteUri' => $url], 200),
            ]);

            $score = app(GmbQualidadeService::class)->avaliar($perfil);

            $this->assertSame(25, $score->categorias['presenca_externa']['nota'], "falhou para a URL {$url}");
            $diagnostico = collect($score->categorias['presenca_externa']['diagnosticos'])->first();
            $this->assertSame('aviso', $diagnostico['tipo']);
            $this->assertStringContainsString('página inicial', $diagnostico['mensagem']);
        }
    }

    public function test_presenca_externa_soma_whatsapp_e_rede_social_do_tenant(): void
    {
        $perfil = $this->criarPerfilComTenant(['whatsapp_phone' => '21999998888', 'instagram_url' => 'https://instagram.com/freterio']);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['websiteUri' => 'https://frete.rio.br/barra-da-tijuca'], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['presenca_externa']['nota']); // 40 + 30 + 30
        $diagnosticos = collect($score->categorias['presenca_externa']['diagnosticos']);
        $this->assertSame(3, $diagnosticos->where('tipo', 'ok')->count()); // site + whatsapp + rede social (info nao conta)
    }

    public function test_presenca_externa_sem_whatsapp_nem_rede_social_avisa_os_dois(): void
    {
        $perfil = $this->criarPerfilComTenant();

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['presenca_externa']['nota']);
        $diagnosticos = collect($score->categorias['presenca_externa']['diagnosticos']);

        $whatsapp = $diagnosticos->first(fn ($d) => str_contains($d['mensagem'], 'WhatsApp'));
        $this->assertSame('aviso', $whatsapp['tipo']);
        $this->assertNull($whatsapp['acao_url']); // sem link de acao, ver Global Constraints

        $redeSocial = $diagnosticos->first(fn ($d) => str_contains($d['mensagem'], 'rede social'));
        $this->assertSame('aviso', $redeSocial['tipo']);
        $this->assertNull($redeSocial['acao_url']);
    }
}
