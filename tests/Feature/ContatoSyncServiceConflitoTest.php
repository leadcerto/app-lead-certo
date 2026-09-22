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
     * (GoogleService::criarContato) — importar isso de volta não pode deixar
     * escrever um valor arbitrário no campo "nome do meio" do cadastro local.
     * `Contato::nome_do_meio` é um accessor (2026-09-16, Contato::getNomeDoMeioAttribute)
     * que sempre devolve o próprio ID do contato, nunca lê/grava a coluna —
     * então mesmo que o sync tente escrever algo ali, o valor efetivo nunca muda.
     */
    public function test_id_do_banco_no_middle_name_nao_vira_nome_do_meio(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone' => '5521999990202',
            'nome'     => 'Marcos Souza',
        ]);

        $this->fakePessoaComposta('5521999990202', 'Marcos 5000 Souza', 'Marcos Souza', 'Souza');

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertSame((string) $contato->id, $contato->fresh()->nome_do_meio);
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

    /**
     * Achado real 2026-09-22 (pedido do Leonardo, aba "Conflitos de
     * Identidade": Google="Frete"/local="Jamal", Google="Frt"/local="Frt",
     * Google="Mdm"/local="Elisa Raquel" — sempre 0% de similaridade):
     * etiqueta comercial vinda do Google não é nome de pessoa (ver
     * AuditorController::isNaoPessoa). Quando o contato local já tem nome
     * real, não é número reciclado — empurra o nome real local pro Google em
     * vez de criar ContatoPendente. "os dois cadastros devem estar iguais".
     */
    public function test_tag_comercial_do_google_com_nome_real_local_empurra_nome_pro_google_em_vez_de_criar_conflito(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999995555',
            'nome'      => 'Jamal',
            'sobrenome' => null,
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c111222333',
                    'etag'         => 'etag-frete-1',
                    'names'        => [['givenName' => 'Frete']],
                    'phoneNumbers' => [['value' => '5521999995555']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
            '*:updateContact*' => Http::response(['resourceName' => 'people/c111222333'], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $this->assertSame(
            0,
            ContatoPendente::where('contato_existente_id', $contato->id)->count(),
            'etiqueta comercial vinda do Google não pode virar "número possivelmente reciclado" quando o local já tem nome real'
        );

        $contato->refresh();
        $this->assertSame('Jamal', $contato->nome); // nome local não muda

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertNotNull($vinculo);
        $this->assertSame('people/c111222333', $vinculo->google_resource_name);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'updateContact')
                && ($request['names'][0]['givenName'] ?? null) === 'Jamal';
        });
    }

    /**
     * Achado real 2026-09-22 (pedido do Leonardo, exemplos reais: "CVRG"/"Cvrg"
     * x90, "CLI"/"Cli" x11, "KENTEFRIO"/"Kentefrio"): confirmado em produção,
     * 247 de 295 pendências de 'sobrenome' eram diferença só de maiúscula/
     * minúscula — boa parte etiquetas internas em caixa alta que o Google
     * re-capitaliza sozinho ao sincronizar. "mantenha como está no cadastro":
     * não é conflito de verdade, não deve virar pendência de auditoria.
     */
    public function test_diferenca_so_de_maiuscula_minuscula_nao_gera_pendencia(): void
    {
        $vinculo = $this->vinculo(
            ['sobrenome' => 'CVRG'],
            ['campos_editados_humano' => ['sobrenome' => now()->toIso8601String()]]
        );

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'sobrenome', 'Cvrg');

        $vinculo->contato->refresh();
        $vinculo->refresh();
        $this->assertSame('CVRG', $vinculo->contato->sobrenome); // mantém como está no cadastro
        $this->assertArrayNotHasKey('sobrenome', $vinculo->campos_pendentes_auditoria ?? []);
        // linha de base atualiza mesmo assim, pra não reprocessar isso todo ciclo
        $this->assertSame('Cvrg', $vinculo->google_valores_enviados['sobrenome'] ?? null);
    }

    /**
     * Achado real 2026-09-22 (pedido do Leonardo, exemplo real: "Jaqueline Vaz"):
     * o padrão de cadastro predominante no sistema guarda o NOME COMPLETO num
     * campo só (`nome` = "Jaqueline Vaz", `sobrenome` vazio) — não split em
     * duas colunas. Quando o Google devolve o contato estruturado (givenName=
     * "Jaqueline", familyName="Vaz" — exatamente como GoogleService::
     * formatarNomeParaGoogle() os separou ao empurrar), a comparação de 'nome'
     * usava só o givenName ("Jaqueline") contra o "Jaqueline Vaz" local — um
     * FALSO conflito sugerindo cortar o sobrenome. Confirmado em produção:
     * 217 de 313 pendências reais de nome eram exatamente esse padrão.
     */
    public function test_nome_completo_local_com_sobrenome_vazio_nao_gera_falso_conflito(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999996666',
            'nome'      => 'Jaqueline Vaz',
            'sobrenome' => null,
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id'              => $contato->id,
            'tenant_id'               => $tenant->id,
            'google_resource_name'    => 'people/c987654321',
            'google_etag'             => 'etag-999',
            // SEM linha de base ainda (primeiro sync real pós-push) — com uma
            // linha de base igual ao givenName, resolverCampoGoogle() sai cedo
            // demais ("nada mudou") e o teste passaria mesmo com o bug intacto.
            'campos_editados_humano'  => ['nome' => now()->toIso8601String()],
        ]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c987654321',
                    'etag'         => 'etag-999',
                    'names'        => [[
                        'givenName'  => 'Jaqueline',
                        'middleName' => "[{$contato->id}]",
                        'familyName' => 'Vaz',
                    ]],
                    'phoneNumbers' => [['value' => '5521999996666']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Jaqueline Vaz', $contato->nome);

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayNotHasKey(
            'nome',
            $vinculo->campos_pendentes_auditoria ?? [],
            'givenName sozinho ("Jaqueline") não pode virar sugestão de corte contra o nome completo local'
        );
    }

    /**
     * Achado real 2026-09-22 (pedido do Leonardo, exemplo real: "Rosangela
     * Brito"): efeito colateral do mesmo padrão acima — sobrenome vazio local
     * + nome completo junto ("Rosangela Brito") fazia o sync AUTO-ACEITAR o
     * familyName do Google ("Brito") pro campo sobrenome separado (já que
     * "sobrenome vazio" = critério de auto-aceite em resolverCampoGoogle()),
     * duplicando o sobrenome dentro do próprio nome completo E no campo
     * sobrenome isolado.
     */
    public function test_sobrenome_nao_duplica_quando_ja_esta_embutido_no_nome_completo_local(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone'  => '5521999997777',
            'nome'      => 'Rosangela Brito',
            'sobrenome' => null,
        ]);

        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);
        VinculoContatoTenant::create([
            'contato_id'             => $contato->id,
            'tenant_id'              => $tenant->id,
            'google_resource_name'   => 'people/c987654321',
            'google_etag'            => 'etag-999',
            'campos_editados_humano' => ['nome' => now()->toIso8601String()],
        ]);

        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName' => 'people/c987654321',
                    'etag'         => 'etag-999',
                    'names'        => [[
                        'givenName'  => 'Rosangela',
                        'middleName' => "[{$contato->id}]",
                        'familyName' => 'Brito',
                    ]],
                    'phoneNumbers' => [['value' => '5521999997777']],
                ]],
                'nextSyncToken' => 'sync-token-xyz',
            ], 200),
        ]);

        $token = $this->criarToken($tenant);
        app(ContatoSyncService::class)->sincronizar($token, $tenant->id);

        $contato->refresh();
        $this->assertSame('Rosangela Brito', $contato->nome);
        $this->assertNull(
            $contato->sobrenome,
            'sobrenome não pode ser auto-preenchido com um pedaço que já está dentro do nome completo local'
        );
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
