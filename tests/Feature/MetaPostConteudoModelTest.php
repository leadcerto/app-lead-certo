<?php

namespace Tests\Feature;

use App\Models\MetaPostConteudo;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPostConteudoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_conteudo_e_visivel_apenas_para_o_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();

        session(['tenant_id' => $tenant->id]);

        MetaPostConteudo::create([
            'tenant_id' => $tenant->id,
            'titulo'    => 'Promo fim de semana',
            'texto'     => 'Texto de teste',
        ]);

        MetaPostConteudo::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id,
            'titulo'    => 'Conteúdo de outro tenant',
            'texto'     => 'Texto de teste',
        ]);

        $this->assertSame(1, MetaPostConteudo::count());
    }

    public function test_categoria_e_ativo_tem_valor_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $conteudo = MetaPostConteudo::create([
            'tenant_id' => $tenant->id,
            'titulo'    => 'Sem categoria definida',
            'texto'     => 'Texto de teste',
        ]);

        $this->assertSame('geral', $conteudo->fresh()->categoria);
        $this->assertTrue($conteudo->fresh()->ativo);
    }

    public function test_scope_ativos_ignora_conteudo_desativado(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $ativo = MetaPostConteudo::create([
            'tenant_id' => $tenant->id, 'titulo' => 'Ativo', 'texto' => 'x', 'ativo' => true,
        ]);
        MetaPostConteudo::create([
            'tenant_id' => $tenant->id, 'titulo' => 'Inativo', 'texto' => 'x', 'ativo' => false,
        ]);

        $ativos = MetaPostConteudo::ativos()->get();

        $this->assertCount(1, $ativos);
        $this->assertSame($ativo->id, $ativos->first()->id);
    }

    public function test_palavras_chave_e_castado_como_array(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $conteudo = MetaPostConteudo::create([
            'tenant_id'      => $tenant->id,
            'titulo'         => 'Com gatilho',
            'texto'          => 'x',
            'modo_gatilho'   => 'palavra_chave',
            'palavras_chave' => ['QUERO', 'ORÇAMENTO'],
        ]);

        $this->assertSame(['QUERO', 'ORÇAMENTO'], $conteudo->fresh()->palavras_chave);
    }
}
