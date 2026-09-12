<?php

namespace Tests\Feature;

use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GmbQualidadeScoreModelTest extends TestCase
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

    public function test_tabela_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('gmb_qualidade_scores'));
        $this->assertTrue(Schema::hasColumns('gmb_qualidade_scores', [
            'id', 'tenant_id', 'perfil_gmb_id', 'nota_geral', 'categorias', 'avaliado_em',
            'created_at', 'updated_at',
        ]));
    }

    public function test_cria_score_vinculado_ao_perfil_e_so_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $perfil      = $this->criarPerfil($tenant);

        session(['tenant_id' => $tenant->id]);

        $score = GmbQualidadeScore::create([
            'tenant_id'      => $tenant->id,
            'perfil_gmb_id'  => $perfil->id,
            'nota_geral'     => 100,
            'categorias'     => ['atividade' => ['nota' => 100, 'status' => 'calculado']],
            'avaliado_em'    => now(),
        ]);

        GmbQualidadeScore::withoutGlobalScopes()->create([
            'tenant_id'     => $outroTenant->id,
            'perfil_gmb_id' => $this->criarPerfil($outroTenant)->id,
            'nota_geral'    => 50,
            'categorias'    => [],
            'avaliado_em'   => now(),
        ]);

        $this->assertSame(1, GmbQualidadeScore::count());
        $this->assertTrue($score->perfil->is($perfil));
        $this->assertIsArray($score->fresh()->categorias);
        $this->assertSame(100, $score->fresh()->categorias['atividade']['nota']);
    }
}
