# Navegação fixa "Kanban" + permissão de edição restrita (Etapa 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A barra lateral passa a ter "Kanban" como rótulo fixo, com um item por Kanban do
tenant (identificado pelo apelido); o Kanban ativo mostra inline os 7 itens de sempre
(Atendimentos, Variáveis, Configurações, Motivos de Encerramento, Relatórios do Gestor,
Documentação, Especificações Técnicas). Qualquer perfil com acesso a Kanban passa a
**visualizar** essas 7 áreas; só `admin` (time Lead Certo) continua podendo **editar**
qualquer uma delas — nem `dono` (hoje com acesso total) edita mais.

**Architecture:** Mudança de permissão (rotas) + reestruturação de navegação (Blade) + bloqueio
visual de edição (CSS condicional) — sem nenhuma migration nesta etapa. O dado de
Variáveis/Motivos/Relatórios continua um só por tenant (fica pras Etapas 2/3/4 virar dado por
Kanban).

**Tech Stack:** Laravel 11 (PHP), Alpine.js, PHPUnit, Playwright (verificação manual).

**Spec:** `docs/superpowers/specs/2026-10-09-navegacao-kanban-permissoes-design.md`

## Global Constraints

- Nenhuma migration nesta etapa — o dado de Variáveis/Motivos/Relatórios continua tenant-wide.
- Suíte completa sem regressão além da baseline (17 falhas + 5 erros pré-existentes,
  confirmado em 09/10/2026).
- **Ripple esperado e aceito**: apertar a edição de `['admin','dono']` pra só `admin` vai
  quebrar testes pré-existentes (de antes de ontem e de ontem) que criam um usuário
  `perfil => 'dono'` e testam `POST/PUT/DELETE` nessas rotas — isso é o comportamento NOVO
  funcionando como esperado, não uma regressão real. Cada task resolve isso trocando o
  `perfil` desses testes especificamente de mutação pra `'admin'`, e adicionando um teste novo
  confirmando que `dono` agora recebe 403 — documentar cada arquivo tocado como uma
  `Ruling:` no ledger, igual ao padrão de ontem.
- Commits em português, terminando com `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Deploy em produção só com confirmação explícita do Leonardo, depois de TODAS as tasks
  passarem e da verificação manual (Task 10).

## Review Focus

- Perfis fora da lista de acesso a Kanban (`admin,dono,diretor,gerente,gestor,vendedor,
  pos_venda,diretor_marketing`) continuam 404/403 nessas rotas — abrir a visualização pra
  mais perfis não pode abrir pra QUALQUER perfil autenticado.
- `dono` perde edição em TODAS as 7 áreas, não só nas que a task tocar primeiro — conferir
  Configurações (colunas, canais, info, coluna-config, coluna-objetivos, criar Kanban),
  Variáveis e Motivos de Encerramento, uma por uma.
- A tela de Atendimentos (mover card, mandar mensagem, assumir ticket) não pode ser afetada
  por nenhuma dessas mudanças — é operação do dia a dia, continua liberada pra todo mundo.
- O link de "voltar" (seta `←`) em cada tela bloqueada continua clicável mesmo com o resto da
  tela travado — sem isso, quem não é admin fica preso na tela sem conseguir sair.
- Relatórios do Gestor e Documentação/Especificações Técnicas não ganham banner de só-leitura
  à toa — elas já não têm nada editável pra bloquear (achado confirmado na investigação).

---

## Task 1: Rotas — Configurações do Kanban e Variáveis abrem visualização, apertam edição pra admin

**Files:**
- Modify: `routes/web.php:281-283` (rota `kanban.config`)
- Modify: `routes/web.php:286-288` (rota `kanban.variaveis`)
- Modify: `routes/web.php:586-615` (grupo de API hoje `role:admin,dono`, split em dois)
- Test: `tests/Feature/KanbanConfigPermissaoEdicaoTest.php` (novo)
- Modify: `tests/Feature/KanbanColunaControllerKanbanIdTest.php` (perfil de mutação → admin)
- Modify: `tests/Feature/KanbanColunaConfigControllerKanbanIdTest.php` (perfil de mutação → admin)
- Modify: `tests/Feature/KanbanColunaObjetivoControllerKanbanIdTest.php` (perfil de mutação → admin)
- Modify: `tests/Feature/KanbanCanalControllerKanbanIdTest.php` (perfil de mutação → admin)
- Modify: `tests/Feature/KanbanInfoControllerKanbanIdTest.php` (perfil de mutação → admin)
- Modify: `tests/Feature/KanbanControllerCriarTest.php` (perfil → admin)

**Interfaces:**
- Nenhuma interface nova — só mudança de middleware nas rotas existentes.

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanConfigPermissaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_configuracoes_do_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/config')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/colunas')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/variaveis')->assertOk();
    }

    public function test_dono_nao_edita_mais_colunas_nem_variaveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Nova', 'papel' => 'em_andamento',
        ])->assertStatus(403);

        $this->actingAs($user)->postJson('/api/painel/kanban/variaveis', [
            'nome' => 'teste', 'label' => 'Teste', 'opcoes' => ['a', 'b'],
        ])->assertStatus(403);

        $this->actingAs($user)->postJson('/api/painel/kanban', [
            'nome' => 'Novo Kanban', 'nome_curto' => 'Novo',
        ])->assertStatus(403);
    }

    public function test_admin_continua_editando_colunas_e_variaveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Nova', 'papel' => 'em_andamento',
        ])->assertStatus(201);

        $this->actingAs($user)->postJson('/api/painel/kanban/variaveis', [
            'nome' => 'teste', 'label' => 'Teste', 'opcoes' => ['a', 'b'],
        ])->assertStatus(201);
    }

    public function test_perfil_sem_acesso_a_kanban_continua_barrado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'auditor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/config')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/painel/kanban/colunas')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=KanbanConfigPermissaoEdicaoTest`
Expected: FAIL — hoje vendedor recebe 403 em `/kanban/config` (view) e nas rotas de API (estão
todas em grupos `role:admin,dono`); dono consegue POST com sucesso (201, não 403).

- [ ] **Step 3: Trocar a rota de visualização de `kanban.config`**

Em `routes/web.php:281-283`:

```php
    // Configurações do Kanban — visualização aberta a todo mundo com acesso a Kanban,
    // edição restrita a admin (ver routes/web.php:586 em diante)
    Route::get('/kanban/config', fn () => view('kanban.config'))
        ->name('kanban.config')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

- [ ] **Step 4: Trocar a rota de visualização de `kanban.variaveis`**

Em `routes/web.php:286-288`:

```php
    // Variáveis de mensagem — visualização aberta a todo mundo com acesso a Kanban,
    // edição restrita a admin
    Route::get('/kanban/variaveis', fn () => view('kanban.variaveis'))
        ->name('kanban.variaveis')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

- [ ] **Step 5: Dividir o grupo de API `role:admin,dono` (linhas 586-615) em dois**

Substituir o bloco inteiro de `routes/web.php:585-615`:

```php
    // Configuração do Kanban (colunas, canais, info, objetivos, variáveis, criar Kanban) —
    // visualização aberta a todo mundo com acesso a Kanban, edição restrita a admin (time
    // Lead Certo) — achado 09/10/2026: dono deixou de poder editar essas áreas.
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing')->group(function () {
        Route::get('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'show']);
        Route::get('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'show']);
        Route::get('/kanban/colunas',             [KanbanColunaController::class, 'index']);
        Route::get('/kanban/papeis',              [KanbanColunaController::class, 'papeis']);
        Route::get('/kanban/canais', [\App\Http\Controllers\Painel\KanbanCanalController::class, 'index']);
        Route::get('/kanban/variaveis',           [SpintaxVariavelController::class, 'index']);
        Route::get('/kanban/variaveis/listar',    [SpintaxVariavelController::class, 'listar']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::put('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'update']);
        Route::put('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'update']);
        Route::post('/kanban/coluna-objetivos/{coluna}',              [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'store']);
        Route::put('/kanban/coluna-objetivos/{coluna}/{id}',          [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'update']);
        Route::delete('/kanban/coluna-objetivos/{coluna}/{id}',       [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'destroy']);
        Route::post('/kanban/coluna-objetivos/{coluna}/reordenar',    [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'reordenar']);
        Route::post('/kanban', [KanbanController::class, 'criar']);
        Route::post('/kanban/colunas',            [KanbanColunaController::class, 'store']);
        Route::put('/kanban/colunas/{coluna}',    [KanbanColunaController::class, 'update']);
        Route::delete('/kanban/colunas/{coluna}', [KanbanColunaController::class, 'destroy']);
        Route::post('/kanban/colunas/reordenar',  [KanbanColunaController::class, 'reordenar']);
        Route::put('/kanban/canais', [\App\Http\Controllers\Painel\KanbanCanalController::class, 'update']);
        Route::post('/kanban/variaveis',          [SpintaxVariavelController::class, 'store']);
        Route::put('/kanban/variaveis/{nome}',    [SpintaxVariavelController::class, 'update']);
        Route::delete('/kanban/variaveis/{nome}', [SpintaxVariavelController::class, 'destroy']);
    });
```

Nota: `/kanban/coluna-objetivos/{coluna}` (GET, `index`) já está aberto desde ontem no grupo
amplo de `routes/web.php:410-428` — não precisa duplicar aqui.

A rota antiga de `routes/web.php:618-619` (`/kanban/variaveis/listar` com role amplo) fica
redundante com a nova rota de visualização do Step 5 acima — remover essas 2 linhas (618-619)
pra não ter rota duplicada.

- [ ] **Step 6: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=KanbanConfigPermissaoEdicaoTest`
Expected: PASS (4 testes).

- [ ] **Step 7: Rodar a suíte completa — mapear o ripple**

Run: `php artisan test`
Expected: vários testes pré-existentes falhando — cada um porque criava um usuário
`perfil => 'dono'` esperando conseguir `POST/PUT/DELETE` nessas rotas. Ler a lista de falhas,
confirmar que TODAS são dessa natureza (nenhuma falha "estranha"/não relacionada) antes de
seguir pro próximo step.

- [ ] **Step 8: Corrigir os testes afetados — trocar `perfil` de mutação pra `admin`**

Para cada um dos arquivos listados em **Files** acima
(`KanbanColunaControllerKanbanIdTest.php`, `KanbanColunaConfigControllerKanbanIdTest.php`,
`KanbanColunaObjetivoControllerKanbanIdTest.php`, `KanbanCanalControllerKanbanIdTest.php`,
`KanbanInfoControllerKanbanIdTest.php`, `KanbanControllerCriarTest.php`): abrir o arquivo,
trocar `'perfil' => 'dono'` por `'perfil' => 'admin'` em todo teste que faz `POST`/`PUT`/
`DELETE` nas rotas tocadas acima. Testes que só fazem `GET` continuam com `dono` (ainda
funciona pra visualizar). Verificar também se existem outros arquivos de teste **pré-existentes
de antes de ontem** (não listados em Files, ex.: `KanbanColunaControllerTest.php`,
`SpintaxVariavelControllerTest.php` se existir) afetados pela suíte completa do Step 7 e
corrigir do mesmo jeito.

- [ ] **Step 9: Rodar a suíte completa de novo**

Run: `php artisan test`
Expected: de volta à baseline (17 falhas + 5 erros pré-existentes, mesmos nomes de sempre).

- [ ] **Step 10: Commit**

```bash
git add routes/web.php tests/Feature/KanbanConfigPermissaoEdicaoTest.php tests/Feature/KanbanColunaControllerKanbanIdTest.php tests/Feature/KanbanColunaConfigControllerKanbanIdTest.php tests/Feature/KanbanColunaObjetivoControllerKanbanIdTest.php tests/Feature/KanbanCanalControllerKanbanIdTest.php tests/Feature/KanbanInfoControllerKanbanIdTest.php tests/Feature/KanbanControllerCriarTest.php
git commit -m "feat: Configurações do Kanban e Variáveis — visualização aberta, edição só admin

Qualquer perfil com acesso a Kanban passa a visualizar colunas,
canais, info, objetivos e variáveis. Editar (criar/salvar/excluir/
reordenar) fica restrito ao time Lead Certo (perfil=admin) — dono
deixa de poder editar, decisão explícita do Leonardo (09/10/2026).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: Rotas — Motivos de Encerramento aperta edição pra admin

**Files:**
- Modify: `routes/web.php:296-298` (rota `kanban.motivos-desfecho`)
- Modify: `routes/web.php:437-442` (grupo `role:admin,dono` → `role:admin`)
- Test: `tests/Feature/MotivoDesfechoPermissaoEdicaoTest.php` (novo)
- Modify: testes pré-existentes de `MotivoDesfechoController` que usam `perfil => 'dono'` em
  mutação (localizar via `grep -rl "MotivoDesfechoController\|motivos-desfecho" tests/Feature/`
  e conferir cada um na suíte completa do Step 5 abaixo).

**Interfaces:**
- Nenhuma interface nova.

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotivoDesfechoPermissaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/motivos-desfecho')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/motivos-desfecho')->assertOk();
    }

    public function test_dono_nao_edita_mais_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Teste', 'e_venda' => false,
        ])->assertStatus(403);
    }

    public function test_admin_continua_editando_motivos_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);

        $this->actingAs($user)->postJson('/api/painel/kanban/motivos-desfecho', [
            'label' => 'Teste', 'e_venda' => false,
        ])->assertStatus(201);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=MotivoDesfechoPermissaoEdicaoTest`
Expected: FAIL — vendedor recebe 403 na view hoje; dono consegue POST (201, não 403).

- [ ] **Step 3: Trocar a rota de visualização**

Em `routes/web.php:296-298`:

```php
    // Motivos de encerramento — visualização aberta a todo mundo com acesso a Kanban,
    // edição restrita a admin
    Route::get('/kanban/motivos-desfecho', [MotivoDesfechoController::class, 'view'])
        ->name('kanban.motivos-desfecho')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

- [ ] **Step 4: Apertar o grupo de mutação**

Em `routes/web.php:437-442`:

```php
    // Gerenciar motivos de encerramento — só admin (time Lead Certo); ver a lista, todo
    // mundo com acesso a Kanban pode (GET já aberto desde ontem em routes/web.php:414)
    Route::middleware('role:admin')->group(function () {
        Route::post('/kanban/motivos-desfecho', [MotivoDesfechoController::class, 'store']);
        Route::put('/kanban/motivos-desfecho/{id}', [MotivoDesfechoController::class, 'update']);
        Route::delete('/kanban/motivos-desfecho/{id}', [MotivoDesfechoController::class, 'destroy']);
    });
```

- [ ] **Step 5: Rodar o teste pra confirmar que passa, depois a suíte completa**

Run: `php artisan test --filter=MotivoDesfechoPermissaoEdicaoTest` → PASS (3 testes).
Run: `php artisan test` → mapear quais testes pré-existentes quebraram (mesmo padrão do Task
1 — usuário `dono` esperando conseguir mutar). Corrigir trocando `perfil` pra `admin` nesses
testes de mutação, igual ao Task 1 Step 8.
Run: `php artisan test` de novo → baseline restaurada.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/MotivoDesfechoPermissaoEdicaoTest.php
git commit -m "feat: Motivos de Encerramento — visualização aberta, edição só admin

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

(Incluir no `git add` qualquer arquivo de teste pré-existente corrigido no Step 5.)

---

## Task 3: Rotas — Relatórios do Gestor abre visualização (sem mutação pra restringir)

**Files:**
- Modify: `routes/web.php:291-293` (rota `kanban.relatorios`)
- Modify: `routes/web.php:430-435` (grupo `role:admin,dono` → role amplo)
- Test: `tests/Feature/RelatoriosGestorVisualizacaoTest.php` (novo)

**Interfaces:**
- Nenhuma interface nova. Achado confirmado na investigação: `GestorKanbanRelatorioController`
  não tem nenhum método de mutação — é só `index`, `show`, `auditorias` — então não existe
  edição pra restringir aqui, só a visualização muda de quem pode ver.

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatoriosGestorVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_relatorios_do_gestor(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/relatorios')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/relatorios')->assertOk();
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=RelatoriosGestorVisualizacaoTest`
Expected: FAIL — vendedor recebe 403 hoje (rota `role:admin,dono`).

- [ ] **Step 3: Trocar a rota de visualização da página**

Em `routes/web.php:291-293`:

```php
    // Relatórios semanais do Gestor do Kanban — visualização aberta a todo mundo com
    // acesso a Kanban (não tem nada editável nesta tela — só leitura pra todo mundo já)
    Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'view'])
        ->name('kanban.relatorios')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

- [ ] **Step 4: Trocar o grupo de API**

Em `routes/web.php:430-435`:

```php
    // Relatórios semanais do Gestor do Kanban — leitura aberta a todo mundo com acesso
    // a Kanban
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing')->group(function () {
        Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'index']);
        Route::get('/kanban/relatorios/{id}', [GestorKanbanRelatorioController::class, 'show']);
        Route::get('/kanban/auditorias', [GestorKanbanRelatorioController::class, 'auditorias']);
    });
```

- [ ] **Step 5: Rodar o teste pra confirmar que passa, depois a suíte completa**

Run: `php artisan test --filter=RelatoriosGestorVisualizacaoTest` → PASS.
Run: `php artisan test` → baseline (sem ripple esperado aqui, não há mutação envolvida).

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/RelatoriosGestorVisualizacaoTest.php
git commit -m "feat: Relatórios do Gestor — visualização aberta a todo mundo com acesso a Kanban

Tela já é só leitura hoje pra qualquer perfil — não tem nada pra
restringir além de quem pode entrar.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: Rotas — Documentação e Especificações Técnicas abrem visualização

**Files:**
- Modify: `routes/web.php:301-303` (rota `kanban.documentacao-botoes`)
- Modify: `routes/web.php:306-311` (rotas `admin.especificacoes` e `admin.especificacoes.show`)
- Test: `tests/Feature/DocumentacaoEspecificacoesVisualizacaoTest.php` (novo)

**Interfaces:**
- Nenhuma — ambas são conteúdo estático (achado confirmado: Documentação é Blade estático sem
  controller; Especificações Técnicas lê markdown do disco, não é dado de tenant).

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentacaoEspecificacoesVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_documentacao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/documentacao/botoes')->assertOk();
    }

    public function test_vendedor_visualiza_especificacoes_tecnicas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/admin/especificacoes')->assertOk();
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=DocumentacaoEspecificacoesVisualizacaoTest`
Expected: FAIL — vendedor recebe 403 nas duas rotas hoje.

- [ ] **Step 3: Trocar as 3 rotas**

Em `routes/web.php:301-303`:

```php
    // Documentação/estratégia — visualização aberta a todo mundo com acesso a Kanban
    Route::get('/kanban/documentacao/botoes', fn () => view('kanban.documentacao-botoes'))
        ->name('kanban.documentacao-botoes')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

Em `routes/web.php:306-311`:

```php
    // Especificações técnicas (specs de design registradas com o Claude) — visualização
    // aberta a todo mundo com acesso a Kanban
    Route::get('/admin/especificacoes', [EspecificacoesController::class, 'index'])
        ->name('admin.especificacoes')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
    Route::get('/admin/especificacoes/{arquivo}', [EspecificacoesController::class, 'show'])
        ->name('admin.especificacoes.show')
        ->middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing');
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa, depois a suíte completa**

Run: `php artisan test --filter=DocumentacaoEspecificacoesVisualizacaoTest` → PASS.
Run: `php artisan test` → baseline (sem mutação envolvida, sem ripple esperado).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/DocumentacaoEspecificacoesVisualizacaoTest.php
git commit -m "feat: Documentação e Especificações Técnicas — visualização aberta a todo mundo com acesso a Kanban

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: Frontend — bloqueio de edição em Configurações do Kanban

**Files:**
- Modify: `resources/views/kanban/config.blade.php:6-10` (div raiz + link de voltar)
- Test: manual (Blade/Alpine não é testável por PHPUnit — cobertura automatizada fica no
  `KanbanBladeCompileCheckTest` estendido na Task 9; esta task soma verificação de compilação
  rápida).

**Interfaces:**
- Consome `auth()->user()->isAdmin()` (já existe em `app/Models/User.php:80-83`).

- [ ] **Step 1: Ler o arquivo atual pra confirmar a estrutura**

Linhas 6-10 de `resources/views/kanban/config.blade.php` hoje:

```blade
<div class="max-w-4xl mx-auto" x-data="kanbanConfig()" x-init="carregar()">

    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban', request()->query('kanban_id') ? ['kanban_id' => request()->query('kanban_id')] : []) }}"
           class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

- [ ] **Step 2: Adicionar o banner e o bloqueio visual**

Substituir essas linhas por:

```blade
@unless(auth()->user()->isAdmin())
<div class="max-w-4xl mx-auto mb-4">
    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Edição restrita ao time Lead Certo — você pode visualizar, mas não alterar esta tela.
    </div>
</div>
@endunless

<div class="max-w-4xl mx-auto{{ auth()->user()->isAdmin() ? '' : ' opacity-60 pointer-events-none select-none' }}" x-data="kanbanConfig()" x-init="carregar()">

    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban', request()->query('kanban_id') ? ['kanban_id' => request()->query('kanban_id')] : []) }}"
           class="pointer-events-auto relative text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

(`pointer-events-auto relative` no link de voltar é a única exceção — sem isso, quem não é
admin fica preso na tela, sem conseguir voltar pro board.)

- [ ] **Step 3: Limpar o cache de views e confirmar que compila**

Run: `php artisan view:clear`
Run: `php artisan test --filter=KanbanConfigViewTest`
Expected: PASS (teste já existente, confirma que a página renderiza sem erro de Blade).

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: Configurações do Kanban — bloqueio visual de edição pra quem não é admin

Aviso no topo + tela travada pro clique (link de voltar continua
funcionando). A segurança de verdade está nas rotas (Task 1) — isso
é só o reflexo visual.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 6: Frontend — bloqueio de edição em Variáveis

**Files:**
- Modify: `resources/views/kanban/variaveis.blade.php:6-16`

**Interfaces:**
- Consome `auth()->user()->isAdmin()`.

- [ ] **Step 1: Ler a estrutura atual (linhas 6-16, já lidas na investigação)**

```blade
<div class="max-w-4xl mx-auto" x-data="kanbanVariaveis()" x-init="carregar()">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('kanban.config') }}"
               class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

- [ ] **Step 2: Adicionar o banner e o bloqueio visual**

```blade
@unless(auth()->user()->isAdmin())
<div class="max-w-4xl mx-auto mb-4">
    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Edição restrita ao time Lead Certo — você pode visualizar, mas não alterar esta tela.
    </div>
</div>
@endunless

<div class="max-w-4xl mx-auto{{ auth()->user()->isAdmin() ? '' : ' opacity-60 pointer-events-none select-none' }}" x-data="kanbanVariaveis()" x-init="carregar()">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('kanban.config') }}"
               class="pointer-events-auto relative text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

- [ ] **Step 3: Limpar cache e confirmar compilação**

Run: `php artisan view:clear`

Não existe hoje um teste de compile dedicado pra essa página — adicionar um rápido:

Arquivo novo `tests/Feature/VariaveisBladeCompileCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariaveisBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_variaveis_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban/variaveis');

        $response->assertOk();
    }
}
```

Run: `php artisan test --filter=VariaveisBladeCompileCheckTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/variaveis.blade.php tests/Feature/VariaveisBladeCompileCheckTest.php
git commit -m "feat: Variáveis — bloqueio visual de edição pra quem não é admin

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 7: Frontend — bloqueio de edição em Motivos de Encerramento

**Files:**
- Modify: `resources/views/kanban/motivos-desfecho.blade.php:6-13`

**Interfaces:**
- Consome `auth()->user()->isAdmin()`.

- [ ] **Step 1: Ler a estrutura atual (linhas 6-13, já lidas na investigação)**

```blade
<div class="max-w-2xl mx-auto" x-data="motivosDesfecho()" x-init="carregar()">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban.config') }}"
           class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

- [ ] **Step 2: Adicionar o banner e o bloqueio visual**

```blade
@unless(auth()->user()->isAdmin())
<div class="max-w-2xl mx-auto mb-4">
    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Edição restrita ao time Lead Certo — você pode visualizar, mas não alterar esta tela.
    </div>
</div>
@endunless

<div class="max-w-2xl mx-auto{{ auth()->user()->isAdmin() ? '' : ' opacity-60 pointer-events-none select-none' }}" x-data="motivosDesfecho()" x-init="carregar()">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('kanban.config') }}"
           class="pointer-events-auto relative text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition-colors">
```

- [ ] **Step 3: Limpar cache e confirmar compilação**

Run: `php artisan view:clear`

Arquivo novo `tests/Feature/MotivosDesfechoBladeCompileCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotivosDesfechoBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_motivos_desfecho_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban/motivos-desfecho');

        $response->assertOk();
    }
}
```

Run: `php artisan test --filter=MotivosDesfechoBladeCompileCheckTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/motivos-desfecho.blade.php tests/Feature/MotivosDesfechoBladeCompileCheckTest.php
git commit -m "feat: Motivos de Encerramento — bloqueio visual de edição pra quem não é admin

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 8: Barra lateral — menu "Kanban" fixo, 7 itens por Kanban ativo, sem seta por Kanban

**Files:**
- Modify: `resources/views/layouts/app.blade.php:148-267` (bloco inteiro do Kanban)
- Modify: `tests/Feature/KanbanBladeCompileCheckTest.php` (ajustar as asserções da Task 9
  que dependem da estrutura antiga — ver Task 9)

**Interfaces:**
- Consome `$kanbansDoTenant`/`$kanbanIdAtivo` (já calculados no topo do layout, ver
  `app.blade.php:31-34`), `$verKanban` (já existe), `auth()->user()->isAdmin()`.

**Nota importante**: `$verKanbanConfig = in_array($perfil, ['admin','dono'])` (linha 150) e o
ramo `@else` (linhas 258-266, link único "Kanban" sem submenu) deixam de existir — agora
`$verKanban` sozinho decide quem vê o menu, e todo mundo que vê, vê a lista completa de
Kanbans (achado da conversa com o Leonardo em 09/10/2026: vendedor/gerente também passam a
ver a lista, resolvendo o gap que ontem ficou documentado como pendência).

- [ ] **Step 1: Substituir o bloco inteiro (linhas 148-267)**

```blade
            {{-- Kanban --}}
            @if($verKanban)
            @php
                $kanbanSecaoAtiva = request()->routeIs('kanban')
                    || request()->routeIs('kanban.config')
                    || request()->routeIs('kanban.variaveis')
                    || request()->routeIs('kanban.motivos-desfecho')
                    || request()->routeIs('kanban.relatorios')
                    || request()->routeIs('kanban.documentacao-botoes')
                    || request()->routeIs('admin.especificacoes*')
                    || request()->routeIs('admin.gestor-kanban');
            @endphp
            <div>
                <button @click="menuAberto = (menuAberto === 'kanban' ? '' : 'kanban')"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm w-full transition"
                        :class="menuAberto === 'kanban' || {{ $kanbanSecaoAtiva ? 'true' : 'false' }} ? 'bg-green-600 text-white font-semibold' : 'text-gray-300 hover:bg-gray-700'">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                    </svg>
                    <span class="flex-1 text-left">Kanban</span>
                    <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0" :class="menuAberto === 'kanban' ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="menuAberto === 'kanban'" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="ml-6 mt-1 space-y-0.5">
                    @if(auth()->user()->isAdmin())
                    <button @click="$dispatch('abrir-novo-kanban')"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs w-full text-left text-gray-400 hover:bg-gray-700 hover:text-gray-200">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Criar novo Kanban
                    </button>
                    <a href="{{ route('admin.gestor-kanban') }}"
                       class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('admin.gestor-kanban') ? 'bg-green-700 text-white font-medium' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        Gestor do Kanban (admin)
                    </a>
                    @endif
                    @foreach ($kanbansDoTenant as $kanbanItem)
                        @php
                            $kanbanEsteAtivo = $kanbanIdAtivo === $kanbanItem->id && $kanbanSecaoAtiva;
                        @endphp
                        <a href="{{ route('kanban', ['kanban_id' => $kanbanItem->id]) }}"
                           class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs truncate {{ $kanbanEsteAtivo ? 'bg-green-700 text-white font-medium' : 'text-gray-400 hover:bg-gray-700 hover:text-gray-200' }}">
                            {{ $kanbanItem->nome_curto ?: $kanbanItem->nome }}
                        </a>
                        @if($kanbanEsteAtivo)
                        <div class="ml-4 space-y-0.5">
                            <a href="{{ route('kanban', ['kanban_id' => $kanbanItem->id]) }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ (request()->routeIs('kanban') && !request()->routeIs('kanban.*')) ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                                </svg>
                                Atendimentos
                            </a>
                            <a href="{{ route('kanban.variaveis') }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.variaveis') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                </svg>
                                Variáveis
                            </a>
                            <a href="{{ route('kanban.config', ['kanban_id' => $kanbanItem->id]) }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.config') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                Configurações
                            </a>
                            <a href="{{ route('kanban.motivos-desfecho') }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.motivos-desfecho') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                                Motivos de Encerramento
                            </a>
                            <a href="{{ route('kanban.relatorios') }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.relatorios') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                Relatórios do Gestor
                            </a>
                            <a href="{{ route('kanban.documentacao-botoes') }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('kanban.documentacao-botoes') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s4.332.477 5.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                </svg>
                                Documentação
                            </a>
                            <a href="{{ route('admin.especificacoes') }}"
                               class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs {{ request()->routeIs('admin.especificacoes*') ? 'bg-green-800 text-white font-medium' : 'text-gray-500 hover:bg-gray-700 hover:text-gray-200' }}">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Especificações Técnicas
                            </a>
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>
            @endif
```

- [ ] **Step 2: Limpar cache e rodar o teste de compile existente**

Run: `php artisan view:clear`
Run: `php artisan test --filter=KanbanBladeCompileCheckTest`
Expected: FAIL — os 2 testes novos de ontem (`test_barra_lateral_mostra_um_bloco_por_kanban_do_tenant`
e `test_pagina_kanban_renderiza_sem_erro_pra_perfil_sem_acesso_as_configuracoes`) checam a
estrutura antiga (seção "Geral" existindo via `assertDontSee('kanban-geral', false)`, que não
faz mais sentido — essa chave nem existe mais). Isso é esperado, corrigido na Task 9.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: barra lateral — menu Kanban fixo, 7 itens por Kanban ativo, sem seta por Kanban

Rótulo do topo nunca muda ('Kanban'); cada Kanban listado mostra
inline os 7 itens de sempre quando é o ativo (como abas, sem seta de
abrir/fechar nesse nível — só o menu 'Kanban' em si continua
colapsável, igual às outras seções da barra). 'Criar novo Kanban' e
'Gestor do Kanban' ficam no topo do submenu, só pra admin. A seção
'Geral' de ontem deixa de existir — os 5 itens voltam pra dentro de
cada Kanban. Todo perfil com acesso a Kanban agora vê a lista
completa (antes só admin/dono viam qualquer coisa além do link
único).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 9: Corrigir e estender `KanbanBladeCompileCheckTest` pra nova estrutura

**Files:**
- Modify: `tests/Feature/KanbanBladeCompileCheckTest.php`

**Interfaces:**
- Nenhuma nova.

- [ ] **Step 1: Ler o arquivo atual por completo**

(3 testes existentes de ontem: `test_pagina_kanban_renderiza_sem_erro_de_blade`,
`test_barra_lateral_mostra_um_bloco_por_kanban_do_tenant`,
`test_pagina_kanban_renderiza_sem_erro_pra_perfil_sem_acesso_as_configuracoes`.)

- [ ] **Step 2: Reescrever os 2 testes que dependiam da estrutura antiga**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_kanban_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
    }

    public function test_barra_lateral_mostra_um_item_por_kanban_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Imersão', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee('Atendimentos');
        $response->assertSee('Imersão');
        $response->assertDontSee('Geral');
    }

    public function test_vendedor_ve_a_lista_de_kanbans_mas_nao_ve_criar_novo_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee('Atendimentos');
        $response->assertDontSee('Criar novo Kanban');
    }

    public function test_admin_ve_o_botao_de_criar_novo_kanban_mas_dono_nao_ve(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);
        $dono   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($admin)->get('/kanban')->assertSee('Criar novo Kanban');
        $this->actingAs($dono)->get('/kanban')->assertDontSee('Criar novo Kanban');
    }
}
```

- [ ] **Step 3: Rodar o arquivo inteiro**

Run: `php artisan test --filter=KanbanBladeCompileCheckTest`
Expected: PASS (4 testes).

- [ ] **Step 4: Rodar a suíte completa**

Run: `php artisan test`
Expected: baseline restaurada (17 falhas + 5 erros pré-existentes).

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/KanbanBladeCompileCheckTest.php
git commit -m "test: atualiza KanbanBladeCompileCheckTest pra nova estrutura da barra lateral

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 10: Verificação manual no navegador (Playwright)

**Files:** nenhum arquivo novo.

- [ ] **Step 1: Rodar a suíte completa do zero**

Run: `php artisan test`
Expected: mesma baseline (17 falhas + 5 erros pré-existentes), confirmada do zero.

- [ ] **Step 2: Migrar o banco local de dev e testar no navegador**

Igual ao padrão de ontem: `php artisan serve`, criar um tenant + 2 usuários descartáveis
(`perfil => 'admin'` e `perfil => 'dono'`) via `php artisan tinker` com um script temporário,
logar com cada um via Playwright, conferir:

1. **Como `dono`**: abre "Kanban", vê a lista de Kanbans (sem botão "Criar novo Kanban"), abre
   Configurações/Variáveis/Motivos de Encerramento — vê o aviso amber no topo, a tela aparece
   apagada e sem clique, o link de voltar (←) continua funcionando. Abre Relatórios do Gestor,
   Documentação, Especificações Técnicas normalmente (sem aviso, já eram só leitura).
   Atendimentos continua 100% funcional (mover card, mandar mensagem).
2. **Como `admin`**: vê o botão "Criar novo Kanban" e "Gestor do Kanban"; abre Configurações/
   Variáveis/Motivos de Encerramento sem nenhum aviso, consegue editar e salvar normalmente.
3. **Console do navegador**: zero erros/warnings em qualquer uma das páginas visitadas.
4. Criar um 2º Kanban pelo botão "Criar novo Kanban" (como admin), confirmar que ele aparece
   na lista com o apelido certo, e que seu submenu de 7 itens abre normalmente ao clicar nele.

- [ ] **Step 3: Apagar os dados descartáveis e parar o servidor local**

Mesmo processo de ontem — remover o tenant/usuários de teste via tinker, derrubar o
`php artisan serve`.

- [ ] **Step 4: Reportar qualquer divergência encontrada**

Corrigir via TDD (se for bug de backend) ou ajuste direto no Blade/JS (se for só detalhe
visual) antes de prosseguir pro deploy.

---

## Task 11: Deploy

**Files:** nenhum arquivo novo.

- [ ] **Step 1: Pedir confirmação explícita ao Leonardo antes do deploy**

- [ ] **Step 2: Deploy**

```bash
git checkout main
git merge --no-ff <branch-usada> -m "Merge: navegação fixa Kanban + permissão de edição restrita"
bash deploy.sh
```

- [ ] **Step 3: Verificar em produção**

```bash
ssh -i ~/.ssh/leadcerto_vps_nova root@31.97.172.203 "supervisorctl status"
```

Expected: workers `RUNNING`. Conferir o log de produção por alguns minutos (igual a ontem) e
abrir o painel real de um tenant com mais de um perfil disponível pra confirmar visualmente.
