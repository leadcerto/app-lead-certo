<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoSyncServiceConflitoTest extends TestCase
{
    use RefreshDatabase;

    private function vinculo(array $contatoAttrs = [], array $vinculoAttrs = []): VinculoContatoTenant
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create($contatoAttrs);

        return VinculoContatoTenant::create(array_merge([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], $vinculoAttrs));
    }

    public function test_aceita_correcao_do_google_quando_campo_local_nao_e_humano(): void
    {
        $vinculo = $this->vinculo(['empresa' => null]); // automático/vazio, nunca editado por humano

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->contato->refresh();
        $vinculo->refresh();
        $this->assertSame('Fretes ABC', $vinculo->contato->empresa);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria ?? []);
    }

    public function test_nao_sobrescreve_quando_humano_editou_local_e_valores_divergem(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Transportes Silva'],
            ['campos_editados_humano' => ['empresa' => now()->toIso8601String()]]
        );

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->contato->refresh();
        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa); // não mexeu
        $this->assertSame(
            ['sugerido' => 'Fretes ABC', 'origem' => 'google'],
            $vinculo->campos_pendentes_auditoria['empresa'] ?? null
        );
        // linha de base atualiza mesmo sem aplicar — evita recriar a pendência de novo
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
    }

    public function test_nao_recria_pendencia_ja_existente_no_ciclo_seguinte(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Transportes Silva'],
            [
                'campos_editados_humano'     => ['empresa' => now()->toIso8601String()],
                'google_valores_enviados'    => ['empresa' => 'Fretes ABC'], // já rodou uma vez
                'campos_pendentes_auditoria' => ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
            ]
        );

        $service = app(ContatoSyncService::class);
        // Ciclo seguinte do cron, mesmo valor do Google — não deve mudar nada
        $service->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa);
        $this->assertSame(
            ['sugerido' => 'Fretes ABC', 'origem' => 'google'],
            $vinculo->campos_pendentes_auditoria['empresa']
        );
    }

    public function test_ausencia_no_google_nunca_apaga_campo_local(): void
    {
        $vinculo = $this->vinculo(['empresa' => 'Transportes Silva']);

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', null);

        $vinculo->contato->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa);
    }

    public function test_campo_nome_usa_semnomereal_como_criterio_de_vazio(): void
    {
        $vinculo = $this->vinculo(['nome' => 'Sem Nome']);

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'nome', 'Rodrigo Alves');

        $vinculo->contato->refresh();
        $this->assertSame('Rodrigo Alves', $vinculo->contato->nome);
    }

    public function test_valor_igual_a_linha_de_base_nao_faz_nada(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Fretes ABC'],
            ['google_valores_enviados' => ['empresa' => 'Fretes ABC']]
        );

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->refresh();
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria ?? []);
    }
}
