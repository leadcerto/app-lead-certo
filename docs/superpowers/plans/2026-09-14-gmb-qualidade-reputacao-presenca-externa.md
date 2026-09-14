# Reputação (Avaliações) + Presença Externa v2 (GMB Qualidade) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Calcular de verdade a categoria Reputação (volume, recorrência, nota + palavras-chave, qualidade de
resposta) a partir da API de reviews do Google, e refinar Presença Externa (site com página própria vs. home
genérica, WhatsApp e rede social cadastrados no `Tenant`).

**Architecture:** Novo método privado `GmbQualidadeService::buscarDadosReviews(GoogleToken $token, string
$locationId): array` busca reviews da API v4 (`accounts/{}/locations/{}/reviews`) uma vez por avaliação, sempre
depois de `buscarDadosLocation()` ter tido sucesso (cascata: sem location não tem reviews). `avaliarReputacao()`
calcula os 7 sub-critérios a partir do payload. `avaliarPresencaExterna()` ganha um segundo parâmetro (`PerfilGmb
$perfil`) pra ler `$perfil->tenant->whatsapp_phone`/redes sociais, além do `websiteUri` que já usava.

**Tech Stack:** Laravel 11, PHPUnit (`RefreshDatabase`), `Illuminate\Support\Facades\Http::fake()`, `Carbon`.

**Spec:** `docs/superpowers/specs/2026-09-13-gmb-qualidade-reputacao-presenca-externa-design.md`

## Global Constraints

- Endpoint de reviews: `GET https://mybusiness.googleapis.com/v4/{accountName}/locations/{locationId}/reviews`
  com query params `pageSize=20` e `orderBy=updateTime desc` — é a mesma família v4 já usada em
  `GmbPostPublishService`, precisa resolver `accountName` primeiro via
  `GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts` (usa `accounts[0].name`).
- A resposta de reviews já vem com `averageRating` (decimal 1-5) e `totalReviewCount` (int) prontos — nunca
  somar manualmente nem fazer chamada extra pra esses dois números.
- `buscarDadosLocation()` passa a devolver também `'token' => $token` e `'location_id' => $locationId` no caso
  de sucesso (chaves novas, aditivas — nenhum teste existente quebra por isso).
- Reputação só é calculada quando `buscarDadosLocation()` **e** `buscarDadosReviews()` têm sucesso; se qualquer
  um falhar, a categoria vira `categoriaErro('Reputação', $motivo)` — motivo de `buscarDadosLocation()` tem
  prioridade se os dois falharem.
- Palavras-chave (usadas nos sub-critérios de menção e nas respostas): categoria principal + categorias
  secundárias (`$dadosLocation['categories']`) + `$perfil->city`. Comparação via `mb_stripos($texto, $palavra)
  !== false` — case-insensitive, substring simples, sem normalização de acento (limitação de v1, documentada).
- `totalReviewCount === 0` é caso especial: nota 0, um único diagnóstico, pula os 4 sub-critérios normais.
- Cada sub-critério de Reputação (fora do caso especial) **sempre** gera um diagnóstico (`ok` quando ideal,
  senão `aviso`/`erro`) — nunca fica em silêncio quando passa (mesmo padrão já usado em Identidade/Localização).
- WhatsApp e rede social são campos do `Tenant` (`whatsapp_phone`, `instagram_url`, `facebook_url`,
  `youtube_url`, `linkedin_url`), **não campos da ficha do Google** — as mensagens de diagnóstico dizem
  "cadastrado no sistema", nunca "na ficha do Google". Sem `acao_url`/`acao_label` nesses dois diagnósticos —
  a única tela que edita esses campos (`admin.empresas.edit`) é `role:admin`, o Dono de um tenant cliente não
  tem acesso.
- "Página própria" de site: `parse_url($websiteUri, PHP_URL_PATH)` não vazio e diferente de `/`.
- Nenhuma mudança na view (`resources/views/gmb-qualidade/show.blade.php`) — o loop de diagnósticos e o gauge
  por categoria já são genéricos, funcionam pra Reputação sem alteração.
- Fora de escopo (não implementar): responder avaliação via API, paginar reviews além da primeira página (até
  20), normalização de acentuação nas palavras-chave, tela de autosserviço pro Dono editar WhatsApp/redes,
  sugestão de resposta por IA.

---

### Task 1: `buscarDadosReviews()` + `avaliarReputacao()` — categoria Reputação

**Files:**
- Modify: `app/Services/GmbQualidadeService.php:1-11` (imports), `:35-52` (match block em `avaliar()`),
  `:188-190` (retorno de sucesso de `buscarDadosLocation()`)
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: nada de tarefas anteriores (parte da fundação já em produção: `buscarDadosLocation()`,
  `categoriaErro()`, `CATEGORIAS_LABELS`, `GoogleToken`, `PerfilGmb`).
- Produces: `private function buscarDadosReviews(GoogleToken $token, string $locationId): array` (retorna
  `['sucesso' => true, 'dados' => array]` ou `['sucesso' => false, 'motivo' => string]`); `private function
  avaliarReputacao(array $dadosReviews, array $dadosLocation, PerfilGmb $perfil): array`; `private function
  avaliarReputacaoComReviews(PerfilGmb $perfil, array $location): array` (helper que encadeia os dois acima).
  `buscarDadosLocation()` passa a incluir `'token'` e `'location_id'` no array de sucesso — Task 2 não depende
  disso, mas qualquer código futuro pode reaproveitar.

- [ ] **Step 1: Escrever os testes que falham**

Adicione ao topo de `tests/Feature/GmbQualidadeServiceTest.php`, junto aos `use` já existentes, se ainda não
tiver:

```php
use Illuminate\Support\Carbon;
```

Adicione estes testes ao final da classe (antes do `}` de fechamento), reaproveitando `criarPerfilComGoogle()` e
`criarTokenGoogle()` já existentes no arquivo:

```php
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
```

Adicione também este teste, que documenta explicitamente a limitação de v1 aceita na spec (busca de
palavra-chave não normaliza acento — não é bug, é escopo):

```php
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
```

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — os 7 testes novos falham (método `buscarDadosReviews`/`avaliarReputacao` não existem ainda;
`reputacao` continua `pendente`).

- [ ] **Step 3: Adicionar o import de `Carbon` no service**

Em `app/Services/GmbQualidadeService.php`, no topo do arquivo (linha 9, logo após `use App\Models\Tenant;`),
adicione:

```php
use Carbon\Carbon;
```

- [ ] **Step 4: Fazer `buscarDadosLocation()` devolver o token e o location_id resolvidos**

Em `app/Services/GmbQualidadeService.php`, ache este trecho (linha 188-190):

```php
        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json()];
        }
```

Troque por:

```php
        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json(), 'token' => $token, 'location_id' => $locationId];
        }
```

- [ ] **Step 5: Implementar `buscarDadosReviews()`**

Adicione este método privado logo depois de `buscarDadosLocation()` (depois do fechamento `}` da linha 233,
antes de `private function avaliarIdentidade`):

```php
    private function buscarDadosReviews(GoogleToken $token, string $locationId): array
    {
        $accountRes = Http::withToken($token->access_token)
            ->timeout(15)
            ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

        if ($accountRes->status() === 429 || str_contains($accountRes->body(), 'Quota exceeded')) {
            Log::warning('Reviews API falhou (contas)', ['status' => 429, 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => 'Google retornou 429 (Quota excedida) ao buscar a conta para as avaliações. Tente novamente em alguns instantes.'];
        }

        if ($accountRes->status() === 403) {
            $erroGoogle = $accountRes->json('error.message') ?? $accountRes->body();
            $motivo = (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))
                ? 'A API "My Business Account Management API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/overview?project=159179119828'
                : 'Permissão do Google Meu Negócio pendente para ler as avaliações. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle;

            Log::warning('Reviews API falhou (contas)', ['status' => 403, 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => $motivo];
        }

        if (! $accountRes->successful()) {
            Log::warning('Reviews API falhou (contas)', ['status' => $accountRes->status(), 'response' => $accountRes->body()]);
            return ['sucesso' => false, 'motivo' => "Erro Google ({$accountRes->status()}) ao buscar a conta para as avaliações: " . ($accountRes->json('error.message') ?? $accountRes->body())];
        }

        $accountName = $accountRes->json('accounts.0.name');
        if (! $accountName) {
            return ['sucesso' => false, 'motivo' => 'Nenhuma conta do Google Meu Negócio encontrada para buscar as avaliações.'];
        }

        $res = Http::withToken($token->access_token)
            ->timeout(15)
            ->get("https://mybusiness.googleapis.com/v4/{$accountName}/locations/{$locationId}/reviews", [
                'pageSize' => 20,
                'orderBy'  => 'updateTime desc',
            ]);

        if ($res->successful()) {
            return ['sucesso' => true, 'dados' => $res->json()];
        }

        $status = $res->status();
        $erroGoogle = $res->json('error.message') ?? $res->body();

        Log::warning('Reviews API falhou', ['status' => $status, 'response' => $res->body()]);

        if ($status === 403 && (str_contains($erroGoogle, 'SERVICE_DISABLED') || str_contains($erroGoogle, 'has not been used in project'))) {
            return ['sucesso' => false, 'motivo' => 'A API "Google My Business API" precisa ser ativada no Google Cloud Console para ler as avaliações: https://console.developers.google.com/apis/api/mybusiness.googleapis.com/overview?project=159179119828'];
        }

        if ($status === 403) {
            return ['sucesso' => false, 'motivo' => 'Permissão do Google Meu Negócio pendente para ler as avaliações. Reconecte a conta Google em "Integrações". Detalhes: ' . $erroGoogle];
        }

        if ($status === 404) {
            return ['sucesso' => false, 'motivo' => "Google retornou 404 ao buscar avaliações: localização não encontrada para o ID '{$locationId}'."];
        }

        if ($status === 429 || str_contains($erroGoogle, 'Quota exceeded')) {
            return ['sucesso' => false, 'motivo' => 'Google retornou 429 (Quota excedida) ao buscar as avaliações. Tente novamente em alguns instantes.'];
        }

        return ['sucesso' => false, 'motivo' => "Erro Google ({$status}) ao buscar avaliações: {$erroGoogle}"];
    }
```

- [ ] **Step 6: Implementar `avaliarReputacao()` e o helper `avaliarReputacaoComReviews()`**

Adicione estes dois métodos privados logo depois de `buscarDadosReviews()` (antes de `avaliarIdentidade`):

```php
    private function avaliarReputacaoComReviews(PerfilGmb $perfil, array $location): array
    {
        $reviews = $this->buscarDadosReviews($location['token'], $location['location_id']);

        return $reviews['sucesso']
            ? $this->avaliarReputacao($reviews['dados'], $location['dados'], $perfil)
            : $this->categoriaErro(self::CATEGORIAS_LABELS['reputacao'], $reviews['motivo']);
    }

    private function avaliarReputacao(array $dadosReviews, array $dadosLocation, PerfilGmb $perfil): array
    {
        $totalReviews = $dadosReviews['totalReviewCount'] ?? 0;

        if ($totalReviews === 0) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['reputacao'],
                'diagnosticos' => [[
                    'tipo'       => 'erro',
                    'mensagem'   => 'Nenhuma avaliação registrada nesta ficha ainda. Comece a coletar avaliações de clientes reais.',
                    'acao_label' => 'Ver na Apostila',
                    'acao_url'   => route('admin.gmb-apostila.index') . '#pilares',
                ]],
            ];
        }

        $reviews = $dadosReviews['reviews'] ?? [];
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];
        $pontos = 0;
        $diagnosticos = [];

        // 1. Volume
        if ($totalReviews >= 50) {
            $pontos += 25;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$totalReviews} avaliações no total.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($totalReviews >= 10) {
            $pontos += 15;
            $diagnosticos[] = array_merge(['tipo' => 'aviso', 'mensagem' => "{$totalReviews} avaliações no total; o ideal é pelo menos 50."], $acaoManual);
        } else {
            $pontos += 5;
            $diagnosticos[] = array_merge(['tipo' => 'aviso', 'mensagem' => "Só {$totalReviews} avaliação(ões); o ideal é pelo menos 50."], $acaoManual);
        }

        // 2. Recorrência — max(createTime) entre os reviews retornados (pagina ordenada por updateTime desc,
        // ver spec pra por que nao confiamos em reviews[0] diretamente)
        $datasCriacao = array_filter(array_map(fn ($r) => $r['createTime'] ?? null, $reviews));
        $maisRecente = ! empty($datasCriacao) ? Carbon::parse(max($datasCriacao)) : null;
        $diasDesdeUltima = $maisRecente ? (int) $maisRecente->diffInDays(now()) : null;

        if ($diasDesdeUltima !== null && $diasDesdeUltima <= 7) {
            $pontos += 25;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Avaliação mais recente há {$diasDesdeUltima} dia(s) — dentro do ideal (a cada 7 dias).", 'acao_label' => null, 'acao_url' => null];
        } else {
            $textoData = $diasDesdeUltima !== null ? "{$diasDesdeUltima} dias" : 'muito tempo';
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Avaliação mais recente há {$textoData}. O Google valoriza recência mais que volume total — priorize pedir novas avaliações.", 'acao_label' => null, 'acao_url' => null];
        }

        // 3a. Nota média
        $notaMedia = (float) ($dadosReviews['averageRating'] ?? 0);
        $notaMediaFormatada = number_format($notaMedia, 1, ',', '');
        if ($notaMedia >= 4.5) {
            $pontos += 15;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Nota média {$notaMediaFormatada} — acima de 4.5.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($notaMedia >= 4.0) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Nota média {$notaMediaFormatada}; o ideal é 4.5 ou mais.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => "Nota média {$notaMediaFormatada} — abaixo de 4.0. Revise o atendimento antes de acelerar o volume de avaliações.", 'acao_label' => null, 'acao_url' => null];
        }

        // Palavras-chave: categoria principal + secundarias + cidade do perfil
        $palavrasChave = array_values(array_filter(array_merge(
            [$dadosLocation['categories']['primaryCategory']['displayName'] ?? null],
            array_column($dadosLocation['categories']['additionalCategories'] ?? [], 'displayName'),
            [$perfil->city]
        )));

        $contemPalavraChave = function (?string $texto) use ($palavrasChave): bool {
            if (empty($texto)) {
                return false;
            }
            foreach ($palavrasChave as $palavra) {
                if (mb_stripos($texto, $palavra) !== false) {
                    return true;
                }
            }
            return false;
        };

        // 3b. Menção a categoria/cidade nos comentários
        $totalAmostra = count($reviews);
        $comMencao = collect($reviews)->filter(fn ($r) => $contemPalavraChave($r['comment'] ?? null))->count();
        $percentualMencao = $totalAmostra > 0 ? (int) round(($comMencao / $totalAmostra) * 100) : 0;

        if ($percentualMencao >= 50) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$percentualMencao}% das avaliações recentes citam o serviço ou a cidade.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Só {$percentualMencao}% das avaliações recentes citam o serviço ou a cidade; estimule o cliente a mencionar o que foi feito e onde.", 'acao_label' => null, 'acao_url' => null];
        }

        // 4a. Taxa de resposta
        $respondidas = collect($reviews)->filter(fn ($r) => ! empty($r['reviewReply']))->values();
        $percentualRespondido = $totalAmostra > 0 ? (int) round(($respondidas->count() / $totalAmostra) * 100) : 0;

        if ($percentualRespondido >= 80) {
            $pontos += 10;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "{$percentualRespondido}% das avaliações recentes têm resposta do dono.", 'acao_label' => null, 'acao_url' => null];
        } elseif ($percentualRespondido >= 1) {
            $pontos += 5;
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Só {$percentualRespondido}% das avaliações recentes foram respondidas; o ideal é responder 100%.", 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => '0% das avaliações recentes foram respondidas; o ideal é responder 100%.', 'acao_label' => null, 'acao_url' => null];
        }

        // 4b. Prazo médio de resposta — só entre as respondidas
        if ($respondidas->isNotEmpty()) {
            $horasSoma = $respondidas->sum(function ($r) {
                $criada = Carbon::parse($r['createTime']);
                $respondida = Carbon::parse($r['reviewReply']['updateTime']);
                return $criada->diffInHours($respondida);
            });
            $horasMedia = (int) round($horasSoma / $respondidas->count());

            if ($horasMedia <= 48) {
                $pontos += 10;
                $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => "Tempo médio de resposta: {$horasMedia}h — dentro do ideal (até 48h).", 'acao_label' => null, 'acao_url' => null];
            } else {
                $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => "Tempo médio de resposta: {$horasMedia}h; o ideal é até 48h.", 'acao_label' => null, 'acao_url' => null];
            }
        } else {
            $diagnosticos[] = ['tipo' => 'erro', 'mensagem' => 'Nenhuma avaliação recente foi respondida. Responda o quanto antes — o ideal é em até 48h.', 'acao_label' => null, 'acao_url' => null];
        }

        // 4c. Termos nas respostas
        $respostaComTermo = $respondidas->contains(fn ($r) => $contemPalavraChave($r['reviewReply']['comment'] ?? null));

        if ($respostaComTermo) {
            $pontos += 5;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Pelo menos uma resposta recente cita o serviço ou a cidade.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => 'Nenhuma resposta recente cita o serviço ou a cidade — respostas genéricas perdem força. Cite o serviço e o nome do cliente quando possível.', 'acao_label' => null, 'acao_url' => null];
        }

        return [
            'nota'         => $pontos,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['reputacao'],
            'diagnosticos' => $diagnosticos,
        ];
    }
```

- [ ] **Step 7: Ligar Reputação em `avaliar()`**

Em `app/Services/GmbQualidadeService.php`, no `match` dentro de `avaliar()` (linhas 36-51), troque:

```php
                'saude_risco'      => $location['sucesso']
                    ? $this->avaliarSaudeRisco($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                default => $this->categoriaPendente($label),
```

Por:

```php
                'saude_risco'      => $location['sucesso']
                    ? $this->avaliarSaudeRisco($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
                'reputacao'        => $location['sucesso']
                    ? $this->avaliarReputacaoComReviews($perfil, $location)
                    : $this->categoriaErro($label, $location['motivo']),
                default => $this->categoriaPendente($label),
```

- [ ] **Step 8: Rodar os testes de novo**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS — todos os testes, incluindo os 7 novos e os já existentes (Identidade/Localização/Presença
Externa/Saúde-Risco/Atividade continuam intocados).

- [ ] **Step 9: Commit**

```bash
git add app/Services/GmbQualidadeService.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: calcula categoria Reputação (volume, recorrência, nota, respostas) via API de reviews"
```

---

### Task 2: `avaliarPresencaExterna()` v2 — página própria + WhatsApp + rede social

**Files:**
- Modify: `app/Services/GmbQualidadeService.php:44-46` (chamada em `avaliar()`), `:395-426`
  (`avaliarPresencaExterna()`)
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: `Tenant::CENTRAL_ID` não usado aqui, mas `PerfilGmb::tenant()` (relação já existente,
  `app/Models/PerfilGmb.php:34`) e os campos `Tenant.whatsapp_phone`/`instagram_url`/`facebook_url`/
  `youtube_url`/`linkedin_url` (já existem no model, `app/Models/Tenant.php`, nenhuma migration nova).
- Produces: `avaliarPresencaExterna(array $dados, PerfilGmb $perfil): array` — assinatura muda (ganha o segundo
  parâmetro). Nenhuma outra task depende dessa mudança.

- [ ] **Step 1: Escrever os testes que falham**

Adicione ao final de `tests/Feature/GmbQualidadeServiceTest.php` (antes do `}` de fechamento):

```php
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
```

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: FAIL — os 4 testes novos falham (assinatura de `avaliarPresencaExterna` ainda não aceita `$perfil`,
pontuação ainda é 0/100 sem os sub-critérios novos).

- [ ] **Step 3: Reescrever `avaliarPresencaExterna()`**

Em `app/Services/GmbQualidadeService.php`, troque o método inteiro (linhas 395-426):

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

Por:

```php
    private function avaliarPresencaExterna(array $dados, PerfilGmb $perfil): array
    {
        $website = $dados['websiteUri'] ?? '';
        $acaoManual = ['acao_label' => 'Abrir Google Business Profile Manager', 'acao_url' => 'https://business.google.com/'];
        $diagnosticos = [];
        $pontos = 0;

        if (! empty($website)) {
            $path = parse_url($website, PHP_URL_PATH);
            $paginaPropria = ! empty($path) && $path !== '/';

            if ($paginaPropria) {
                $pontos += 40;
                $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Site vinculado aponta para uma página própria (não a home genérica).', 'acao_label' => null, 'acao_url' => null];
            } else {
                $pontos += 25;
                $diagnosticos[] = array_merge([
                    'tipo'     => 'aviso',
                    'mensagem' => 'O site vinculado aponta para a página inicial, não para uma página própria desta localização. Uma página dedicada (com endereço, telefone e serviços desta unidade) vale mais para o Google e para o cliente.',
                ], $acaoManual);
            }
        } else {
            $diagnosticos[] = [
                'tipo'       => 'erro',
                'mensagem'   => 'Nenhum site cadastrado na ficha.',
                'acao_label' => 'Adicionar site no Google Business Profile Manager',
                'acao_url'   => 'https://business.google.com/',
            ];
        }

        if (! empty($perfil->tenant->whatsapp_phone)) {
            $pontos += 30;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'WhatsApp cadastrado no sistema.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => 'WhatsApp não cadastrado no sistema.', 'acao_label' => null, 'acao_url' => null];
        }

        $temRedeSocial = ! empty($perfil->tenant->instagram_url) || ! empty($perfil->tenant->facebook_url)
            || ! empty($perfil->tenant->youtube_url) || ! empty($perfil->tenant->linkedin_url);

        if ($temRedeSocial) {
            $pontos += 30;
            $diagnosticos[] = ['tipo' => 'ok', 'mensagem' => 'Pelo menos uma rede social cadastrada no sistema.', 'acao_label' => null, 'acao_url' => null];
        } else {
            $diagnosticos[] = ['tipo' => 'aviso', 'mensagem' => 'Nenhuma rede social cadastrada no sistema.', 'acao_label' => null, 'acao_url' => null];
        }

        $diagnosticos[] = [
            'tipo'       => 'info',
            'mensagem'   => 'Lembrete: adicione o Schema.org (LocalBusiness) no site vinculado para reforçar a ficha para o Google.',
            'acao_label' => 'Ver exemplo na Apostila',
            'acao_url'   => route('admin.gmb-apostila.index'),
        ];

        return [
            'nota'         => $pontos,
            'status'       => 'calculado',
            'label'        => self::CATEGORIAS_LABELS['presenca_externa'],
            'diagnosticos' => $diagnosticos,
        ];
    }
```

- [ ] **Step 4: Atualizar a chamada em `avaliar()`**

No mesmo arquivo, dentro do `match` de `avaliar()` (linhas 44-46), troque:

```php
                'presenca_externa' => $location['sucesso']
                    ? $this->avaliarPresencaExterna($location['dados'])
                    : $this->categoriaErro($label, $location['motivo']),
```

Por:

```php
                'presenca_externa' => $location['sucesso']
                    ? $this->avaliarPresencaExterna($location['dados'], $perfil)
                    : $this->categoriaErro($label, $location['motivo']),
```

- [ ] **Step 5: Rodar os testes de novo**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidadeServiceTest`
Expected: PASS — todos, incluindo os 4 novos.

Também rode o teste de integração já existente que cobre "perfil bom" com as 5 categorias (ele tinha
`websiteUri => 'https://freterio.com.br'`, sem path — vai passar a dar 25 pontos em vez de 100 em Presença
Externa, o que muda a `nota_geral` esperada nesse teste):

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=test_perfil_bom_com_todos_os_dados_ideais_calcula_5_categorias_com_nota_alta`
Expected: **FAIL** — esse teste antigo (de um sub-projeto anterior) assume Presença Externa = 100 com
`https://freterio.com.br`. Corrija o teste (não o código): ache o teste
`test_perfil_bom_com_todos_os_dados_ideais_calcula_5_categorias_com_nota_alta` em
`tests/Feature/GmbQualidadeServiceTest.php` e troque `'websiteUri' => 'https://freterio.com.br',` por
`'websiteUri' => 'https://freterio.com.br/barra-da-tijuca',` (dá página própria, mantém a intenção original do
teste de "tudo ideal = 100"). Rode de novo pra confirmar que passa.

- [ ] **Step 6: Commit**

```bash
git add app/Services/GmbQualidadeService.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: Presença Externa distingue página própria de home genérica + WhatsApp/rede social"
```

---

### Task 3: Verificação de suíte completa + teste de integração das 7 categorias juntas

**Files:**
- Modify: `tests/Feature/GmbQualidadeServiceTest.php` (novo teste de integração)

**Interfaces:**
- Consumes: tudo das Tasks 1 e 2.
- Produces: nada — task de verificação, sem interface nova.

- [ ] **Step 1: Escrever o teste de integração com as 7 categorias**

Adicione ao final de `tests/Feature/GmbQualidadeServiceTest.php`:

```php
    public function test_perfil_bom_com_7_categorias_ideais_calcula_nota_geral_alta(): void
    {
        $tenant = Tenant::factory()->create(['whatsapp_phone' => '21999998888', 'instagram_url' => 'https://instagram.com/freterio']);
        $perfil = $this->criarPerfilComGoogle($tenant);
        $this->criarTokenGoogle($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDay());

        $criadaHaUmDia = now()->subDay()->toIso8601String();

        $reviews = [];
        for ($i = 0; $i < 20; $i++) {
            $reviews[] = [
                'reviewId'    => "r{$i}",
                'comment'     => 'Transportadora excelente em Rio de Janeiro.',
                'createTime'  => $criadaHaUmDia,
                'updateTime'  => $criadaHaUmDia,
                'reviewReply' => ['comment' => 'Obrigado pela confiança na nossa transportadora!', 'updateTime' => now()->subDay()->addHours(5)->toIso8601String()],
            ];
        }

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
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/123']]], 200),
            'mybusiness.googleapis.com/v4/*/reviews*' => Http::response([
                'reviews'          => $reviews,
                'averageRating'    => 4.9,
                'totalReviewCount' => 60,
            ], 200),
        ]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['atividade']['nota']);
        $this->assertSame(100, $score->categorias['identidade']['nota']);
        $this->assertSame(100, $score->categorias['localizacao']['nota']);
        $this->assertSame(100, $score->categorias['presenca_externa']['nota']);
        $this->assertSame(100, $score->categorias['saude_risco']['nota']);
        $this->assertSame(100, $score->categorias['reputacao']['nota']);
        $this->assertSame('pendente', $score->categorias['conteudo']['status']);
        $this->assertSame(100, $score->nota_geral);

        Http::assertSentCount(3); // 1 location + 1 accounts + 1 reviews
    }
```

- [ ] **Step 2: Rodar esse teste isolado**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=test_perfil_bom_com_7_categorias_ideais_calcula_nota_geral_alta`
Expected: PASS

- [ ] **Step 3: Rodar todo o módulo GMB Qualidade**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test --filter=GmbQualidade`
Expected: PASS — todos os testes de `GmbQualidadeServiceTest`, `GmbQualidadeControllerTest`.

- [ ] **Step 4: Rodar a suíte completa do projeto**

Run: `"/c/Users/PICHAU/.config/herd/bin/php.bat" artisan test`
Expected: as mesmas falhas pré-existentes e não relacionadas já documentadas em commits anteriores (Kanban,
webhooks Uazapi/Covercut, sincronização de contatos, google-etiqueta, EmpresaController, ExampleTest — ~20
falhas + 2 erros) — nenhuma falha nova fora dessa lista conhecida.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/GmbQualidadeServiceTest.php
git commit -m "test: integração das 7 categorias juntas (Reputação + Presença Externa v2)"
```
