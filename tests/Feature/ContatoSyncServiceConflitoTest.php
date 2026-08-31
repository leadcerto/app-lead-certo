<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Jobs\ProvisionarEtiquetasGoogleJob;
use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        // GoogleToken::booted() dispara ProvisionarEtiquetasGoogleJob de
        // verdade (QUEUE_CONNECTION=sync) -- sem isso, os testes deste
        // arquivo fazem chamada HTTP real pra API do Google. Chamadas
        // posteriores a Bus::fake() no corpo dos testes (pra
        // EnriquecerContatoNovoViaGoogleJob) não afetam este dispatch, que
        // já aconteceu aqui antes.
        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);

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
    /**
     * Payload REALISTA de um contato que o próprio Lead Certo empurrou pro
     * Google: `GoogleService::criarContato()` grava givenName=nome limpo,
     * middleName=ID do banco e familyName=sobrenome — e o Google compõe o
     * `displayName` a partir dos três ("Marcia 5000 Souza"). Ler o nome do
     * displayName trazia o eco do nosso próprio ID de volta pro campo `nome`:
     * `limparNome()` derruba o índice de 3-6 dígitos e sobra "Marcia Souza",
     * comparado contra a linha de base "Marcia" → conflito FALSO em todo
     * contato com sobrenome no primeiro sync pós-deploy.
     *
     * Aqui o primeiro nome é curto o bastante pra "Marcia Souza" nem passar no
     * portão de similaridade contra o "Marcia" local (66%), então o sintoma é
     * um ContatoPendente falso de "número possivelmente reciclado".
     */
    public function test_display_name_composto_pelo_nosso_push_nao_gera_conflito_de_numero_reciclado(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999994444',
            'nome'      => 'Marcia',
            'sobrenome' => 'Souza',
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id'              => $contato->id,
            'tenant_id'               => $tenant->id,
            'google_resource_name'    => 'people/c987654321',
            'google_etag'             => 'etag-999',
            // Linha de base no formato pós-fix: o valor TRANSFORMADO que
            // GoogleService::limparNome() de fato mandou pro Google.
            'google_valores_enviados' => ['nome' => 'Marcia', 'sobrenome' => 'Souza'],
            // Backfill (seção 8 do design) marca todo campo sincronizado já
            // preenchido como editado-por-humano no deploy.
            'campos_editados_humano'  => [
                'nome'      => now()->toIso8601String(),
                'sobrenome' => now()->toIso8601String(),
            ],
        ]);

        $this->fakePessoaComposta('5521999994444', 'Marcia 5000 Souza', 'Marcia', 'Souza');

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Marcia', $contato->nome);
        $this->assertSame('Souza', $contato->sobrenome);

        $this->assertSame(
            0,
            ContatoPendente::where('contato_existente_id', $contato->id)->count(),
            'contato empurrado por nós não pode voltar como "número possivelmente reciclado"'
        );

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria ?? []);
        $this->assertArrayNotHasKey('sobrenome', $vinculo->campos_pendentes_auditoria ?? []);
    }

    /**
     * Mesmo bug do teste acima, com o primeiro nome longo o bastante pra passar
     * no portão de similaridade — aí o sintoma é o descrito pelo revisor: uma
     * pendência de auditoria sugerindo "Marcia Fernanda Souza" como nome novo.
     * Aprovar essa sugestão corrompe o campo `nome` (vira nome + sobrenome).
     */
    public function test_display_name_composto_pelo_nosso_push_nao_gera_pendencia_falsa_de_nome(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999993333',
            'nome'      => 'Marcia Fernanda',
            'sobrenome' => 'Souza',
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id'              => $contato->id,
            'tenant_id'               => $tenant->id,
            'google_resource_name'    => 'people/c987654321',
            'google_etag'             => 'etag-999',
            'google_valores_enviados' => ['nome' => 'Marcia Fernanda', 'sobrenome' => 'Souza'],
            'campos_editados_humano'  => [
                'nome'      => now()->toIso8601String(),
                'sobrenome' => now()->toIso8601String(),
            ],
        ]);

        $this->fakePessoaComposta(
            '5521999993333',
            'Marcia Fernanda 5000 Souza',
            'Marcia Fernanda',
            'Souza'
        );

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Marcia Fernanda', $contato->nome);
        $this->assertSame('Souza', $contato->sobrenome);

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayNotHasKey(
            'nome',
            $vinculo->campos_pendentes_auditoria ?? [],
            'o eco do nosso próprio push não pode virar sugestão de nome novo'
        );
        $this->assertArrayNotHasKey('sobrenome', $vinculo->campos_pendentes_auditoria ?? []);
    }

    /**
     * O displayName continua sendo a fonte quando o contato veio de fora e o
     * Google não expõe givenName separado (contato digitado só com "nome
     * completo" num campo só) — o fallback não pode ser removido junto.
     */
    public function test_sem_given_name_ainda_cai_no_display_name(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['telefone' => '5521999992222', 'nome' => 'Sem Nome']);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c111',
                    'etag'         => 'etag-111',
                    'names'        => [['displayName' => 'Rodrigo Alves']],
                    'phoneNumbers' => [['value' => '5521999992222']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertSame('Rodrigo Alves', $contato->fresh()->nome);
    }

    /**
     * Achado Important da revisão de branch: a Task 3 trocou o loop antigo de
     * "preenche qualquer campo local vazio" pelo loop de resolverCampoGoogle()
     * sobre os 4 campos sincronizados. Contato NOVO seguia ganhando tudo via
     * Contato::create($dados), mas contato JÁ EXISTENTE parou de receber
     * profissão, endereço, aniversário, redes sociais etc.
     */
    public function test_contato_existente_ainda_recebe_campos_nao_sincronizados_quando_vazios(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999991111',
            'nome'      => 'Marcos Souza',
            'profissao' => null,
            'cidade'    => null,
        ]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName'  => 'people/c222',
                    'etag'          => 'etag-222',
                    'names'         => [['displayName' => 'Marcos Souza', 'givenName' => 'Marcos Souza']],
                    'phoneNumbers'  => [['value' => '5521999991111']],
                    'organizations' => [['title' => 'Motorista']],
                    'addresses'     => [['city' => 'Niterói']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Motorista', $contato->profissao);
        $this->assertSame('Niterói', $contato->cidade);
    }

    public function test_merge_de_campo_vazio_nunca_sobrescreve_valor_ja_preenchido(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999990101',
            'nome'      => 'Marcos Souza',
            'profissao' => 'Encanador',
        ]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName'  => 'people/c333',
                    'etag'          => 'etag-333',
                    'names'         => [['displayName' => 'Marcos Souza', 'givenName' => 'Marcos Souza']],
                    'phoneNumbers'  => [['value' => '5521999990101']],
                    'organizations' => [['title' => 'Motorista']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertSame('Encanador', $contato->fresh()->profissao);
    }

    /**
     * O middleName dos contatos que NÓS empurramos carrega o ID do banco
     * (GoogleService::criarContato) — importar isso de volta escreveria o
     * próprio ID interno no campo "nome do meio" do cadastro.
     */
    public function test_id_do_banco_no_middle_name_nao_vira_nome_do_meio(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'     => '5521999990202',
            'nome'         => 'Marcos Souza',
            'nome_do_meio' => null,
        ]);

        $this->fakePessoaComposta('5521999990202', 'Marcos 5000 Souza', 'Marcos Souza', 'Souza');

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertNull($contato->fresh()->nome_do_meio);
    }

    /**
     * Mesma armadilha do middleName, no outro campo: o endpoint legado
     * `atualizarGoogleSobrenome()` grava o ID do banco no familyName
     * (convenção antiga, anterior ao middleName). Sem essa guarda, rodar aquele
     * endpoint e depois sincronizar escreveria o ID interno no `sobrenome` de
     * cada contato do tenant.
     */
    public function test_id_do_banco_no_family_name_nao_vira_sobrenome(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999990303',
            'nome'      => 'Marcos Souza',
            'sobrenome' => null,
        ]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c444',
                    'etag'         => 'etag-444',
                    'names'        => [[
                        'displayName' => 'Marcos Souza 91234',
                        'givenName'   => 'Marcos Souza',
                        'familyName'  => '91234', // ID do banco, não sobrenome
                    ]],
                    'phoneNumbers' => [['value' => '5521999990303']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertNull($contato->fresh()->sobrenome);
    }

    private function fakePessoaComposta(string $telefone, string $displayName, string $givenName, string $familyName): void
    {
        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c987654321',
                    'etag'         => 'etag-999',
                    'names'        => [[
                        'displayName' => $displayName,
                        'givenName'   => $givenName,
                        'middleName'  => '5000',
                        'familyName'  => $familyName,
                    ]],
                    'phoneNumbers' => [['value' => $telefone]],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);
    }

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
