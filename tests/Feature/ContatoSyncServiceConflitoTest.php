<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    private function criarToken(Tenant $tenant): GoogleToken
    {
        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'franqueado@empresa.com',
            'access_token'  => 'access-token-teste',
            'refresh_token' => 'refresh-token-teste',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);
    }

    private function fakeConexoesGoogle(string $telefone, string $nome, string $empresa): void
    {
        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName'   => 'people/c987654321',
                    'etag'           => 'etag-999',
                    'names'          => [['displayName' => $nome]],
                    'phoneNumbers'   => [['value' => $telefone]],
                    'organizations'  => [['name' => $empresa]],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);
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

    /**
     * Regressão do desvio documentado no relatório da Task 3 (camposJaHumanos()):
     * um Contato PRÉ-EXISTENTE (ex: veio da agenda do WhatsApp) sem nenhum
     * VinculoContatoTenant ainda ganha aqui seu primeiro vínculo Google. Sem
     * camposJaHumanos() marcando 'empresa' como já-editado-por-humano na
     * criação do vínculo, resolverCampoGoogle() trataria o campo já
     * preenchido como "nunca editado" e aceitaria qualquer valor do Google
     * sem checar — sobrescrevendo dado real de negócio silenciosamente.
     *
     * Diferente dos testes acima (que chamam resolverCampoGoogle() direto
     * sobre um vínculo já montado à mão), este passa pelo fluxo de verdade —
     * sincronizar() → processarPessoa() → firstOrCreate() — que é o único
     * lugar onde camposJaHumanos() é exercitado.
     */
    public function test_empresa_pre_existente_nao_e_sobrescrita_no_primeiro_vinculo_google(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone' => '5521999995555',
            'nome'     => 'Marcos Souza',
            'empresa'  => 'Transportes Silva',
        ]);

        // Mesmo nome dos dois lados — evita cair no ramo de "número
        // possivelmente reciclado" (o que importa aqui é só o campo empresa).
        $this->fakeConexoesGoogle('5521999995555', 'Marcos Souza', 'Fretes ABC');

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Transportes Silva', $contato->empresa); // não foi sobrescrito

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        $this->assertNotNull($vinculo, 'primeiro vínculo Google deveria ter sido criado');
        $this->assertSame(
            ['sugerido' => 'Fretes ABC', 'origem' => 'google'],
            $vinculo->campos_pendentes_auditoria['empresa'] ?? null
        );
    }
}
