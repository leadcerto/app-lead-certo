<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbQualidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame('pendente', $score->categorias['identidade']['status']);
        $this->assertNull($score->categorias['identidade']['nota']);
        $this->assertCount(7, $score->categorias);
        // nota_geral = so a media de 'atividade' (100), nao afetada pelas 6 pendentes
        $this->assertSame(100, $score->nota_geral);
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
