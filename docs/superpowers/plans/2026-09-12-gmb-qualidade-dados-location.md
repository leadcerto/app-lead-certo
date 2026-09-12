# Análise de Qualidade da Ficha (GMB) — Dados de Location Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Calcular de verdade as categorias Identidade, Localização, Presença Externa e Saúde/Risco da Análise
de Qualidade da Ficha GMB, todas a partir de uma única chamada nova à Business Information API v1 do Google.

**Architecture:** Um novo método privado `GmbQualidadeService::buscarDadosLocation(PerfilGmb $perfil): array`
busca o payload da location uma única vez por chamada a `avaliar()` e retorna `['sucesso' => bool, 'dados' =>
array]` ou `['sucesso' => false, 'motivo' => string]`. Cada uma das 4 categorias novas recebe esse resultado —
nenhuma faz chamada própria. Quando a busca falha, a categoria fica com o novo `status: 'erro'` (distinto de
`pendente`), com o motivo real do Google no diagnóstico.

**Tech Stack:** Laravel 11, PHPUnit (`RefreshDatabase`), `Illuminate\Support\Facades\Http::fake()`.

**Spec:** `docs/superpowers/specs/2026-09-12-gmb-qualidade-dados-location-design.md`

## Global Constraints

- Endpoint: `GET https://mybusinessbusinessinformation.googleapis.com/v1/locations/{location_id}` com
  `readMask=categories,title,profile,storefrontAddress,serviceArea,websiteUri,regularHours,specialHours,phoneNumbers`
  (query param, não JSON body — é um GET).
- `name` do location é **`locations/{id}`**, sem prefixo `accounts/{account}` (diferente da v4 usada em
  `GmbPostPublishService`) — não existe passo de "buscar lista de contas" nesta integração.
- Token: `GoogleToken::withoutGlobalScopes()->where('tenant_id', $perfil->tenant_id)->first() ?? GoogleToken::withoutGlobalScopes()->first()` — mesmo padrão de `GmbPostPublishService::publicar()`. Renovar via
  `app(GoogleService::class)->renovarToken($token)` se `expires_at` no passado.
- Escopo OAuth `business.manage` já concedido — nenhuma reconexão de conta necessária.
- Todas as categorias somam exatamente 100 pontos quando todos os sub-critérios estão no ideal (ver tabelas
  na spec).
- `nota_geral` continua sendo a média apenas de categorias com `status === 'calculado'` — **nenhuma mudança**
  necessária nesse cálculo (`app/Services/GmbQualidadeService.php:36-42`, método `avaliar()`).
- Novo status `'erro'`: categoria tentou calcular mas a chamada ao Google falhou por motivo externo ao
  conteúdo da ficha. Fica fora da média, igual `'pendente'`. Diferença é só o texto do badge na view.
- Todas as URLs de ação manual (edição não suportada nesta v1) usam `https://business.google.com/` (Google
  Business Profile Manager) como `acao_url`.
- Testes de model/service usam `Tenant::factory()->create()` + `PerfilGmb::create([...])` — ver fixture
  `criarPerfil()` já existente em `tests/Feature/GmbQualidadeServiceTest.php`.
- Ao criar `GoogleToken` em testes, envolver com `Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class])`
  antes do `create()` (o model dispara esse job no evento `created`) — ver `tests/Feature/GoogleServiceRenovacaoTest.php:19-20`.

---

### Task 1: `buscarDadosLocation()` + status `erro` + categoria Identidade

**Files:**
- Modify: `app/Services/GmbQualidadeService.php`
- Modify: `resources/views/gmb-qualidade/show.blade.php:53-60`
- Modify: `tests/Feature/GmbQualidadeServiceTest.php` (atualiza 1 teste existente)
- Test: `tests/Feature/GmbQualidadeServiceTest.php` (novos testes)

**Interfaces:**
- Consumes: nada de tarefas anteriores (parte da fundação já em produção: `GmbQualidadeService::CATEGORIAS_LABELS`, `categoriaPendente()`, `avaliarAtividade()`, `GmbQualidadeScore`, `PerfilGmb`).
- Produces: `private function buscarDadosLocation(PerfilGmb $perfil): array` (retorna `['sucesso' => true, 'dados' => array]` ou `['sucesso' => false, 'motivo' => string]`); `private function categoriaErro(string $label, string $motivo): array`; `private function avaliarIdentidade(array $dados): array`. Tasks 2-4 consomem `buscarDadosLocation()` e `categoriaErro()` (não reimplementam a busca).

- [ ] **Step 1: Escrever os testes que falham (busca de location + categoria Identidade)**

Adicione ao topo de `tests/Feature/GmbQualidadeServiceTest.php` os imports que faltam e um helper de perfil com
location cadastrada:

```php
use App\Models\GoogleToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
```

Adicione este helper privado na classe (ao lado de `criarPerfil()`):

```php
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
```

Adicione os testes abaixo (substituindo o teste antigo indicado no Step 1b):

```php
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
```

- [ ] **Step 1b: Atualizar o teste de regressão existente**

O teste `test_categorias_ainda_nao_implementadas_ficam_pendentes_e_fora_da_media` (já existente no arquivo)
usa `criarPerfil()`, que **não** tem `google_location_id` — a partir deste task, `identidade` passa a ficar
`status: 'erro'` (não mais `'pendente'`) para esse perfil, já que a categoria agora existe e tenta calcular.
Troque a asserção de `identidade` para `conteudo` (categoria que **nenhum** task deste plano implementa —
continua genuinamente `'pendente'` do início ao fim, então este teste não precisa ser tocado de novo nos
próximos tasks) e adicione a verificação de `identidade`:

```php
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
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — `avaliarIdentidade`/`buscarDadosLocation`/`categoriaErro` ainda não existem; o teste de
regressão falha porque `identidade` ainda retorna `'pendente'`.

- [ ] **Step 3: Implementar `buscarDadosLocation()`, `categoriaErro()` e `avaliarIdentidade()`**

Em `app/Services/GmbQualidadeService.php`, adicione os imports no topo:

```php
use App\Models\GoogleToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
```

Substitua o método `avaliar()` inteiro por:

```php
    public function avaliar(PerfilGmb $perfil): GmbQualidadeScore
    {
        $categorias = [];
        $location = $this->buscarDadosLocation($perfil);

        foreach (self::CATEGORIAS_LABELS as $chave => $label) {
            $categorias[$chave] = match ($chave) {
                'atividade'  => $this->avaliarAtividade($perfil),
                'identidade' => $location['sucesso']
                    ? $this->avaliarIdentidade($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                default => $this->categoriaPendente($label),
            };
        }

        $notasCalculadas = collect($categorias)
            ->where('status', 'calculado')
            ->pluck('nota');

        $notaGeral = $notasCalculadas->isNotEmpty()
            ? (int) round($notasCalculadas->avg())
            : null;

        return GmbQualidadeScore::withoutGlobalScopes()->updateOrCreate(
            ['perfil_gmb_id' => $perfil->id],
            [
                'tenant_id'   => $perfil->tenant_id,
                'nota_geral'  => $notaGeral,
                'categorias'  => $categorias,
                'avaliado_em' => now(),
            ]
        );
    }
```

Adicione estes 3 novos métodos privados logo depois de `avaliarAtividade()` (antes de `categoriaPendente()`):

```php
    private function buscarDadosLocation(PerfilGmb $perfil): array
    {
        if (empty($perfil->google_location_id)) {
            return [
                'sucesso' => false,
                'motivo'  => "O perfil '{$perfil->nome}' não possui o 'ID do Perfil no Google' cadastrado. Acesse GMB → Perfis GMB, edite este perfil e preencha o ID da empresa no Google.",
            ];
        }

        $token = GoogleToken::withoutGlobalScopes()->where('tenant_id', $perfil->tenant_id)->first()
            ?? GoogleToken::withoutGlobalScopes()->first();

        if (! $token) {
            return [
                'sucesso' => false,
                'motivo'  => 'Nenhuma conta Google conectada encontrada. Acesse o menu "Integrações" e conecte a conta Google que gerencia os perfis GMB.',
            ];
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            app(GoogleService::class)->renovarToken($token);
            $token->refresh();
        }

        $locationId = preg_replace('#^locations/#', '', trim($perfil->google_location_id));

        $res = Http::withToken($token->access_token)
            ->timeout(15)
            ->get("https://mybusinessbusinessinformation.googleapis.com/v1/locations/{$locationId}", [
                'readMask' => 'categories,title,profile,storefrontAddress,serviceArea,websiteUri,regularHours,specialHours,phoneNumbers',
            ]);

        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json()];
        }

        $status = $res->status();
        $erroGoogle = $res->json('error.message') ?? $res->body();

        Log::warning('Business Information API falhou', [
            'perfil_id' => $perfil->id,
            'status'    => $status,
            'response'  => $res->body(),
        ]);

        if ($status === 403 && (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))) {
            return [
                'sucesso' => false,
                'motivo'  => 'A API "Business Information API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessbusinessinformation.googleapis.com/overview?project=159179119828',
            ];
        }

        if ($status === 403) {
            return [
                'sucesso' => false,
                'motivo'  => 'Permissão do Google Meu Negócio pendente para ler dados da ficha. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle,
            ];
        }

        if ($status === 404) {
            return [
                'sucesso' => false,
                'motivo'  => "Google retornou 404: Localização não encontrada para o ID '{$locationId}'. Verifique o ID do Perfil da Empresa em GMB → Perfis GMB.",
            ];
        }

        if ($status === 429 || str_contains($erroGoogle, 'Quota exceeded')) {
            return [
                'sucesso' => false,
                'motivo'  => 'Google retornou 429 (Quota excedida). Tente novamente em alguns instantes.',
            ];
        }

        return [
            'sucesso' => false,
            'motivo'  => "Erro Google ({$status}): {$erroGoogle}",
        ];
    }

    private function avaliarIdentidade(array $dados): array
    {
        $pontos = 0;
        $diagnosticos = [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];

        $categoriaPrimaria = $dados['categories']['primaryCategory']['displayName'] ?? null;
        if (! empty($categoriaPrimaria)) {
            $pontos += 40;
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'erro',
                'mensagem' => 'Categoria principal não definida na ficha. É o critério de ranqueamento mais importante do Google.',
            ], $acaoManual);
        }

        $secundarias = count($dados['categories']['additionalCategories'] ?? []);
        if ($secundarias >= 3 && $secundarias <= 5) {
            $pontos += 20;
        } elseif ($secundarias >= 1 && $secundarias <= 2) {
            $pontos += 10;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Você tem {$secundarias} categoria(s) secundária(s); o ideal é entre 3 e 5.",
            ], $acaoManual);
        } elseif ($secundarias >= 6) {
            $pontos += 15;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Você tem {$secundarias} categorias secundárias; o ideal é entre 3 e 5.",
            ], $acaoManual);
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => 'Nenhuma categoria secundária cadastrada; o ideal é entre 3 e 5.',
            ], $acaoManual);
        }

        $titulo = $dados['title'] ?? '';
        $temSeparadorSuspeito = str_contains($titulo, '|') || str_contains($titulo, '•')
            || str_contains($titulo, ':') || str_contains($titulo, ' - ');
        if (! $temSeparadorSuspeito) {
            $pontos += 20;
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "O nome da ficha parece conter termos extras além do nome real do negócio (ex: separadores como '|', '•', ':' ou ' - '). O Google pode suspender fichas com nome fora do padrão.",
            ], $acaoManual);
        }

        $descricao = $dados['profile']['description'] ?? '';
        $tamanhoDescricao = strlen($descricao);
        if ($tamanhoDescricao >= 150) {
            $pontos += 20;
        } elseif ($tamanhoDescricao >= 1) {
            $pontos += 10;
            $diagnosticos[] = array_merge([
                'tipo'     => 'aviso',
                'mensagem' => "Descrição com apenas {$tamanhoDescricao} caractere(s); o ideal é pelo menos 150.",
            ], $acaoManual);
        } else {
            $diagnosticos[] = array_merge([
                'tipo'     => 'erro',
                'mensagem' => 'Descrição ausente. Escreva uma descrição de pelo menos 150 caracteres direto no Google Business Profile Manager.',
            ], $acaoManual);
        }

        if (empty($diagnosticos)) {
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Categoria, categorias secundárias, nome e descrição bem preenchidos.', 'acao_label' => null, 'acao_url' => null];
        }

        return [
            'nota'         => $pontos,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['identidade'],
            'diagnosticos' => $diagnosticos,
        ];
    }

    private function categoriaErro(string $label, string $motivo): array
    {
        return [
            'nota'   => null,
            'status' => 'erro',
            'label'  => $label,
            'diagnosticos' => [[
                'tipo'       => 'erro',
                'mensagem'   => $motivo,
                'acao_label' => null,
                'acao_url'   => null,
            ]],
        ];
    }
```

`GmbQualidadeService` **não recebe construtor novo** — `buscarDadosLocation()` já resolve o `GoogleService` via
`app(GoogleService::class)->renovarToken($token)` diretamente (mesmo padrão usado em
`GmbPostPublishService::enviarParaGoogleApi()`), sem precisar de injeção de dependência na classe.

- [ ] **Step 4: Ajustar a view para o novo status `erro`**

Em `resources/views/gmb-qualidade/show.blade.php`, linha 53, troque:

```blade
                @if($categoria['status'] === 'pendente')
                    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">Em breve</span>
```

por:

```blade
                @if($categoria['status'] !== 'calculado')
                    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">{{ $categoria['status'] === 'erro' ? 'Indisponível' : 'Em breve' }}</span>
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS — todos os testes novos e o de regressão atualizado.

Run também: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeControllerTest`
Expected: PASS — nenhuma mudança de comportamento esperada nesses testes (perfil sem `google_location_id`
continua mostrando "Em breve" para as 5 categorias ainda pendentes).

- [ ] **Step 6: Commit**

```bash
git add app/Services/GmbQualidadeService.php resources/views/gmb-qualidade/show.blade.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: adiciona categoria Identidade via Business Information API (GMB Qualidade)"
```

---

### Task 2: Categoria Localização

**Files:**
- Modify: `app/Services/GmbQualidadeService.php`
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: `buscarDadosLocation()` e `categoriaErro()` do Task 1 (não reimplementa a busca); `$location['dados']` tem o mesmo formato retornado pela Business Information API (`storefrontAddress`, `serviceArea`).
- Produces: `private function avaliarLocalizacao(array $dados): array`. Nenhuma outra tarefa depende diretamente deste método.

- [ ] **Step 1: Escrever os testes que falham**

Adicione a `tests/Feature/GmbQualidadeServiceTest.php`:

```php
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
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['localizacao']['nota']);
        $this->assertSame('ok', $score->categorias['localizacao']['diagnosticos'][0]['tipo']);
    }

    public function test_localizacao_com_endereco_incompleto_lista_campos_faltantes(): void
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
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(40, $score->categorias['localizacao']['nota']);
        $this->assertStringContainsString('CEP', $score->categorias['localizacao']['diagnosticos'][0]['mensagem']);
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
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['localizacao']['nota']);
        $this->assertSame('erro', $score->categorias['localizacao']['diagnosticos'][0]['tipo']);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — `localizacao` ainda cai no `default => categoriaPendente($label)`, então o `status` retorna
`'pendente'` em vez de `'calculado'`/`'erro'` e as asserções de nota falham.

- [ ] **Step 3: Implementar `avaliarLocalizacao()`**

Em `app/Services/GmbQualidadeService.php`, adicione o método privado logo depois de `avaliarIdentidade()`:

```php
    private function avaliarLocalizacao(array $dados): array
    {
        $storefront = $dados['storefrontAddress'] ?? [];
        $serviceArea = $dados['serviceArea'] ?? [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];

        $temStorefront = ! empty($storefront['addressLines']) || ! empty($storefront['locality']);
        $temServiceArea = ! empty($serviceArea);

        if (! $temStorefront && ! $temServiceArea) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'erro',
                    'mensagem' => 'Nenhum endereço público nem área de atendimento configurados nesta ficha.',
                ], $acaoManual)],
            ];
        }

        if ($temStorefront) {
            $campos = [
                'addressLines'       => 'linhas de endereço',
                'locality'           => 'cidade',
                'administrativeArea' => 'estado',
                'postalCode'         => 'CEP',
                'regionCode'         => 'país',
            ];
            $pontos = 0;
            $faltando = [];
            foreach ($campos as $chave => $rotulo) {
                if (! empty($storefront[$chave])) {
                    $pontos += 20;
                } else {
                    $faltando[] = $rotulo;
                }
            }

            if (empty($faltando)) {
                return [
                    'nota'   => 100,
                    'status' => 'calculado',
                    'label'  => self::CATEGORIAS_LABELS['localizacao'],
                    'diagnosticos' => [['tipo' => 'ok', 'mensagem' => 'Endereço completo.', 'acao_label' => null, 'acao_url' => null]],
                ];
            }

            return [
                'nota'   => $pontos,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'aviso',
                    'mensagem' => 'Endereço incompleto — faltam: ' . implode(', ', $faltando) . '.',
                ], $acaoManual)],
            ];
        }

        $pontos = 0;
        $faltando = [];
        if (! empty($serviceArea['businessType'])) {
            $pontos += 50;
        } else {
            $faltando[] = 'tipo de negócio';
        }
        if (! empty($serviceArea['places']['placeInfos'])) {
            $pontos += 50;
        } else {
            $faltando[] = 'lista de áreas atendidas';
        }

        if (empty($faltando)) {
            return [
                'nota'   => 100,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['localizacao'],
                'diagnosticos' => [['tipo' => 'ok', 'mensagem' => 'Área de atendimento completa.', 'acao_label' => null, 'acao_url' => null]],
            ];
        }

        return [
            'nota'   => $pontos,
            'status' => 'calculado',
            'label'  => self::CATEGORIAS_LABELS['localizacao'],
            'diagnosticos' => [array_merge([
                'tipo'     => 'aviso',
                'mensagem' => 'Área de atendimento incompleta — faltam: ' . implode(', ', $faltando) . '.',
            ], $acaoManual)],
        ];
    }
```

Depois, no método `avaliar()`, troque a linha `'atividade'  => $this->avaliarAtividade($perfil),` mantendo-a, e
adicione logo abaixo do `'identidade' => ...` (antes do `default`):

```php
                'localizacao' => $location['sucesso']
                    ? $this->avaliarLocalizacao($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GmbQualidadeService.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: adiciona categoria Localização (GMB Qualidade)"
```

---

### Task 3: Categoria Presença Externa

**Files:**
- Modify: `app/Services/GmbQualidadeService.php`
- Modify: `resources/views/gmb-qualidade/show.blade.php` (estilo do diagnóstico tipo `info`)
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: `buscarDadosLocation()`/`categoriaErro()` do Task 1; `route('admin.gmb-apostila.index')` (rota já existente, ver `routes/gmb-web.php`).
- Produces: `private function avaliarPresencaExterna(array $dados): array`. Introduz o diagnóstico `tipo: 'info'` — usado depois também pela categoria Saúde/Risco (Task 4), que reaproveita o mesmo estilo de view sem precisar mexer nela de novo.

- [ ] **Step 1: Escrever os testes que falham**

```php
    public function test_presenca_externa_com_site_cadastrado_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(['websiteUri' => 'https://freterio.com.br'], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['presenca_externa']['nota']);
        $this->assertSame('ok', $score->categorias['presenca_externa']['diagnosticos'][0]['tipo']);
        $this->assertSame('info', $score->categorias['presenca_externa']['diagnosticos'][1]['tipo']);
    }

    public function test_presenca_externa_sem_site_fica_zerada_mas_sempre_lembra_do_schema_org(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['presenca_externa']['nota']);
        $this->assertCount(2, $score->categorias['presenca_externa']['diagnosticos']);
        $this->assertStringContainsString('Schema.org', $score->categorias['presenca_externa']['diagnosticos'][1]['mensagem']);
        $this->assertNotNull($score->categorias['presenca_externa']['diagnosticos'][1]['acao_url']);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — `presenca_externa` ainda cai no `default => categoriaPendente($label)`.

- [ ] **Step 3: Implementar `avaliarPresencaExterna()`**

Adicione o método privado logo depois de `avaliarLocalizacao()`:

```php
    private function avaliarPresencaExterna(array $dados): array
    {
        $website = $dados['websiteUri'] ?? '';
        $diagnosticos = [];

        if (! empty($website)) {
            $nota = 100;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Site vinculado à ficha.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $nota = 0;
            $diagnosticos[] = [
                'tipo'       => 'erro',
                'mensagem'   => 'Nenhum site cadastrado na ficha.',
                'acao_label' => 'Adicionar site no Google Business Profile Manager',
                'acao_url'   => 'https://business.google.com/',
            ];
        }

        $diagnosticos[] = [
            'tipo'       => 'info',
            'mensagem'   => 'Lembrete: adicione o Schema.org (LocalBusiness) no site vinculado para reforçar a ficha para o Google.',
            'acao_label' => 'Ver exemplo na Apostila',
            'acao_url'   => route('admin.gmb-apostila.index'),
        ];

        return [
            'nota'         => $nota,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['presenca_externa'],
            'diagnosticos' => $diagnosticos,
        ];
    }
```

No método `avaliar()`, adicione o novo `match` arm (antes do `default`):

```php
                'presenca_externa' => $location['sucesso']
                    ? $this->avaliarPresencaExterna($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
```

- [ ] **Step 4: Adicionar estilo visual pro diagnóstico tipo `info` na view**

Em `resources/views/gmb-qualidade/show.blade.php`, o `match($diag['tipo'])` (linha ~65) hoje é:

```blade
                    $corDiag = match($diag['tipo']) {
                        'ok' => 'border-green-400 text-gray-700',
                        'aviso' => 'border-amber-400 text-gray-700',
                        'erro' => 'border-red-400 text-gray-700',
                        default => 'border-gray-300 text-gray-500',
                    };
```

Troque por (adiciona o caso `'info'` explícito):

```blade
                    $corDiag = match($diag['tipo']) {
                        'ok' => 'border-green-400 text-gray-700',
                        'aviso' => 'border-amber-400 text-gray-700',
                        'erro' => 'border-red-400 text-gray-700',
                        'info' => 'border-blue-400 text-gray-700',
                        default => 'border-gray-300 text-gray-500',
                    };
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/GmbQualidadeService.php resources/views/gmb-qualidade/show.blade.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: adiciona categoria Presença Externa (GMB Qualidade)"
```

---

### Task 4: Categoria Saúde/Risco

**Files:**
- Modify: `app/Services/GmbQualidadeService.php`
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: `buscarDadosLocation()`/`categoriaErro()` do Task 1; estilo `info` da view já adicionado no Task 3 (nenhuma mudança de view necessária aqui).
- Produces: `private function avaliarSaudeRisco(array $dados): array`. Última categoria deste sub-projeto — depois dela só `conteudo` e `reputacao` continuam `pendente` (sub-projetos futuros).

- [ ] **Step 1: Escrever os testes que falham**

```php
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
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['saude_risco']['nota']);
        $this->assertSame('erro', $score->categorias['saude_risco']['diagnosticos'][0]['tipo']);
        $this->assertCount(2, $score->categorias['saude_risco']['diagnosticos']);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — `saude_risco` ainda cai no `default => categoriaPendente($label)`.

- [ ] **Step 3: Implementar `avaliarSaudeRisco()`**

Adicione o método privado logo depois de `avaliarPresencaExterna()`:

```php
    private function avaliarSaudeRisco(array $dados): array
    {
        $periods = $dados['regularHours']['periods'] ?? [];
        $diagnosticos = [];

        if (! empty($periods)) {
            $nota = 100;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Horário de funcionamento cadastrado.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $nota = 0;
            $diagnosticos[] = [
                'tipo'       => 'erro',
                'mensagem'   => 'Horário de funcionamento não cadastrado na ficha.',
                'acao_label' => 'Abrir Google Business Profile Manager',
                'acao_url'   => 'https://business.google.com/',
            ];
        }

        $diagnosticos[] = [
            'tipo'       => 'info',
            'mensagem'   => 'Verifique periodicamente se há edições sugeridas por terceiros pendentes de revisão no Google Business Profile Manager.',
            'acao_label' => 'Abrir Google Business Profile Manager',
            'acao_url'   => 'https://business.google.com/',
        ];

        return [
            'nota'         => $nota,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['saude_risco'],
            'diagnosticos' => $diagnosticos,
        ];
    }
```

No método `avaliar()`, adicione o último `match` arm (antes do `default`):

```php
                'saude_risco' => $location['sucesso']
                    ? $this->avaliarSaudeRisco($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
```

O `match` final deve ficar assim (referência completa, para conferência):

```php
            $categorias[$chave] = match ($chave) {
                'atividade'        => $this->avaliarAtividade($perfil),
                'identidade'       => $location['sucesso'] ? $this->avaliarIdentidade($location['dados']) : $this->categoriaErro($label, $location['motivo']),
                'localizacao'      => $location['sucesso'] ? $this->avaliarLocalizacao($location['dados']) : $this->categoriaErro($label, $location['motivo']),
                'presenca_externa' => $location['sucesso'] ? $this->avaliarPresencaExterna($location['dados']) : $this->categoriaErro($label, $location['motivo']),
                'saude_risco'      => $location['sucesso'] ? $this->avaliarSaudeRisco($location['dados']) : $this->categoriaErro($label, $location['motivo']),
                default => $this->categoriaPendente($label),
            };
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GmbQualidadeService.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: adiciona categoria Saúde/Risco (GMB Qualidade)"
```

---

### Task 5: Verificação de suíte completa + teste de integração das 4 categorias juntas

**Files:**
- Test: `tests/Feature/GmbQualidadeServiceTest.php`
- Test: `tests/Feature/GmbQualidadeControllerTest.php`

**Interfaces:**
- Consumes: todos os métodos das Tasks 1-4 (`avaliarIdentidade`, `avaliarLocalizacao`, `avaliarPresencaExterna`, `avaliarSaudeRisco`, `buscarDadosLocation`, `categoriaErro`).
- Produces: nada consumido por tarefas futuras — este é o task de fechamento deste sub-projeto.

- [ ] **Step 1: Escrever o teste de integração (perfil "bom" e perfil "incompleto" nas 4 categorias de uma vez)**

Adicione a `tests/Feature/GmbQualidadeServiceTest.php`:

```php
    public function test_perfil_bom_com_todos_os_dados_ideais_calcula_5_categorias_com_nota_alta(): void
    {
        $tenant = Tenant::factory()->create();
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
                'websiteUri'   => 'https://freterio.com.br',
                'regularHours' => ['periods' => [['openDay' => 'MONDAY', 'openTime' => '09:00', 'closeDay' => 'MONDAY', 'closeTime' => '18:00']]],
            ], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['atividade']['nota']);
        $this->assertSame(100, $score->categorias['identidade']['nota']);
        $this->assertSame(100, $score->categorias['localizacao']['nota']);
        $this->assertSame(100, $score->categorias['presenca_externa']['nota']);
        $this->assertSame(100, $score->categorias['saude_risco']['nota']);
        $this->assertSame('pendente', $score->categorias['conteudo']['status']);
        $this->assertSame('pendente', $score->categorias['reputacao']['status']);
        $this->assertSame(100, $score->nota_geral);
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
```

Adicione a `tests/Feature/GmbQualidadeControllerTest.php`, dentro da classe:

```php
    public function test_view_mostra_indisponivel_quando_categoria_fica_em_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant); // sem google_location_id -> identidade/localizacao/etc ficam 'erro'

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");

        $response->assertOk();
        $response->assertSee('Indisponível');
        $response->assertSee('Em breve'); // conteudo/reputacao continuam pendentes
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que passam**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeControllerTest`
Expected: PASS em ambos.

- [ ] **Step 3: Rodar a suíte completa do projeto**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test`
Expected: mesmas falhas pré-existentes de antes deste sub-projeto (Kanban/webhooks/contatos/google-etiqueta,
já confirmadas não relacionadas ao módulo GMB Qualidade), nenhuma falha nova introduzida por este plano.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/GmbQualidadeServiceTest.php tests/Feature/GmbQualidadeControllerTest.php
git commit -m "test: adiciona testes de integração das 4 categorias de Location (GMB Qualidade)"
```

---

## Próximos planos

- **Conteúdo** — quantidade de fotos (`accounts/{account}/locations/{location}/media`, precisa da lista de
  contas como em `GmbPostPublishService`), catálogo de produtos/serviços, atributos (`locations.getAttributes`).
  Chamadas novas, sub-projeto próprio.
- **Reputação** — `accounts/{account}/locations/{location}/reviews` (v4): volume, nota média, avaliação nova
  nos últimos 30 dias, taxa de resposta do dono (`reviewReply` presente ou não). Sub-projeto próprio.
- Ajustar pesos das categorias se, com uso real, algum critério se mostrar desproporcional aos demais.
