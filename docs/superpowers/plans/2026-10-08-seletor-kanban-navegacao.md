# Seletor de Kanban na Navegação (Fase A — MVP) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar à barra lateral e aos 7 controllers de configuração do Kanban (hoje presos a
"o Kanban" implícito, `tipo='vendas'`) a capacidade de operar sobre QUALQUER Kanban do tenant,
via um parâmetro `?kanban_id=` opcional e retrocompatível — e permitir criar um Kanban novo
pela própria tela, sem precisar de script manual.

**Architecture:** Um trait pequeno (`ResolveKanbanDoRequest`) centraliza a resolução de "qual
Kanban" (query param `kanban_id`, com fallback pro Kanban `tipo='vendas'` — comportamento de
hoje, zero regressão). Cada controller passa a usar essa resolução em vez da query fixa.
Endpoint novo cria um Kanban vazio (sem colunas — reaproveita a tela de Configurações já
existente pra isso). Barra lateral vira N blocos colapsáveis (um por Kanban), só com
Atendimentos/Configurações dentro — o resto (Variáveis, Motivos, Relatórios, Documentação,
Especificações) continua compartilhado nesta fase, por decisão explícita do Leonardo.

**Tech Stack:** Laravel 11 (PHP), Alpine.js, PHPUnit, Playwright (verificação manual), MySQL.

**Spec:** `docs/superpowers/specs/2026-10-08-seletor-kanban-navegacao-design.md`

## Global Constraints

- Suíte completa sem regressão além da baseline de 17 falhas pré-existentes.
- Todo endpoint que hoje assume `tipo='vendas'` sem `?kanban_id=` deve continuar resolvendo
  exatamente o mesmo Kanban de antes — zero regressão pra tenants com 1 Kanban só.
- Commits em português, terminando com `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Deploy em produção só depois de TODAS as tasks passarem, com confirmação explícita do
  Leonardo antes — e com verificação manual no navegador (Playwright) antes do deploy, já
  que as tasks de frontend (9-10) não têm cobertura de teste automatizado de backend.

## Review Focus

- `KanbanColunaConfigController`/`KanbanColunaObjetivoController` resolvem coluna por `chave`
  string sozinha hoje — com 2 Kanbans usando a mesma `chave` (improvável com a convenção
  `funil_`, mas possível), a correção precisa desambiguar pelo `kanban_id` resolvido, não só
  confiar na string.
- Criar um 2º Kanban com nome curto que gera o mesmo `tipo` slug de um Kanban existente do
  mesmo tenant não pode quebrar com erro 500 nem sobrescrever o Kanban existente.
- `?kanban_id=` apontando pra um Kanban de OUTRO tenant não pode vazar dado — o filtro por
  `tenant_id` tem que prevalecer sempre (findOrFail já escopado por tenant via TenantScope,
  mas confirmar isso explicitamente em teste, não só confiar no global scope).
- A barra lateral, com 0 Kanbans adicionais (caso de hoje, só o `tipo='vendas'`), precisa
  continuar exatamente igual visualmente ao que já existe — ninguém deve notar diferença até
  criar um 2º Kanban de propósito.
- O botão "+" só deve aparecer pra quem tem permissão de admin/dono (mesma regra já aplicada
  nas rotas de configuração de coluna) — não pra qualquer usuário.

---

## Task 1: Migration — `nome_curto` em `kanbans` + backfill + fillable

**Files:**
- Create: `database/migrations/2026_10_08_000002_add_nome_curto_to_kanbans_table.php`
- Modify: `app/Models/Kanban.php:19-25` (fillable)
- Test: `tests/Feature/KanbanNomeCurtoTest.php`

**Interfaces:**
- Produces: `Kanban.nome_curto` (nullable string(20)), fillable — usado pelas tasks seguintes.

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanNomeCurtoTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_tem_nome_curto_fillable(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $kanban->update(['nome_curto' => 'Imóveis']);

        $this->assertSame('Imóveis', $kanban->fresh()->nome_curto);
    }

    public function test_backfill_preenche_nome_curto_do_kanban_vendas_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->assertSame('Atendimentos', $kanban->nome_curto);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanNomeCurtoTest`
Expected: FAIL — `nome_curto` não existe (erro de coluna desconhecida / `nome_curto` sempre
null porque não é fillable ainda).

- [ ] **Step 3: Criar a migration**

```php
<?php

use App\Models\Kanban;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->string('nome_curto', 20)->nullable()->after('nome');
        });

        // Backfill: todo Kanban tipo='vendas' já existente ganha o rótulo que a
        // barra lateral sempre mostrou pra ele até agora — zero mudança visível.
        Kanban::where('tipo', 'vendas')->whereNull('nome_curto')->update(['nome_curto' => 'Atendimentos']);
    }

    public function down(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->dropColumn('nome_curto');
        });
    }
};
```

- [ ] **Step 4: Adicionar `nome_curto` ao fillable do model**

Em `app/Models/Kanban.php`, adicionar `'nome_curto',` logo depois de `'nome',` no array
`$fillable` (linha ~22).

- [ ] **Step 5: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanNomeCurtoTest`
Expected: PASS (2 testes).

- [ ] **Step 6: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline de 17 falhas, zero regressão.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_08_000002_add_nome_curto_to_kanbans_table.php app/Models/Kanban.php tests/Feature/KanbanNomeCurtoTest.php
git commit -m "feat: adiciona nome_curto ao Kanban, com backfill

Campo pra exibir na barra lateral (curto, sem espaço) — o nome
completo continua existindo pra relatórios/títulos. Backfill mantém
'Atendimentos' pro Kanban tipo=vendas, zero mudança visível hoje.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: Trait `ResolveKanbanDoRequest` + aplicar no `KanbanColunaController`

**Files:**
- Create: `app/Http/Controllers/Painel/Concerns/ResolveKanbanDoRequest.php`
- Modify: `app/Http/Controllers/Painel/KanbanColunaController.php` (métodos `index`, `store`,
  `update`, `destroy`, `reordenar` — `papeis()` não usa Kanban, não precisa mudar)
- Test: `tests/Feature/KanbanColunaControllerKanbanIdTest.php`

**Interfaces:**
- Produces: trait com `protected function resolverKanban(Request $request): Kanban` — usado
  por todas as tasks seguintes (3-7).

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    private function criarKanbanFunil(Tenant $tenant): Kanban
    {
        return Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
    }

    public function test_index_sem_kanban_id_continua_retornando_as_colunas_do_kanban_vendas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $this->criarKanbanFunil($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas');

        $response->assertOk();
        $chaves = collect($response->json())->pluck('chave')->all();
        $this->assertContains('lead_novo', $chaves);
        $this->assertNotContains('funil_novo_lead', $chaves);
    }

    public function test_index_com_kanban_id_retorna_as_colunas_do_kanban_certo(): void
    {
        $tenant      = Tenant::factory()->create();
        $user        = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = $this->criarKanbanFunil($tenant);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_novo_lead', 'label' => 'Novo Lead',
            'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $chaves = collect($response->json())->pluck('chave')->all();
        $this->assertSame(['funil_novo_lead'], $chaves);
    }

    public function test_kanban_id_de_outro_tenant_nao_vaza_colunas(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenantA->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanDoB = Kanban::where('tenant_id', $tenantB->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanDoB->id);

        $response->assertStatus(404);
    }

    public function test_store_cria_coluna_no_kanban_informado(): void
    {
        $tenant      = Tenant::factory()->create();
        $user        = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = $this->criarKanbanFunil($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas?kanban_id=' . $kanbanFunil->id, [
            'label' => 'Nova Coluna', 'papel' => 'em_andamento',
        ]);

        $response->assertStatus(201);
        $this->assertSame($kanbanFunil->id, KanbanColuna::findOrFail($response->json('id'))->kanban_id);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanColunaControllerKanbanIdTest`
Expected: FAIL nos testes 2, 3 e 4 (hoje tudo cai sempre no Kanban `tipo='vendas'`,
ignorando `kanban_id`; o teste 1 já passa, confirma que não há regressão na omissão do
parâmetro).

- [ ] **Step 3: Criar o trait**

```php
<?php

namespace App\Http\Controllers\Painel\Concerns;

use App\Models\Kanban;
use Illuminate\Http\Request;

trait ResolveKanbanDoRequest
{
    protected function resolverKanban(Request $request): Kanban
    {
        $tenantId = $request->user()->tenant_id;
        $kanbanId = $request->query('kanban_id');

        if ($kanbanId) {
            return Kanban::where('tenant_id', $tenantId)->findOrFail($kanbanId);
        }

        return Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();
    }
}
```

- [ ] **Step 4: Aplicar no `KanbanColunaController`**

Em `app/Http/Controllers/Painel/KanbanColunaController.php`:
1. Adicionar `use App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;` e
   `use ResolveKanbanDoRequest;` dentro da classe.
2. No `index()`, trocar a resolução do Kanban (onde hoje busca `tipo='vendas'` — conferir a
   linha exata lendo o método, o grep anterior mostrou a lógica em `store()` na linha 54 como
   referência do padrão) por `$kanban = $this->resolverKanban($request);`.
3. Mesma troca em `store()` (linha ~54), `update()`, `destroy()`, `reordenar()` — todos
   recebem `Request $request`, então `$this->resolverKanban($request)` funciona em todos.
4. `papeis()` não mexe com Kanban nenhum (só lista o enum `PapelColunaKanban`) — não precisa
   de mudança.

- [ ] **Step 5: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanColunaControllerKanbanIdTest`
Expected: PASS (4 testes).

- [ ] **Step 6: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline, zero regressão (atenção especial aqui — `KanbanColunaController` é
usado pela tela de Configurações que já tem testes extensos, ex.: `KanbanColunaControllerTest.php`).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/Concerns/ResolveKanbanDoRequest.php app/Http/Controllers/Painel/KanbanColunaController.php tests/Feature/KanbanColunaControllerKanbanIdTest.php
git commit -m "feat: KanbanColunaController aceita ?kanban_id= pra operar em qualquer Kanban do tenant

Trait ResolveKanbanDoRequest centraliza a resolução — retrocompatível,
omitir o parâmetro continua resolvendo o Kanban tipo=vendas de hoje.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `KanbanCanalController` aceita `?kanban_id=`

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanCanalController.php` (métodos `index`, `update`)
- Test: `tests/Feature/KanbanCanalControllerKanbanIdTest.php`

**Interfaces:**
- Consumes: `ResolveKanbanDoRequest` (Task 2).

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_vincula_canal_no_kanban_informado_por_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        $canal = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais?kanban_id=' . $kanbanFunil->id, [
            'canal_ids' => [$canal->id],
        ]);

        $response->assertOk();
        $this->assertTrue($kanbanFunil->fresh()->canais()->where('whatsapp_canais.id', $canal->id)->exists());

        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertFalse($kanbanGeral->canais()->where('whatsapp_canais.id', $canal->id)->exists());
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanCanalControllerKanbanIdTest`
Expected: FAIL — o canal é vinculado ao Kanban `tipo='vendas'`, não ao `$kanbanFunil`.

- [ ] **Step 3: Aplicar o trait**

Em `app/Http/Controllers/Painel/KanbanCanalController.php`, adicionar `use
App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;` + `use
ResolveKanbanDoRequest;`, e em `index()` e `update()` trocar:

```php
$kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();
```

por:

```php
$kanban   = $this->resolverKanban($request);
```

(`$tenantId` ainda é usado separadamente em `update()` pra validar os `canal_ids` — manter
essa linha, só trocar a resolução do `$kanban`.)

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanCanalControllerKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanCanalController.php tests/Feature/KanbanCanalControllerKanbanIdTest.php
git commit -m "feat: KanbanCanalController aceita ?kanban_id= pra operar em qualquer Kanban do tenant

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: `KanbanInfoController` aceita `?kanban_id=`

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanInfoController.php` (métodos `show`, `update`)
- Test: `tests/Feature/KanbanInfoControllerKanbanIdTest.php`

**Interfaces:**
- Consumes: `ResolveKanbanDoRequest` (Task 2).

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanInfoControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_com_kanban_id_retorna_nome_do_kanban_certo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $response->assertJson(['nome' => 'Funil de Qualificação']);
    }

    public function test_update_com_kanban_id_atualiza_o_kanban_certo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $this->actingAs($user)->putJson('/api/painel/kanban/info?kanban_id=' . $kanbanFunil->id, [
            'nome' => 'Funil Renomeado',
        ])->assertOk();

        $this->assertSame('Funil Renomeado', $kanbanFunil->fresh()->nome);
        $this->assertNotSame('Funil Renomeado', $kanbanGeral->fresh()->nome);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanInfoControllerKanbanIdTest`
Expected: FAIL.

- [ ] **Step 3: Aplicar o trait**

Em `app/Http/Controllers/Painel/KanbanInfoController.php`, mesma troca das tasks anteriores
em `show()` (linha ~14-16) e `update()` (linha ~39-41).

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanInfoControllerKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanInfoController.php tests/Feature/KanbanInfoControllerKanbanIdTest.php
git commit -m "feat: KanbanInfoController aceita ?kanban_id= pra operar em qualquer Kanban do tenant

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: `KanbanColunaConfigController` aceita `?kanban_id=` e desambigua por `kanban_id`+`chave`

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php` (métodos `show`,
  `update`)
- Test: `tests/Feature/KanbanColunaConfigControllerKanbanIdTest.php`

**Interfaces:**
- Consumes: `ResolveKanbanDoRequest` (Task 2).

**Nota importante**: diferente das tasks anteriores, aqui a correção é mais funda — hoje
`KanbanColunaConfig::where('coluna_kanban', $coluna)` resolve só pela `chave` string, sem
nenhum filtro de Kanban. Precisa resolver a `KanbanColuna` real primeiro (por `kanban_id` +
`chave`), e só então buscar/gravar o `KanbanColunaConfig` correspondente.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_com_kanban_id_grava_config_no_kanban_certo_mesmo_com_chave_repetida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        // Chave DELIBERADAMENTE igual nos 2 Kanbans — o pior caso de ambiguidade.
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'em_atendimento', 'label' => 'Em Atendimento (funil)',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 1,
        ]);

        $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento?kanban_id=' . $kanbanFunil->id, [
            'objetivo' => 'Objetivo só do funil',
        ])->assertOk();

        $colunaFunilReal = KanbanColuna::where('kanban_id', $kanbanFunil->id)->where('chave', 'em_atendimento')->firstOrFail();
        $configFunil = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('kanban_coluna_id', $colunaFunilReal->id)
            ->first();
        $this->assertNotNull($configFunil);
        $this->assertSame('Objetivo só do funil', $configFunil->objetivo);

        // O Kanban geral (mesma chave 'em_atendimento') não pode ter sido afetado.
        $colunaGeralReal = KanbanColuna::where('tenant_id', $tenant->id)->where('kanban_id', $kanbanGeral->id)->where('chave', 'em_atendimento')->firstOrFail();
        $configGeral = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('kanban_coluna_id', $colunaGeralReal->id)
            ->first();
        $this->assertNull($configGeral?->objetivo);
    }
}
```

> Confirmado por leitura direta do model (08/10/2026): `app/Models/KanbanColunaConfig.php`
> já tem `kanban_coluna_id` no `$fillable` (junto com `coluna_kanban`) — não precisa de
> migration nova nesta task, só o controller passar a filtrar por ele.

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanColunaConfigControllerKanbanIdTest`
Expected: FAIL — hoje a config é gravada/lida só por `coluna_kanban` (string), sem filtrar
por `kanban_coluna_id`, então a config do Kanban funil e a do Kanban geral colidem na mesma
linha (mesma `chave`).

- [ ] **Step 3: Implementar**

Ler `app/Http/Controllers/Painel/KanbanColunaConfigController.php` por completo antes de
mexer (arquivo já visto parcialmente — método `show(Request $request, string $coluna)` e
`update(Request $request, string $coluna)`). Em ambos:

1. Adicionar `use App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;` + `use
   ResolveKanbanDoRequest;`.
2. Resolver `$kanban = $this->resolverKanban($request);` no início do método.
3. Resolver a `KanbanColuna` real: `$colunaReal = \App\Models\KanbanColuna::where(
   'kanban_id', $kanban->id)->where('chave', $coluna)->firstOrFail();`.
4. Trocar toda busca/gravação de `KanbanColunaConfig::where('coluna_kanban', $coluna)` por
   `where('kanban_coluna_id', $colunaReal->id)`. Ao criar/atualizar (`update()`), manter
   `coluna_kanban` também preenchido com `$coluna` (compatibilidade com leituras existentes
   que usam só a string, ex.: `SdrResponderService`), mas o filtro de busca/atualização passa
   a ser por `kanban_coluna_id`, não mais só por `coluna_kanban`.

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanColunaConfigControllerKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline — atenção aqui porque mexe num controller usado por várias telas de
config de coluna, conferir `KanbanColunaConfig*Test.php` (vários arquivos existentes) não
regrediu.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php tests/Feature/KanbanColunaConfigControllerKanbanIdTest.php
git commit -m "feat: KanbanColunaConfigController resolve por kanban_id+chave, não só chave

Fecha a ambiguidade: antes, 2 Kanbans com a mesma chave de coluna
compartilhavam a mesma config sem querer. Aceita ?kanban_id= igual aos
outros controllers.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 6: `KanbanColunaObjetivoController` aceita `?kanban_id=`

**Files:**
- Create: `database/migrations/2026_10_08_000003_add_kanban_coluna_id_to_kanban_coluna_objetivos_table.php`
- Modify: `app/Models/KanbanColunaObjetivo.php` (fillable)
- Modify: `app/Http/Controllers/Painel/KanbanColunaObjetivoController.php` (métodos `index`,
  `store`, `update`, `destroy`, `reordenar`)
- Test: `tests/Feature/KanbanColunaObjetivoControllerKanbanIdTest.php`

**Interfaces:**
- Consumes: `ResolveKanbanDoRequest` (Task 2).

**Nota importante**: diferente de `KanbanColunaConfig` (Task 5), `kanban_coluna_objetivos`
**não tem** `kanban_coluna_id` hoje — confirmado por leitura direta de
`app/Models/KanbanColunaObjetivo.php:18-24` (`$fillable` só tem `tenant_id`, `coluna_kanban`,
`texto`, `ordem`, `ativo`) e da migration original
`database/migrations/2026_08_05_000003_create_kanban_coluna_objetivos_table.php`. Esta task
precisa criar essa coluna antes de poder desambiguar por ela.

- [ ] **Step 1: Escrever o teste**

Ler `app/Http/Controllers/Painel/KanbanColunaObjetivoController.php` por completo primeiro
(já visto parcialmente — `index`/`store`/`update`/`destroy`/`reordenar`, todos recebendo
`string $coluna`, filtrando só por `tenant_id` + `coluna_kanban`, sem Kanban nenhum).

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_objetivos_sao_isolados_por_kanban_mesmo_com_chave_repetida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'em_atendimento', 'label' => 'Em Atendimento (funil)',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento?kanban_id=' . $kanbanFunil->id, [
            'texto' => 'Objetivo só do funil',
        ])->assertOk();

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento');
        $textos = collect($response->json())->pluck('texto')->all();
        $this->assertNotContains('Objetivo só do funil', $textos);

        $response2 = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento?kanban_id=' . $kanbanFunil->id);
        $textos2 = collect($response2->json())->pluck('texto')->all();
        $this->assertContains('Objetivo só do funil', $textos2);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanColunaObjetivoControllerKanbanIdTest`
Expected: FAIL — hoje os objetivos de "em_atendimento" aparecem pros 2 Kanbans igual,
misturados.

- [ ] **Step 3: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->foreignId('kanban_coluna_id')->nullable()->after('coluna_kanban')
                ->constrained('kanban_colunas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_coluna_id');
        });
    }
};
```

Sem backfill — hoje todo tenant só tem o Kanban `tipo='vendas'`, então um `coluna_kanban`
sem `kanban_coluna_id` continua resolvendo certo via `resolverKanban()` + busca pela `chave`
nesse Kanban (mesma lógica de fallback das tasks anteriores); não precisa popular a coluna
nova retroativamente pra isso funcionar.

- [ ] **Step 4: Adicionar `kanban_coluna_id` ao fillable do model**

Em `app/Models/KanbanColunaObjetivo.php`, adicionar `'kanban_coluna_id',` ao array
`$fillable` (linha ~20, logo depois de `'coluna_kanban',`).

- [ ] **Step 5: Implementar no controller**

Mesmo padrão da Task 5: em cada método (`index`, `store`, `update`, `destroy`, `reordenar`)
de `app/Http/Controllers/Painel/KanbanColunaObjetivoController.php`:

1. Adicionar `use App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;` + `use
   ResolveKanbanDoRequest;`.
2. Resolver `$kanban = $this->resolverKanban($request);` no início do método.
3. Resolver a `KanbanColuna` real: `$colunaReal = \App\Models\KanbanColuna::where(
   'kanban_id', $kanban->id)->where('chave', $coluna)->firstOrFail();`.
4. Trocar todo filtro de `KanbanColunaObjetivo::where('coluna_kanban', $coluna)` por
   `where('kanban_coluna_id', $colunaReal->id)`. Ao criar (`store()`), gravar os dois campos
   (`coluna_kanban` => $coluna, `kanban_coluna_id` => $colunaReal->id) — mesma lógica de
   compatibilidade da Task 5.

- [ ] **Step 6: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanColunaObjetivoControllerKanbanIdTest`
Expected: PASS.

- [ ] **Step 7: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_08_000003_add_kanban_coluna_id_to_kanban_coluna_objetivos_table.php app/Models/KanbanColunaObjetivo.php app/Http/Controllers/Painel/KanbanColunaObjetivoController.php tests/Feature/KanbanColunaObjetivoControllerKanbanIdTest.php
git commit -m "feat: KanbanColunaObjetivoController isola objetivos por Kanban, não só por chave

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 7: `KanbanController::index()` (o board) aceita `?kanban_id=`

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php:45-48` (início do `index()`)
- Test: `tests/Feature/KanbanControllerIndexKanbanIdTest.php`

**Interfaces:**
- Consumes: `ResolveKanbanDoRequest` (Task 2).

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerIndexKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_com_kanban_id_mostra_so_as_colunas_e_tickets_daquele_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_novo', 'label' => 'Novo do Funil',
            'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $contatoGeral = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanGeral->id, 'contato_id' => $contatoGeral->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $contatoFunil = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id, 'contato_id' => $contatoFunil->id,
            'coluna_kanban' => 'funil_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets?kanban_id=' . $kanbanFunil->id);

        $response->assertOk();
        $corpo = json_encode($response->json());
        $this->assertStringNotContainsString('lead_novo', $corpo);
        $this->assertStringContainsString('funil_novo', $corpo);
    }
}
```

> Ler `KanbanController::index()` por completo antes de implementar — o método é grande (já
> visto que monta `$colunas` no topo e depois faz uma query de tickets filtrando por
> `tenant_id`, provavelmente com `whereIn('coluna_kanban', $colunas)` ou similar; confirmar o
> formato exato da resposta JSON antes de escrever a asserção final do teste acima, ajustando
> `assertStringContainsString`/`assertStringNotContainsString` pro formato real se for
> diferente de um JSON simples com a chave da coluna em texto).

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanControllerIndexKanbanIdTest`
Expected: FAIL — hoje `index()` sempre mostra as colunas/tickets do Kanban `tipo='vendas'`,
ignorando `kanban_id`.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Painel/KanbanController.php::index()`, adicionar `use
ResolveKanbanDoRequest;` na classe (se ainda não usado por nenhuma outra task — `mover()` e
`moverParaOutros()` já resolvem Kanban pelo TICKET, não pelo request, então é provável que
este seja o primeiro uso do trait neste controller). Resolver `$kanban =
$this->resolverKanban($request);` no início de `index()`, e trocar a linha
`$colunas = \App\Models\KanbanColuna::chavesDoTenant($tenantId);` por
`$colunas = \App\Models\KanbanColuna::chavesDoTenant($tenantId, $kanban->id);`. A query de
tickets logo abaixo precisa ganhar `->where('kanban_id', $kanban->id)` também (ela hoje filtra
só por `tenant_id` — ler o método completo pra achar o `TicketAtendimento::where('tenant_id',
...)` ou `::withoutGlobalScopes()->where('tenant_id', ...)` correto e adicionar o filtro ali).

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanControllerIndexKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline — `index()` é o board principal, muito usado em outros testes
(`KanbanController*Test.php`), conferir atentamente.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanControllerIndexKanbanIdTest.php
git commit -m "feat: board do Kanban (index) aceita ?kanban_id= pra mostrar qualquer Kanban do tenant

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 8: Endpoint de criação de Kanban

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php` (novo método `criar`)
- Modify: `routes/web.php` (nova rota, perto da linha 598, mesmo grupo `role:admin,dono`)
- Test: `tests/Feature/KanbanControllerCriarTest.php`

**Interfaces:**
- Produces: `POST /painel/kanban` → cria `Kanban` novo, sem colunas.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerCriarTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_kanban_novo_sem_colunas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil de Qualificação — Imersão',
            'nome_curto' => 'Imersão',
        ]);

        $response->assertStatus(201);
        $kanban = Kanban::findOrFail($response->json('id'));
        $this->assertSame('Imersão', $kanban->nome_curto);
        $this->assertSame(0, $kanban->colunas()->count());
        $this->assertNotSame('vendas', $kanban->tipo);
    }

    public function test_nome_curto_vira_tipo_unico_mesmo_repetido(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $primeiro = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil A', 'nome_curto' => 'Funil',
        ])->assertStatus(201)->json();

        $segundo = $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil B', 'nome_curto' => 'Funil',
        ])->assertStatus(201)->json();

        $this->assertNotSame(
            Kanban::findOrFail($primeiro['id'])->tipo,
            Kanban::findOrFail($segundo['id'])->tipo
        );
    }

    public function test_nome_curto_com_espaco_e_rejeitado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Funil', 'nome_curto' => 'Nome Com Espaço',
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanControllerCriarTest`
Expected: FAIL (rota 404 — não existe ainda).

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Painel/KanbanController.php`, novo método:

```php
public function criar(Request $request): JsonResponse
{
    $validated = $request->validate([
        'nome'       => 'required|string|max:100',
        'nome_curto' => 'required|string|max:20|regex:/^\S+$/',
    ]);

    $tenantId  = $request->user()->tenant_id;
    $tipoBase  = \Illuminate\Support\Str::slug($validated['nome_curto'], '_');
    $tipo      = $tipoBase;
    $sufixo    = 1;
    while (\App\Models\Kanban::where('tenant_id', $tenantId)->where('tipo', $tipo)->exists()) {
        $tipo = "{$tipoBase}_" . (++$sufixo);
    }

    $proximaOrdem = (\App\Models\Kanban::where('tenant_id', $tenantId)->max('ordem') ?? 0) + 1;

    $kanban = \App\Models\Kanban::create([
        'tenant_id'  => $tenantId,
        'tipo'       => $tipo,
        'nome'       => $validated['nome'],
        'nome_curto' => $validated['nome_curto'],
        'ordem'      => $proximaOrdem,
    ]);

    return response()->json($kanban, 201);
}
```

Em `routes/web.php`, dentro do grupo `role:admin,dono` (perto de onde `kanban/colunas` já
está, linha ~598), adicionar:

```php
Route::post('/kanban', [KanbanController::class, 'criar']);
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanControllerCriarTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php routes/web.php tests/Feature/KanbanControllerCriarTest.php
git commit -m "feat: endpoint de criação de Kanban novo (sem colunas)

Colunas são adicionadas depois pela tela de Configurações já
existente — reaproveita 100% do que já tem. tipo vira slug único do
nome curto, com sufixo numérico se colidir.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 9: Barra lateral — N blocos por Kanban + botão "+"

**Files:**
- Modify: `resources/views/layouts/app.blade.php:140-222`
- Modify: controller/view-composer que renderiza o layout, pra passar a lista de Kanbans do
  tenant (ler o arquivo primeiro pra achar onde isso deve entrar — provavelmente um
  `View::composer` novo em `AppServiceProvider`, ou uma variável já computada e compartilhada
  hoje; procurar por `$verKanban`/`$perfil` em `app.blade.php` linhas 1-20 pra ver como
  variáveis chegam nesse arquivo hoje antes de decidir onde adicionar `$kanbansDoTenant`)

**Interfaces:**
- Consumes: `Kanban.nome_curto` (Task 1), `GET/POST /painel/kanban` (Task 8).

Este é o primeiro task de frontend puro — sem teste de backend automatizado cobrindo HTML/JS.
Verificação é manual (Task 11).

- [ ] **Step 1: Ler o topo de `app.blade.php` (linhas 1-25)** pra entender como
  `$verKanban`/`$perfil`/`$user` chegam na view hoje (PHP inline no topo do Blade, ou
  injetado por um composer/middleware) — replicar o mesmo mecanismo pra `$kanbansDoTenant`.

- [ ] **Step 2: Adicionar a busca dos Kanbans do tenant**

No mesmo lugar onde `$verKanban`/`$perfil` são computados (topo do arquivo, por volta da
linha 18), adicionar:

```php
$kanbansDoTenant = $user ? \App\Models\Kanban::where('tenant_id', $user->tenant_id)->orderBy('ordem')->get() : collect();
$kanbanIdAtivo   = (int) (request()->query('kanban_id') ?? $kanbansDoTenant->firstWhere('tipo', 'vendas')?->id);
```

- [ ] **Step 3: Substituir o bloco único "Kanban" (linhas 140-222) por um `@foreach`**

Pra cada `$kanban` em `$kanbansDoTenant`, renderizar o MESMO bloco colapsável de hoje
(copiar a estrutura Alpine `x-show`/`:class` existente), trocando:
- O rótulo fixo `"Kanban"` (linha 156) por `{{ $kanban->nome_curto }}`.
- A condição de "ativo" (linha 145-147: `$kanbanAtivo`) por comparar `$kanbanIdAtivo ===
  $kanban->id`.
- O `@click` do botão por alternar `menuAberto` usando `'kanban-' . $kanban->id` como chave
  (em vez da string fixa `'kanban'`), pra cada bloco abrir/fechar independente.
- Dentro do bloco, manter SÓ os 2 links "Atendimentos" e "Configurações" (linhas 162-168 e
  176-183 do arquivo original), cada um com `?kanban_id={{ $kanban->id }}` appendado na URL:
  `route('kanban', ['kanban_id' => $kanban->id])` / `route('kanban.config', ['kanban_id' =>
  $kanban->id])`.
- REMOVER de dentro do bloco (ficam fora, na seção "Geral" do Step 4): Variáveis, Motivos de
  Encerramento, Relatórios do Gestor, Documentação, Especificações Técnicas, Gestor do
  Kanban.
- Adicionar o botão "+" (só quando `$kanban->id === $kanbanIdAtivo`, e só pra
  `$verKanbanConfig`) ao lado do nome do Kanban nesse bloco, chamando
  `abrirModalNovoKanban()` (Step 5).

- [ ] **Step 4: Criar a seção "Geral" (fora do `@foreach`)**

Logo depois do `@foreach` do Step 3, um bloco colapsável novo, rótulo "Geral", contendo
exatamente os 6 links que saíram de dentro de cada bloco de Kanban (Variáveis, Motivos de
Encerramento, Relatórios do Gestor, Documentação, Especificações Técnicas, Gestor do Kanban
admin) — mesmas rotas/condições de "ativo" de hoje, sem `kanban_id` nenhum (continuam
tenant-wide nesta fase).

- [ ] **Step 5: Modal "Novo Kanban"**

Espelhar o modal de "+ Nova Coluna" já existente em `resources/views/kanban/config.blade.php`
(função `abrirModalNovaColuna()`/`modalColunaAberto`, HTML do modal — ler esse arquivo
completo pra copiar a estrutura exata). Adicionar ao `app.blade.php` (ou a um componente
Blade novo incluído nele) um modal equivalente com 2 campos (nome, nome_curto), chamando
`POST /painel/kanban` no submit, e redirecionando pra `route('kanban', ['kanban_id' =>
response.id])` no sucesso.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: barra lateral mostra um bloco por Kanban, com botão de criar novo

Nome curto do Kanban como rótulo, só Atendimentos/Configurações
nidificados (únicas telas já isoladas por Kanban). Variáveis/Motivos/
Relatórios/Documentação/Especificações viram uma seção 'Geral'
compartilhada, sem mudar de comportamento nesta fase.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 10: Telas Alpine.js passam `kanban_id` em toda chamada

**Files:**
- Modify: `resources/views/kanban/index.blade.php` (ou equivalente, o board)
- Modify: `resources/views/kanban/config.blade.php`
- Outros arquivos em `resources/views/kanban/*.blade.php` que fazem `fetch('/painel/kanban/...')`
  — localizar com `grep -rln "fetch('/painel/kanban" resources/views/` antes de começar e
  listar todos antes de editar.

**Interfaces:**
- Consumes: todos os endpoints das Tasks 2-8 (aceitam `?kanban_id=` agora).

- [ ] **Step 1: Listar todos os arquivos afetados**

```bash
grep -rln "fetch(['\"]\?/painel/kanban\|/api/painel/kanban" resources/views/kanban/*.blade.php
```

- [ ] **Step 2: Em cada arquivo Alpine (`x-data` principal da página)**, adicionar no
  `x-init` ou no `init()`:

```js
this.kanbanId = new URLSearchParams(window.location.search).get('kanban_id');
```

E criar um helper `urlComKanban(path)` que appenda `(kanbanId ? (path.includes('?') ? '&' :
'?') + 'kanban_id=' + kanbanId : '')` — usar esse helper em TODA chamada `fetch()` existente
no arquivo, trocando `fetch('/painel/kanban/colunas')` por
`fetch(this.urlComKanban('/painel/kanban/colunas'))`, por exemplo.

- [ ] **Step 3: Rodar a suíte completa** (garantir que nenhum teste de backend quebrou — os
  testes de backend não passam por essa camada JS, então isso é só uma confirmação de que
  nada em PHP foi tocado sem querer)

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/
git commit -m "feat: telas do Kanban passam kanban_id em toda chamada à API

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 11: Verificação manual no navegador (Playwright)

**Files:** nenhum arquivo novo.

- [ ] **Step 1: Rodar a suíte completa do zero**

Run: `php artisan test`
Expected: mesma baseline de 17 falhas pré-existentes.

- [ ] **Step 2: Login real no painel (ambiente local ou staging) e testar visualmente**

1. Tenant com só 1 Kanban: confirmar que a barra lateral está EXATAMENTE igual a antes
   (rótulo "Atendimentos", mesmo comportamento) — nada deve ter mudado visualmente.
2. Clicar no "+", preencher nome + nome curto, criar um Kanban novo — confirmar que aparece
   um bloco novo na barra lateral.
3. Abrir "Atendimentos" do Kanban novo — confirmar que o board aparece vazio (sem os tickets
   do Kanban geral).
4. Abrir "Configurações" do Kanban novo — confirmar que "+ Nova Coluna" cria uma coluna SÓ
   nesse Kanban (voltar pro Kanban geral e confirmar que a coluna não aparece lá).
5. Alternar entre os 2 Kanbans várias vezes — confirmar que só um bloco fica aberto/verde por
   vez, e que o board mostra os dados certos em cada troca.

- [ ] **Step 3: Reportar qualquer divergência encontrada** — se algo visual não bater com o
  esperado, corrigir via TDD (escrever o teste que faltou, se for um bug de backend) ou ajuste
  direto no Blade/JS (se for só um detalhe visual), antes de prosseguir pro deploy.

---

## Task 12: Deploy

**Files:** nenhum arquivo novo.

- [ ] **Step 1: Pedir confirmação explícita ao Leonardo antes do deploy** — mesmo com a
  suíte 100% verde e a verificação manual feita, este é o primeiro deploy que toca a barra
  lateral de TODOS os tenants ao mesmo tempo.

- [ ] **Step 2: Deploy**

```bash
git checkout main
git merge --no-ff <branch-usada-se-houver> -m "Merge: seletor de Kanban na navegação"
git push origin main
bash deploy.sh
```

- [ ] **Step 3: Verificar em produção**

```bash
ssh -i ~/.ssh/leadcerto_vps_nova root@31.97.172.203 "supervisorctl status"
```

Expected: workers `RUNNING`. Depois, abrir o painel de um tenant real (ex.: Frete Rio) e
confirmar visualmente que a barra lateral está igual a antes (já que esse tenant só tem 1
Kanban) — nenhuma mudança visível pra quem não criou um 2º Kanban ainda.
