<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TraducaoServiceAlvoEAntiOscilacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_idioma_do_vendedor_quando_atribuido(): void
    {
        $tenant   = Tenant::factory()->create(['locale' => 'pt-BR']);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'idioma' => 'en-US']);

        $idioma = app(TraducaoService::class)->resolverIdiomaAtendente($vendedor->id, $tenant->locale);

        $this->assertSame('en-US', $idioma);
    }

    public function test_cai_pro_locale_do_tenant_sem_vendedor_atribuido(): void
    {
        $idioma = app(TraducaoService::class)->resolverIdiomaAtendente(null, 'es-ES');

        $this->assertSame('es-ES', $idioma);
    }

    public function test_nunca_muda_idioma_com_mensagem_curta_e_sem_historico(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect([]), textoAtual: 'ok'
        );

        $this->assertFalse($deve);
    }

    public function test_muda_idioma_com_mensagem_longa_mesmo_sem_historico(): void
    {
        $textoLongo = 'I would like to know if my reservation for next week is already confirmed, please.';

        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect([]), textoAtual: $textoLongo
        );

        $this->assertTrue($deve);
    }

    public function test_muda_idioma_com_duas_mensagens_consecutivas_no_mesmo_idioma(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect(['en', 'en']), textoAtual: 'ok'
        );

        $this->assertTrue($deve);
    }

    public function test_nao_muda_quando_idioma_detectado_e_igual_ao_atual(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'pt',
            ultimasMensagensIdioma: collect(['pt', 'pt']), textoAtual: 'texto qualquer, tanto faz o tamanho'
        );

        $this->assertFalse($deve);
    }
}
