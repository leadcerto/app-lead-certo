# Variações de Mensagem + Timing + Horário de Funcionamento (Fase 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cada mensagem de uma `Sequencia` ganha até 7 versões (1 do humano, protegida, + até 6 geradas por IA), sorteadas no envio; o intervalo entre mensagens ganha jitter (±segundos); e cada sequência pode ter horário de funcionamento com uma sequência de repouso alternativa.

**Architecture:** Nova tabela `sequencia_mensagem_variacoes` (filha de `sequencia_mensagens`) guarda as versões. `SequenciaService::iniciarParaTicket()` — o único ponto de disparo de sequências no sistema — passa a sortear uma variação ativa por mensagem e aplicar jitter/horário antes de enfileirar `SequenciaMensagemJob`. Geração das 6 variações via IA reaproveita `OpenRouterService` no mesmo padrão síncrono de `SequenciaController::sugerirVariaveis()` já existente.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (prod) / SQLite `:memory:` (testes) · Alpine.js v3

## Global Constraints

- Fase 2 (aprendizado contínuo: log de envio, tracking de conversão, sorteio ponderado, substituição automática por baixo desempenho) está **fora de escopo**. Não criar nenhuma task dela.
- Toda variação `protegida = true` (origem humano) nunca pode ser excluída ou desativada por nenhuma rota — nem pelo próprio humano. Validado no backend, não só escondido na UI.
- IA nunca edita o `conteudo` de uma variação já existente — só cria linhas novas.
- Migrations que alteram coluna existente (não apenas adicionar) devem seguir o padrão dual-path já estabelecido no repo: `if (DB::getDriverName() !== 'mysql') { Schema::table(...)->column(...)->change(); return; } DB::statement("ALTER TABLE ... MODIFY ...")`. Este plano só adiciona colunas/tabelas novas, então nenhuma task precisa desse padrão — mas se algum desvio de implementação exigir alterar coluna existente, siga essa regra.
- Todos os models novos seguem o padrão de `SequenciaMensagem`: coluna `tenant_id`, sem `TenantScope` global (o controller filtra manualmente por `$request->user()->tenant_id`, exatamente como todo o resto de `SequenciaController` já faz).
- Rotas novas entram no grupo já existente `Route::middleware('role:admin,dono')` em `routes/web.php`, dentro do bloco "Sequências" (linhas 318-332), prefixo `/api/painel/` (herdado do grupo pai).
- Testes usam `RefreshDatabase` + SQLite `:memory:` (padrão do repo, `phpunit.xml`). Rodar comandos `php artisan` via WSL: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test ..."`.

---

### Task 1: Tabela e model `SequenciaMensagemVariacao`

**Files:**
- Create: `database/migrations/2026_07_21_000001_create_sequencia_mensagem_variacoes_table.php`
- Create: `app/Models/SequenciaMensagemVariacao.php`
- Modify: `app/Models/SequenciaMensagem.php`
- Test: `tests/Feature/SequenciaMensagemVariacaoModelTest.php`

**Interfaces:**
- Produces: `SequenciaMensagemVariacao` model com `$fillable = ['tenant_id', 'sequencia_mensagem_id', 'conteudo', 'origem', 'protegida', 'ativa', 'substituida_em']`, casts `protegida:boolean`, `ativa:boolean`, `substituida_em:datetime`. `SequenciaMensagem::variacoes(): HasMany`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaMensagemVariacaoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_tem_relacao_variacoes(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $variacao = SequenciaMensagemVariacao::create([
            'tenant_id'             => $tenant->id,
            'sequencia_mensagem_id' => $msg->id,
            'conteudo'              => 'Olá {nome}!',
            'origem'                => 'humano',
            'protegida'             => true,
            'ativa'                 => true,
        ]);

        $this->assertCount(1, $msg->variacoes);
        $this->assertTrue($msg->variacoes->first()->is($variacao));
        $this->assertTrue($variacao->protegida);
        $this->assertTrue($variacao->ativa);
        $this->assertSame('humano', $variacao->origem);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaMensagemVariacaoModelTest.php"`
Expected: FAIL — `Class "App\Models\SequenciaMensagemVariacao" not found` (ou tabela inexistente).

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequencia_mensagem_variacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sequencia_mensagem_id')->constrained('sequencia_mensagens')->cascadeOnDelete();
            $table->text('conteudo');
            $table->enum('origem', ['humano', 'ia'])->default('humano');
            $table->boolean('protegida')->default(false);
            $table->boolean('ativa')->default(true);
            $table->timestamp('substituida_em')->nullable();
            $table->timestamps();

            $table->index(['sequencia_mensagem_id', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencia_mensagem_variacoes');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenciaMensagemVariacao extends Model
{
    protected $table = 'sequencia_mensagem_variacoes';

    protected $fillable = [
        'tenant_id',
        'sequencia_mensagem_id',
        'conteudo',
        'origem',
        'protegida',
        'ativa',
        'substituida_em',
    ];

    protected $casts = [
        'protegida'      => 'boolean',
        'ativa'          => 'boolean',
        'substituida_em' => 'datetime',
    ];

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(SequenciaMensagem::class, 'sequencia_mensagem_id');
    }
}
```

- [ ] **Step 5: Add the relation to `SequenciaMensagem`**

In `app/Models/SequenciaMensagem.php`, add the `HasMany` import and the relation method:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function variacoes(): HasMany
    {
        return $this->hasMany(SequenciaMensagemVariacao::class, 'sequencia_mensagem_id');
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaMensagemVariacaoModelTest.php"`
Expected: PASS (1 test, assertions green)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_sequencia_mensagem_variacoes_table.php app/Models/SequenciaMensagemVariacao.php app/Models/SequenciaMensagem.php tests/Feature/SequenciaMensagemVariacaoModelTest.php
git commit -m "feat(sequencias): cria tabela e model de variacoes por mensagem"
```

---

### Task 2: Backfill não-destrutivo (conteudo atual vira versão protegida)

**Files:**
- Create: `database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php`
- Test: `tests/Feature/SequenciaMensagemVariacaoBackfillTest.php`

**Interfaces:**
- Consumes: tabela `sequencia_mensagem_variacoes` (Task 1).
- Produces: toda `SequenciaMensagem` existente com `conteudo` não-vazio ganha exatamente 1 `SequenciaMensagemVariacao` com `origem='humano', protegida=true, ativa=true`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaMensagemVariacaoBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_cria_variacao_protegida_para_mensagem_existente(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        // Mensagem criada ANTES da migration de backfill rodar de novo (simula tenant antigo)
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá {nome}, bem-vindo!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        // A suíte roda em sqlite :memory: com RefreshDatabase reaproveitando o schema já
        // migrado; para exercitar o up() de fato, roda-se rollback + migrate direcionados
        // a essa migration específica (mesmo padrão usado em KanbanColunasBackfillTest).
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);

        $variacao = $msg->variacoes()->first();
        $this->assertNotNull($variacao);
        $this->assertSame('Olá {nome}, bem-vindo!', $variacao->conteudo);
        $this->assertSame('humano', $variacao->origem);
        $this->assertTrue($variacao->protegida);
        $this->assertTrue($variacao->ativa);
    }

    public function test_backfill_nao_cria_variacao_para_mensagem_so_imagem(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => '', 'imagem_url' => 'https://exemplo.com/img.png', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php']);

        $this->assertCount(0, $msg->variacoes()->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaMensagemVariacaoBackfillTest.php"`
Expected: FAIL — `migrate:rollback` errors with "nothing to rollback" / migration file not found (arquivo ainda não existe).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mensagens = DB::table('sequencia_mensagens')->get(['id', 'tenant_id', 'conteudo']);

        foreach ($mensagens as $msg) {
            if (trim((string) $msg->conteudo) === '') {
                continue; // mensagem só-imagem, sem texto pra virar variação
            }

            $jaTemProtegida = DB::table('sequencia_mensagem_variacoes')
                ->where('sequencia_mensagem_id', $msg->id)
                ->where('protegida', true)
                ->exists();

            if ($jaTemProtegida) {
                continue; // idempotente: não duplica se a migration rodar de novo
            }

            DB::table('sequencia_mensagem_variacoes')->insert([
                'tenant_id'              => $msg->tenant_id,
                'sequencia_mensagem_id'  => $msg->id,
                'conteudo'               => $msg->conteudo,
                'origem'                 => 'humano',
                'protegida'              => true,
                'ativa'                  => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sequencia_mensagem_variacoes')
            ->where('origem', 'humano')
            ->where('protegida', true)
            ->delete();
    }
};
```

Nomeie o arquivo `database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaMensagemVariacaoBackfillTest.php"`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_21_000002_backfill_sequencia_mensagem_variacoes.php tests/Feature/SequenciaMensagemVariacaoBackfillTest.php
git commit -m "feat(sequencias): backfill nao-destrutivo de variacoes para mensagens existentes"
```

---

### Task 3: Endpoint de listagem de variações

**Files:**
- Modify: `app/Http/Controllers/Painel/SequenciaController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SequenciaVariacaoControllerTest.php`

**Interfaces:**
- Consumes: `SequenciaMensagem::variacoes()` (Task 1).
- Produces: `GET /api/painel/sequencias/{seq}/mensagens/{msgId}/variacoes` → JSON array de variações (protegida primeiro, depois por id).
- Produces (privado, reaproveitado pelas próximas tasks): `SequenciaController::mensagemDoTenant(Request $request, int $sequenciaId, int $mensagemId): SequenciaMensagem` — busca a mensagem validando que pertence ao tenant logado, `abort(404)` se não encontrar.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaVariacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_variacoes_com_protegida_primeiro(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Oi!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        $humana = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes");

        $response->assertOk();
        $response->assertJsonPath('0.id', $humana->id);
        $response->assertJsonCount(2);
    }

    public function test_404_quando_mensagem_e_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user    = $this->criarUsuarioDono($tenantA);
        $sequenciaB = Sequencia::create(['tenant_id' => $tenantB->id, 'nome' => 'X', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msgB = SequenciaMensagem::create([
            'tenant_id' => $tenantB->id, 'sequencia_id' => $sequenciaB->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequenciaB->id}/mensagens/{$msgB->id}/variacoes");

        $response->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoControllerTest.php"`
Expected: FAIL — 404 rota não existe (`Route [...] not defined` / erro de método).

- [ ] **Step 3: Add the route**

Em `routes/web.php`, dentro do bloco `Route::middleware('role:admin,dono')->group(...)` das Sequências (após a linha `Route::post('/sequencias/{id}/sugerir-variaveis', ...)`, antes do fechamento `});`):

```php
        Route::get('/sequencias/{seq}/mensagens/{msgId}/variacoes',   [SequenciaController::class, 'variacoes']);
```

- [ ] **Step 4: Add the controller method**

Em `app/Http/Controllers/Painel/SequenciaController.php`, adicione o import do model novo:

```php
use App\Models\SequenciaMensagemVariacao;
```

E, logo após o método `sugerirVariaveis()` (antes do fechamento da classe), adicione o helper privado e o endpoint:

```php
    private function mensagemDoTenant(Request $request, int $sequenciaId, int $mensagemId): SequenciaMensagem
    {
        Sequencia::where('id', $sequenciaId)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        return SequenciaMensagem::where('id', $mensagemId)
            ->where('sequencia_id', $sequenciaId)
            ->firstOrFail();
    }

    // ── Variações por mensagem ────────────────────────────────────────────────

    public function variacoes(Request $request, int $sequenciaId, int $msgId): JsonResponse
    {
        $msg = $this->mensagemDoTenant($request, $sequenciaId, $msgId);

        $variacoes = $msg->variacoes()
            ->orderByDesc('protegida')
            ->orderBy('id')
            ->get();

        return response()->json($variacoes);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoControllerTest.php"`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/SequenciaController.php routes/web.php tests/Feature/SequenciaVariacaoControllerTest.php
git commit -m "feat(sequencias): endpoint de listagem de variacoes por mensagem"
```

---

### Task 4: Endpoints de criar/editar/excluir variação (com regras de proteção)

**Files:**
- Modify: `app/Http/Controllers/Painel/SequenciaController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SequenciaVariacaoProtecaoTest.php`

**Interfaces:**
- Consumes: `mensagemDoTenant()` (Task 3).
- Produces: `POST .../variacoes` (cria variação `origem='humano'` sempre — o humano só cria manualmente as suas), `PUT .../variacoes/{id}` (edita `conteudo`/`ativa`; bloqueia `ativa=false` em `protegida`), `DELETE .../variacoes/{id}` (bloqueia com 422 se `protegida=true`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaVariacaoProtecaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    private function criarMensagemComVariacaoProtegida(Tenant $tenant): array
    {
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $protegida = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        return [$sequencia, $msg, $protegida];
    }

    public function test_cria_variacao_manual_como_origem_humano(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->postJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes",
            ['conteudo' => 'Fala comigo!']
        );

        $response->assertCreated();
        $this->assertDatabaseHas('sequencia_mensagem_variacoes', [
            'sequencia_mensagem_id' => $msg->id,
            'conteudo'              => 'Fala comigo!',
            'origem'                => 'humano',
            'protegida'             => false,
        ]);
    }

    public function test_edita_conteudo_de_variacao_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Bom te ver!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$ia->id}",
            ['conteudo' => 'Editado pelo humano']
        );

        $response->assertOk();
        $this->assertSame('Editado pelo humano', $ia->fresh()->conteudo);
    }

    public function test_bloqueia_desativar_variacao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg, $protegida] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}",
            ['ativa' => false]
        );

        $response->assertStatus(422);
        $this->assertTrue($protegida->fresh()->ativa);
    }

    public function test_bloqueia_exclusao_de_variacao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg, $protegida] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->deleteJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}"
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('sequencia_mensagem_variacoes', ['id' => $protegida->id]);
    }

    public function test_exclui_variacao_nao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Bom te ver!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$ia->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('sequencia_mensagem_variacoes', ['id' => $ia->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoProtecaoTest.php"`
Expected: FAIL — rotas inexistentes.

- [ ] **Step 3: Add the routes**

Em `routes/web.php`, logo abaixo da rota adicionada na Task 3:

```php
        Route::post('/sequencias/{seq}/mensagens/{msgId}/variacoes',          [SequenciaController::class, 'storeVariacao']);
        Route::put('/sequencias/{seq}/mensagens/{msgId}/variacoes/{id}',      [SequenciaController::class, 'updateVariacao']);
        Route::delete('/sequencias/{seq}/mensagens/{msgId}/variacoes/{id}',   [SequenciaController::class, 'destroyVariacao']);
```

- [ ] **Step 4: Add the controller methods**

Em `app/Http/Controllers/Painel/SequenciaController.php`, logo após o método `variacoes()` adicionado na Task 3:

```php
    public function storeVariacao(Request $request, int $sequenciaId, int $msgId): JsonResponse
    {
        $msg = $this->mensagemDoTenant($request, $sequenciaId, $msgId);

        $validated = $request->validate([
            'conteudo' => 'required|string|max:1000',
        ]);

        $variacao = SequenciaMensagemVariacao::create([
            'tenant_id'             => $msg->tenant_id,
            'sequencia_mensagem_id' => $msg->id,
            'conteudo'              => $validated['conteudo'],
            'origem'                => 'humano',
            'protegida'             => false,
            'ativa'                 => true,
        ]);

        return response()->json($variacao, 201);
    }

    public function updateVariacao(Request $request, int $sequenciaId, int $msgId, int $id): JsonResponse
    {
        $this->mensagemDoTenant($request, $sequenciaId, $msgId);

        $variacao = SequenciaMensagemVariacao::where('id', $id)
            ->where('sequencia_mensagem_id', $msgId)
            ->firstOrFail();

        $validated = $request->validate([
            'conteudo' => 'sometimes|string|max:1000',
            'ativa'    => 'sometimes|boolean',
        ]);

        if ($variacao->protegida && array_key_exists('ativa', $validated) && ! $validated['ativa']) {
            return response()->json(['message' => 'A versão original não pode ser desativada.'], 422);
        }

        $variacao->update($validated);

        return response()->json($variacao);
    }

    public function destroyVariacao(Request $request, int $sequenciaId, int $msgId, int $id): JsonResponse
    {
        $this->mensagemDoTenant($request, $sequenciaId, $msgId);

        $variacao = SequenciaMensagemVariacao::where('id', $id)
            ->where('sequencia_mensagem_id', $msgId)
            ->firstOrFail();

        if ($variacao->protegida) {
            return response()->json(['message' => 'A versão original não pode ser excluída.'], 422);
        }

        $variacao->delete();

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoProtecaoTest.php"`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/SequenciaController.php routes/web.php tests/Feature/SequenciaVariacaoProtecaoTest.php
git commit -m "feat(sequencias): CRUD de variacoes com regras de protecao da versao humana"
```

---

### Task 5: Geração automática das 6 variações via IA

**Files:**
- Create: `app/Services/SequenciaVariacaoIaService.php`
- Modify: `app/Http/Controllers/Painel/SequenciaController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/SequenciaVariacaoIaServiceTest.php`
- Test: `tests/Feature/SequenciaControllerGeraVariacoesAutomaticoTest.php`

**Interfaces:**
- Consumes: `OpenRouterService::chat(array $messages, string $tier, int $maxTokens, ?string $origem, ?int $tenantId): ?string` (já existe, `app/Services/OpenRouterService.php:33`).
- Produces: `SequenciaVariacaoIaService::gerarVariacoesIniciais(SequenciaMensagem $mensagem): int` (retorna quantas variações criou; 0 se IA falhar ou já existir variação `origem=ia`). `POST .../mensagens/{msgId}/variacoes/gerar` (endpoint manual de regeneração).

- [ ] **Step 1: Write the failing test for the service**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Services\SequenciaVariacaoIaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaVariacaoIaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarMensagem(Tenant $tenant, string $conteudo = 'Olá {nome}, tudo bem?'): SequenciaMensagem
    {
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);

        return SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => $conteudo, 'delay_segundos' => 0, 'ativo' => true,
        ]);
    }

    public function test_gera_6_variacoes_a_partir_da_resposta_da_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);

        $json = json_encode(['variacoes' => [
            ['ordem' => 1, 'conteudo' => 'Oi {nome}, como vai?'],
            ['ordem' => 2, 'conteudo' => 'Fala {nome}, tudo certo?'],
            ['ordem' => 3, 'conteudo' => 'E aí {nome}, beleza?'],
            ['ordem' => 4, 'conteudo' => 'Opa {nome}, tudo bem aí?'],
            ['ordem' => 5, 'conteudo' => 'Olá {nome}, como você está?'],
            ['ordem' => 6, 'conteudo' => 'Oii {nome}, tudo joia?'],
        ]]);

        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(6, $criadas);
        $this->assertSame(6, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->where('origem', 'ia')->count());
    }

    public function test_nao_gera_de_novo_se_ja_existe_variacao_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Já existente', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        Http::fake(); // se chamar a IA, o teste falha por request inesperada não fakeada com corpo

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        Http::assertNothingSent();
    }

    public function test_falha_da_ia_nao_quebra_e_retorna_zero(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant);

        Http::fake(['openrouter.ai/*' => Http::response('erro', 500)]);

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        $this->assertSame(0, SequenciaMensagemVariacao::where('sequencia_mensagem_id', $msg->id)->count());
    }

    public function test_mensagem_sem_conteudo_nao_gera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $msg    = $this->criarMensagem($tenant, '');

        Http::fake();

        $criadas = app(SequenciaVariacaoIaService::class)->gerarVariacoesIniciais($msg);

        $this->assertSame(0, $criadas);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoIaServiceTest.php"`
Expected: FAIL — `Class "App\Services\SequenciaVariacaoIaService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use Illuminate\Support\Facades\Log;

class SequenciaVariacaoIaService
{
    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Gera as até 6 variações iniciais de uma mensagem, com base no conteúdo
     * escrito pelo humano. Não faz nada se a mensagem não tem texto ou se já
     * existe alguma variação de origem IA (evita duplicar geração).
     */
    public function gerarVariacoesIniciais(SequenciaMensagem $mensagem): int
    {
        if (trim((string) $mensagem->conteudo) === '') {
            return 0;
        }

        $jaTemIa = $mensagem->variacoes()->where('origem', 'ia')->exists();
        if ($jaTemIa) {
            return 0;
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você é especialista em copywriting conversacional para WhatsApp. Sua única função é gerar variações de uma mensagem original, preservando objetivo e variáveis. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
MENSAGEM ORIGINAL (IMUTÁVEL):
"{$mensagem->conteudo}"

TAREFA: gere exatamente 6 variações desta mensagem.

REGRAS OBRIGATÓRIAS:
1. Preserve todas as {variaveis} exatamente como estão escritas — nunca substitua, remova ou renomeie uma variável.
2. Mantenha o mesmo objetivo comunicacional da mensagem original.
3. Varie apenas: estrutura da frase, abertura emocional, nível de formalidade (até 1 grau acima ou abaixo), comprimento (até 20% maior ou menor).
4. Não invente informações, promessas ou dados que não existam na mensagem original.
5. Não use mais emojis que o original.
6. Cada variação deve funcionar de forma autônoma.
7. Não numere nem explique — retorne apenas o texto de cada variação.

FORMATO DE SAÍDA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
{"variacoes": [{"ordem": 1, "conteudo": "..."}, {"ordem": 2, "conteudo": "..."}, {"ordem": 3, "conteudo": "..."}, {"ordem": 4, "conteudo": "..."}, {"ordem": 5, "conteudo": "..."}, {"ordem": 6, "conteudo": "..."}]}
PROMPT,
            ],
        ];

        $resposta = $this->openRouter->chat($messages, 'complexo', 2000, 'sequencia_variacoes_iniciais', $mensagem->tenant_id);

        if (! $resposta) {
            Log::warning('SequenciaVariacaoIaService: IA indisponível ao gerar variações iniciais', ['mensagem_id' => $mensagem->id]);
            return 0;
        }

        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);
        $lista = $json['variacoes'] ?? null;

        if (! is_array($lista) || count($lista) === 0) {
            Log::warning('SequenciaVariacaoIaService: resposta IA não é JSON válido', [
                'mensagem_id' => $mensagem->id,
                'resposta'    => mb_substr($resposta, 0, 500),
            ]);
            return 0;
        }

        $criadas = 0;
        foreach ($lista as $item) {
            $texto = trim((string) ($item['conteudo'] ?? ''));
            if ($texto === '') {
                continue;
            }

            SequenciaMensagemVariacao::create([
                'tenant_id'             => $mensagem->tenant_id,
                'sequencia_mensagem_id' => $mensagem->id,
                'conteudo'              => $texto,
                'origem'                => 'ia',
                'protegida'             => false,
                'ativa'                 => true,
            ]);
            $criadas++;
        }

        return $criadas;
    }

    /**
     * Regeneração manual: desativa (soft) as variações IA atuais e gera 6 novas.
     * Usada pelo endpoint de "regenerar variações" quando o humano edita a
     * mensagem original e quer variações atualizadas.
     */
    public function regenerar(SequenciaMensagem $mensagem): int
    {
        $mensagem->variacoes()
            ->where('origem', 'ia')
            ->where('ativa', true)
            ->update(['ativa' => false, 'substituida_em' => now()]);

        return $this->gerarVariacoesIniciaisForcado($mensagem);
    }

    /** Igual a gerarVariacoesIniciais, mas ignora o guard de "já tem IA" — usado só pelo regenerar(). */
    private function gerarVariacoesIniciaisForcado(SequenciaMensagem $mensagem): int
    {
        $mensagem->unsetRelation('variacoes');

        if (trim((string) $mensagem->conteudo) === '') {
            return 0;
        }

        // Reaproveita a mesma lógica de geração; como as variações IA antigas
        // acabaram de ser desativadas, o guard "já tem IA ativa" não se aplica
        // ao contarmos apenas ativas — mas gerarVariacoesIniciais() checa
        // existência de QUALQUER variação IA (ativa ou não). Por isso este
        // método duplica a chamada à IA em vez de reusar o público.
        return $this->chamarIaEGerar($mensagem);
    }

    private function chamarIaEGerar(SequenciaMensagem $mensagem): int
    {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Você é especialista em copywriting conversacional para WhatsApp. Sua única função é gerar variações de uma mensagem original, preservando objetivo e variáveis. Responde SEMPRE com JSON válido.',
            ],
            [
                'role'    => 'user',
                'content' => <<<PROMPT
MENSAGEM ORIGINAL (IMUTÁVEL):
"{$mensagem->conteudo}"

TAREFA: gere exatamente 6 variações desta mensagem.

REGRAS OBRIGATÓRIAS:
1. Preserve todas as {variaveis} exatamente como estão escritas — nunca substitua, remova ou renomeie uma variável.
2. Mantenha o mesmo objetivo comunicacional da mensagem original.
3. Varie apenas: estrutura da frase, abertura emocional, nível de formalidade (até 1 grau acima ou abaixo), comprimento (até 20% maior ou menor).
4. Não invente informações, promessas ou dados que não existam na mensagem original.
5. Não use mais emojis que o original.
6. Cada variação deve funcionar de forma autônoma.
7. Não numere nem explique — retorne apenas o texto de cada variação.

FORMATO DE SAÍDA — retorne SOMENTE este JSON, sem markdown, sem explicação adicional:
{"variacoes": [{"ordem": 1, "conteudo": "..."}, {"ordem": 2, "conteudo": "..."}, {"ordem": 3, "conteudo": "..."}, {"ordem": 4, "conteudo": "..."}, {"ordem": 5, "conteudo": "..."}, {"ordem": 6, "conteudo": "..."}]}
PROMPT,
            ],
        ];

        $resposta = $this->openRouter->chat($messages, 'complexo', 2000, 'sequencia_variacoes_regeneracao', $mensagem->tenant_id);

        if (! $resposta) {
            Log::warning('SequenciaVariacaoIaService: IA indisponível ao regenerar variações', ['mensagem_id' => $mensagem->id]);
            return 0;
        }

        $limpo = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $resposta));
        $json  = json_decode($limpo, true);
        $lista = $json['variacoes'] ?? null;

        if (! is_array($lista) || count($lista) === 0) {
            Log::warning('SequenciaVariacaoIaService: resposta IA não é JSON válido na regeneração', [
                'mensagem_id' => $mensagem->id,
                'resposta'    => mb_substr($resposta, 0, 500),
            ]);
            return 0;
        }

        $criadas = 0;
        foreach ($lista as $item) {
            $texto = trim((string) ($item['conteudo'] ?? ''));
            if ($texto === '') {
                continue;
            }

            SequenciaMensagemVariacao::create([
                'tenant_id'             => $mensagem->tenant_id,
                'sequencia_mensagem_id' => $mensagem->id,
                'conteudo'              => $texto,
                'origem'                => 'ia',
                'protegida'             => false,
                'ativa'                 => true,
            ]);
            $criadas++;
        }

        return $criadas;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaVariacaoIaServiceTest.php"`
Expected: PASS (4 tests)

- [ ] **Step 5: Write the failing test for automatic trigger on storeMensagem + manual regenerate endpoint**

```php
<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaControllerGeraVariacoesAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    private function fakeIaComSeisVariacoes(): void
    {
        $json = json_encode(['variacoes' => [
            ['ordem' => 1, 'conteudo' => 'V1'], ['ordem' => 2, 'conteudo' => 'V2'],
            ['ordem' => 3, 'conteudo' => 'V3'], ['ordem' => 4, 'conteudo' => 'V4'],
            ['ordem' => 5, 'conteudo' => 'V5'], ['ordem' => 6, 'conteudo' => 'V6'],
        ]]);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    public function test_storeMensagem_dispara_geracao_automatica_das_variacoes(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $this->fakeIaComSeisVariacoes();

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0,
        ]);

        $response->assertCreated();
        $msg = SequenciaMensagem::first();
        $this->assertSame(1, $msg->variacoes()->where('origem', 'humano')->where('protegida', true)->count());
        $this->assertSame(6, $msg->variacoes()->where('origem', 'ia')->count());
    }

    public function test_storeMensagem_nao_falha_quando_ia_indisponivel(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        Http::fake(['openrouter.ai/*' => Http::response('erro', 500)]);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0,
        ]);

        $response->assertCreated();
        $msg = SequenciaMensagem::first();
        $this->assertSame(1, $msg->variacoes()->where('protegida', true)->count());
        $this->assertSame(0, $msg->variacoes()->where('origem', 'ia')->count());
    }

    public function test_endpoint_de_regeneracao_manual_substitui_variacoes_ia(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $antiga = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Antiga', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        $this->fakeIaComSeisVariacoes();

        $response = $this->actingAs($user)->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/gerar");

        $response->assertOk();
        $this->assertFalse($antiga->fresh()->ativa);
        $this->assertSame(6, $msg->variacoes()->where('origem', 'ia')->where('ativa', true)->count());
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaControllerGeraVariacoesAutomaticoTest.php"`
Expected: FAIL — nenhuma variação IA criada automaticamente; rota `.../variacoes/gerar` inexistente.

- [ ] **Step 7: Wire the automatic call into `storeMensagem` and add the manual endpoint**

Em `app/Http/Controllers/Painel/SequenciaController.php`, adicione o import:

```php
use App\Services\SequenciaVariacaoIaService;
```

Modifique a assinatura de `storeMensagem` para receber o serviço injetado, e adicione a criação da variação protegida + disparo automático logo após criar `$msg`:

```php
    public function storeMensagem(Request $request, int $sequenciaId, SequenciaVariacaoIaService $variacaoIa): JsonResponse
    {
```

(a assinatura original tinha só `Request $request, int $sequenciaId` — mantenha todo o corpo do método igual até o `$msg = SequenciaMensagem::create([...]);`, e logo depois dele adicione:)

```php
        if (! empty($validated['conteudo'])) {
            SequenciaMensagemVariacao::create([
                'tenant_id'             => $msg->tenant_id,
                'sequencia_mensagem_id' => $msg->id,
                'conteudo'              => $validated['conteudo'],
                'origem'                => 'humano',
                'protegida'             => true,
                'ativa'                 => true,
            ]);

            $variacaoIa->gerarVariacoesIniciais($msg);
        }

        return response()->json($msg, 201);
    }
```

(remova o antigo `return response()->json($msg, 201);` duplicado que já existia no final do método — deve sobrar só esta versão, após o bloco acima).

Adicione o endpoint de regeneração manual, logo após `destroyVariacao()`:

```php
    public function gerarVariacoes(Request $request, int $sequenciaId, int $msgId, SequenciaVariacaoIaService $variacaoIa): JsonResponse
    {
        $msg = $this->mensagemDoTenant($request, $sequenciaId, $msgId);

        $criadas = $variacaoIa->regenerar($msg);

        if ($criadas === 0) {
            return response()->json(['message' => 'IA temporariamente indisponível. Tente novamente em instantes.'], 503);
        }

        return response()->json(['criadas' => $criadas]);
    }
```

- [ ] **Step 8: Add the route**

Em `routes/web.php`, logo abaixo das rotas de variação adicionadas na Task 4:

```php
        Route::post('/sequencias/{seq}/mensagens/{msgId}/variacoes/gerar', [SequenciaController::class, 'gerarVariacoes']);
```

- [ ] **Step 9: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaControllerGeraVariacoesAutomaticoTest.php tests/Feature/SequenciaControllerButtonSettingsTest.php"`
Expected: PASS em todos (a segunda suíte confirma que `storeMensagem` continua funcionando para o fluxo já existente de botões).

- [ ] **Step 10: Commit**

```bash
git add app/Services/SequenciaVariacaoIaService.php app/Http/Controllers/Painel/SequenciaController.php routes/web.php tests/Feature/SequenciaVariacaoIaServiceTest.php tests/Feature/SequenciaControllerGeraVariacoesAutomaticoTest.php
git commit -m "feat(sequencias): geracao automatica das 6 variacoes via IA + regeneracao manual"
```

---

### Task 6: Sorteio de variação ativa no disparo da sequência

**Files:**
- Modify: `app/Services/SequenciaService.php`
- Test: `tests/Feature/SequenciaServiceSorteiaVariacaoTest.php`

**Interfaces:**
- Consumes: `SequenciaMensagem::variacoes()` (Task 1).
- Produces: `SequenciaService::iniciarParaTicket()` passa o conteúdo de uma variação ativa sorteada (não mais `$msg->conteudo` fixo) para `SequenciaMensagemJob::dispatch()`. Se não houver nenhuma variação ativa (mensagem antiga sem backfill, ou todas desativadas), cai de volta em `$msg->conteudo` — nunca quebra o envio.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SequenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenciaServiceSorteiaVariacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_dispara_com_conteudo_de_variacao_ativa_sorteada(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Original nunca deve sair daqui', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Variação única ativa', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Variação desativada, nunca deve sair daqui', 'origem' => 'ia', 'protegida' => false, 'ativa' => false,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Variação única ativa');
    }

    public function test_cai_para_conteudo_da_mensagem_quando_nao_ha_variacao_ativa(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Sem variação cadastrada ainda', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Sem variação cadastrada ainda');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceSorteiaVariacaoTest.php"`
Expected: FAIL no primeiro teste — hoje sempre dispara com `$msg->conteudo` ("Original nunca deve sair daqui"), nunca com a variação.

- [ ] **Step 3: Update `SequenciaService`**

Substitua o conteúdo de `app/Services/SequenciaService.php` por:

```php
<?php

namespace App\Services;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Sequencia;
use App\Models\TicketAtendimento;

class SequenciaService
{
    public function iniciarParaTicket(TicketAtendimento $ticket): bool
    {
        $sequencias = Sequencia::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->with(['mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                'variacoes' => fn ($q2) => $q2->where('ativa', true),
            ])])
            ->get();

        $disparou       = false;
        $delayAcumulado = 0;

        foreach ($sequencias as $sequencia) {
            foreach ($sequencia->mensagens as $msg) {
                $delayAcumulado += $msg->delay_segundos;

                $variacao = $msg->variacoes->count() > 0
                    ? $msg->variacoes->random()
                    : null;
                $conteudo = $variacao?->conteudo ?? $msg->conteudo;

                SequenciaMensagemJob::dispatch(
                    $ticket->id,
                    $conteudo,
                    $msg->imagem_url,
                    $sequencia->coluna_kanban,
                    $msg->button_settings ?: null,
                    (bool) $msg->obrigatorio,
                )
                    ->onQueue('default')
                    ->delay(now()->addSeconds($delayAcumulado));
                $disparou = true;
            }
        }

        return $disparou;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceSorteiaVariacaoTest.php"`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the pre-existing sequência tests to confirm no regression**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceBotoesPorMensagemTest.php tests/Feature/SequenciaMensagemJobObrigatorioTest.php tests/Feature/SequenciaMensagemJobOptOutTest.php"`
Expected: PASS em todos (nenhum destes assume um `conteudo` fixo vindo direto de `$msg->conteudo` sem variação — confirme lendo cada teste antes de rodar; se algum falhar, ajuste o teste para criar uma variação `protegida` com o mesmo texto usado hoje, replicando o comportamento do backfill da Task 2).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SequenciaService.php tests/Feature/SequenciaServiceSorteiaVariacaoTest.php
git commit -m "feat(sequencias): sorteia variacao ativa no disparo, com fallback pro conteudo fixo"
```

---

### Task 7: Timing variável (jitter)

**Files:**
- Create: `database/migrations/2026_07_21_000003_add_delay_jitter_segundos_to_sequencia_mensagens.php`
- Modify: `app/Models/SequenciaMensagem.php`
- Modify: `app/Services/SequenciaService.php`
- Modify: `app/Http/Controllers/Painel/SequenciaController.php`
- Test: `tests/Feature/SequenciaServiceJitterTest.php`

**Interfaces:**
- Produces: coluna `delay_jitter_segundos` (unsignedInteger, default 0) em `sequencia_mensagens`. Delay real de disparo = `delay_segundos ± random(0, delay_jitter_segundos)`, nunca negativo.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SequenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenciaServiceJitterTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_delay_fica_dentro_da_janela_de_jitter(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Oi!', 'delay_segundos' => 10, 'delay_jitter_segundos' => 5, 'ativo' => true,
        ]);

        $agora = now();
        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, function ($job, $queue, $pushed) use ($agora) {
            $delayReal = $pushed->delay->diffInSeconds($agora, false);
            return $delayReal >= 5 && $delayReal <= 15;
        });
    }

    public function test_delay_sem_jitter_configurado_fica_exato(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Oi!', 'delay_segundos' => 10, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        $this->assertDatabaseHas('sequencia_mensagens', ['delay_jitter_segundos' => 0]);
    }
}
```

> Nota: `Queue::fake()` grava o `delay` calculado no momento de `->delay(...)`; a asserção usa `$pushed->delay` (propriedade pública do job com o `Carbon` do delay, exposta via `Illuminate\Foundation\Bus\PendingDispatch`/`Queueable`). Se essa propriedade não estiver acessível dessa forma na versão do Laravel do projeto, ajuste a asserção para capturar o delay via `Queue::assertPushed(SequenciaMensagemJob::class, function ($job) { ... })` combinado com `$job->delay` diretamente no job (o trait `Queueable` expõe `$job->delay` como propriedade pública) — teste a asserção mais simples primeiro (`$job->delay !== null`) e refine a partir do erro real reportado pelo PHPUnit.

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceJitterTest.php"`
Expected: FAIL — coluna `delay_jitter_segundos` não existe.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->unsignedInteger('delay_jitter_segundos')->default(0)->after('delay_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->dropColumn('delay_jitter_segundos');
        });
    }
};
```

- [ ] **Step 4: Add to model `$fillable`**

Em `app/Models/SequenciaMensagem.php`, adicione `'delay_jitter_segundos'` logo após `'delay_segundos'` no array `$fillable`.

- [ ] **Step 5: Apply jitter in `SequenciaService`**

Em `app/Services/SequenciaService.php`, troque a linha `$delayAcumulado += $msg->delay_segundos;` por:

```php
                $jitter = $msg->delay_jitter_segundos > 0
                    ? random_int(-$msg->delay_jitter_segundos, $msg->delay_jitter_segundos)
                    : 0;
                $delayAcumulado += max(0, $msg->delay_segundos + $jitter);
```

- [ ] **Step 6: Accept the new field in `storeMensagem`/`updateMensagem` validation**

Em `app/Http/Controllers/Painel/SequenciaController.php`, adicione `'delay_jitter_segundos' => 'sometimes|integer|min:0|max:3600',` na regra de validação de `storeMensagem()` (logo após `'delay_segundos'`) e em `updateMensagem()` (mesma posição), e inclua `$validated['delay_jitter_segundos'] ?? 0` no array passado a `SequenciaMensagem::create()` em `storeMensagem()`.

- [ ] **Step 7: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceJitterTest.php"`
Expected: PASS (2 tests) — se a asserção do Step 1 precisar de ajuste por causa da API interna do `Queueable`, ajuste-a agora conforme a nota, sem mudar a lógica de produção.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_21_000003_add_delay_jitter_segundos_to_sequencia_mensagens.php app/Models/SequenciaMensagem.php app/Services/SequenciaService.php app/Http/Controllers/Painel/SequenciaController.php tests/Feature/SequenciaServiceJitterTest.php
git commit -m "feat(sequencias): timing variavel (jitter) entre mensagens da sequencia"
```

---

### Task 8: Extensão da biblioteca de variáveis (`SpintaxVariavel::$defaults`)

**Files:**
- Modify: `app/Models/SpintaxVariavel.php`
- Test: `tests/Feature/SpintaxVariavelDefaultsTest.php`

**Interfaces:**
- Produces: `SpintaxVariavel::$defaults` ganha as chaves `saudacao`, `despedida`, `cta`, `gancho`, `prova_social`, `urgencia`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\SpintaxVariavel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpintaxVariavelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_novas_variaveis_padrao_existem_com_pelo_menos_3_opcoes(): void
    {
        foreach (['saudacao', 'despedida', 'cta', 'gancho', 'prova_social', 'urgencia'] as $nome) {
            $this->assertArrayHasKey($nome, SpintaxVariavel::$defaults, "Falta a variável padrão '{$nome}'");
            $opcoes = array_filter(array_map('trim', explode("\n", SpintaxVariavel::$defaults[$nome]['opcoes'])));
            $this->assertGreaterThanOrEqual(3, count($opcoes), "'{$nome}' precisa de pelo menos 3 opções");
            $this->assertNotEmpty(SpintaxVariavel::$defaults[$nome]['label']);
        }
    }

    public function test_sorteio_de_variavel_padrao_nova_funciona_para_tenant_sem_customizacao(): void
    {
        $resultado = SpintaxVariavel::sorteio(999999, 'saudacao');

        $this->assertNotSame('', $resultado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SpintaxVariavelDefaultsTest.php"`
Expected: FAIL — `saudacao` etc. ainda não existem em `$defaults`.

- [ ] **Step 3: Extend `$defaults`**

Em `app/Models/SpintaxVariavel.php`, adicione estas 6 entradas ao array `public static array $defaults`, logo após a entrada `'termo_servico'`:

```php
        'saudacao' => [
            'label'  => 'Saudação',
            'opcoes' => "Olá\nBom te ver por aqui\nFala comigo\nOi\nOpa, tudo certo?\nTudo bem?",
        ],
        'despedida' => [
            'label'  => 'Despedida',
            'opcoes' => "Um abraço!\nAté logo!\nQualquer dúvida, me chama.\nFico à disposição.\nTamo junto!",
        ],
        'cta' => [
            'label'  => 'Chamada para ação',
            'opcoes' => "Clique aqui:\nAcesse o link:\nDá uma olhada aqui:\nVeja neste link:\nSegue o link de acesso:",
        ],
        'gancho' => [
            'label'  => 'Gancho de abertura',
            'opcoes' => "Vi que você se interessou\nNotei seu interesse\nRecebi seu contato\nQue legal ver seu interesse por aqui",
        ],
        'prova_social' => [
            'label'  => 'Prova social',
            'opcoes' => "Nossa equipe já atendeu centenas de clientes satisfeitos.\nSomos referência na região nesse serviço.\nTemos avaliação nota máxima dos nossos clientes.",
        ],
        'urgencia' => [
            'label'  => 'Urgência',
            'opcoes' => "Nossa agenda está se esgotando rápido.\nA disponibilidade pra essa semana está apertada.\nQuero garantir sua vaga antes que a agenda feche.",
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SpintaxVariavelDefaultsTest.php"`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/SpintaxVariavel.php tests/Feature/SpintaxVariavelDefaultsTest.php
git commit -m "feat(sequencias): adiciona saudacao/despedida/cta/gancho/prova_social/urgencia aos defaults"
```

---

### Task 9: Horário de funcionamento + sequência de repouso

**Files:**
- Create: `database/migrations/2026_07_21_000004_add_horario_funcionamento_to_sequencias.php`
- Modify: `app/Models/Sequencia.php`
- Modify: `app/Services/SequenciaService.php`
- Modify: `app/Http/Controllers/Painel/SequenciaController.php`
- Test: `tests/Feature/SequenciaServiceHorarioFuncionamentoTest.php`

**Interfaces:**
- Produces: `Sequencia` ganha `horario_ativo` (bool, default false), `horario_inicio`/`horario_fim` (time, nullable), `sequencia_repouso_id` (FK nullable self-referencing). `Sequencia::sequenciaRepouso(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SequenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SequenciaServiceHorarioFuncionamentoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_dentro_do_horario_dispara_a_sequencia_principal_normalmente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Mensagem do horário comercial', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Mensagem do horário comercial');
        Carbon::setTestNow();
    }

    public function test_fora_do_horario_usa_sequencia_de_repouso_quando_configurada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket   = $this->criarTicket();
        $repouso  = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Repouso', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $repouso->id, 'ordem' => 1,
            'conteudo' => 'Mensagem de repouso', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
            'sequencia_repouso_id' => $repouso->id,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Nunca deve disparar às 23h', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Mensagem de repouso');
        Queue::assertNotPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Nunca deve disparar às 23h');
        Carbon::setTestNow();
    }

    public function test_fora_do_horario_sem_repouso_adia_para_o_proximo_inicio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Adiada pro próximo horário', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        // Próximo início: amanhã (2026-07-22) às 08:00 America/Sao_Paulo — pelo menos 9h de delay a partir das 23h de hoje.
        Queue::assertPushed(SequenciaMensagemJob::class, function ($job) {
            return $job->conteudo === 'Adiada pro próximo horário';
        });
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceHorarioFuncionamentoTest.php"`
Expected: FAIL — colunas `horario_ativo`/`horario_inicio`/`horario_fim`/`sequencia_repouso_id` não existem.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencias', function (Blueprint $table) {
            $table->boolean('horario_ativo')->default(false)->after('ativo');
            $table->time('horario_inicio')->nullable()->after('horario_ativo');
            $table->time('horario_fim')->nullable()->after('horario_inicio');
            $table->foreignId('sequencia_repouso_id')->nullable()->after('horario_fim')
                ->constrained('sequencias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sequencias', function (Blueprint $table) {
            $table->dropForeign(['sequencia_repouso_id']);
            $table->dropColumn(['horario_ativo', 'horario_inicio', 'horario_fim', 'sequencia_repouso_id']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

Em `app/Models/Sequencia.php`, atualize `$fillable` e `$casts`, e adicione a relação:

```php
    protected $fillable = [
        'tenant_id',
        'nome',
        'descricao',
        'coluna_kanban',
        'ativo',
        'horario_ativo',
        'horario_inicio',
        'horario_fim',
        'sequencia_repouso_id',
    ];

    protected $casts = [
        'ativo'         => 'boolean',
        'horario_ativo' => 'boolean',
    ];
```

E o método de relação, logo após `mensagens()`:

```php
    public function sequenciaRepouso(): BelongsTo
    {
        return $this->belongsTo(Sequencia::class, 'sequencia_repouso_id');
    }
```

(`BelongsTo` já está importado no arquivo.)

- [ ] **Step 5: Update `SequenciaService` to resolve the window**

Substitua novamente o conteúdo de `app/Services/SequenciaService.php` por:

```php
<?php

namespace App\Services;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Sequencia;
use App\Models\TicketAtendimento;
use Illuminate\Support\Carbon;

class SequenciaService
{
    public function iniciarParaTicket(TicketAtendimento $ticket): bool
    {
        $sequencias = Sequencia::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->with([
                'mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                    'variacoes' => fn ($q2) => $q2->where('ativa', true),
                ]),
                'sequenciaRepouso.mensagens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')->with([
                    'variacoes' => fn ($q2) => $q2->where('ativa', true),
                ]),
            ])
            ->get();

        $disparou = false;

        foreach ($sequencias as $sequencia) {
            [$sequenciaEfetiva, $inicioBase] = $this->resolverJanela($sequencia);

            if (! $sequenciaEfetiva) {
                continue; // horário ativo, fora da janela, sem repouso configurado — tratado por resolverJanela via adiamento, então isso não deveria ocorrer; guarda defensiva
            }

            $delayAcumulado = max(0, (int) now()->diffInSeconds($inicioBase, false));

            foreach ($sequenciaEfetiva->mensagens as $msg) {
                $jitter = $msg->delay_jitter_segundos > 0
                    ? random_int(-$msg->delay_jitter_segundos, $msg->delay_jitter_segundos)
                    : 0;
                $delayAcumulado += max(0, $msg->delay_segundos + $jitter);

                $variacao = $msg->variacoes->count() > 0 ? $msg->variacoes->random() : null;
                $conteudo = $variacao?->conteudo ?? $msg->conteudo;

                SequenciaMensagemJob::dispatch(
                    $ticket->id,
                    $conteudo,
                    $msg->imagem_url,
                    $sequencia->coluna_kanban,
                    $msg->button_settings ?: null,
                    (bool) $msg->obrigatorio,
                )
                    ->onQueue('default')
                    ->delay(now()->addSeconds($delayAcumulado));
                $disparou = true;
            }
        }

        return $disparou;
    }

    /**
     * Decide qual sequência efetivamente disparar (a principal ou a de repouso)
     * e a partir de que instante começar a contar o delay das mensagens.
     *
     * - horario_ativo = false: sempre a principal, a partir de agora.
     * - dentro da janela [horario_inicio, horario_fim] (fuso America/Sao_Paulo): principal, a partir de agora.
     * - fora da janela, com sequencia_repouso configurada: a de repouso, a partir de agora.
     * - fora da janela, sem repouso: a principal, mas a partir do próximo horario_inicio.
     *
     * @return array{0: Sequencia, 1: Carbon}
     */
    private function resolverJanela(Sequencia $sequencia): array
    {
        if (! $sequencia->horario_ativo || ! $sequencia->horario_inicio || ! $sequencia->horario_fim) {
            return [$sequencia, now()];
        }

        $agora   = now()->timezone('America/Sao_Paulo');
        $inicio  = $agora->copy()->setTimeFromTimeString($sequencia->horario_inicio);
        $fim     = $agora->copy()->setTimeFromTimeString($sequencia->horario_fim);

        if ($agora->between($inicio, $fim)) {
            return [$sequencia, now()];
        }

        if ($sequencia->sequenciaRepouso) {
            return [$sequencia->sequenciaRepouso, now()];
        }

        $proximoInicio = $agora->lessThan($inicio) ? $inicio : $inicio->addDay();

        return [$sequencia, $proximoInicio];
    }
}
```

- [ ] **Step 6: Accept the new fields in `store`/`update`**

Em `app/Http/Controllers/Painel/SequenciaController.php`, adicione ao array de validação de `store()`:

```php
            'horario_ativo'         => 'sometimes|boolean',
            'horario_inicio'        => 'nullable|required_if:horario_ativo,true|date_format:H:i',
            'horario_fim'           => 'nullable|required_if:horario_ativo,true|date_format:H:i',
            'sequencia_repouso_id'  => 'nullable|integer|exists:sequencias,id',
```

E o mesmo bloco (com `sometimes` em vez de `required_if` implícito continuando igual, já que `sometimes` já cobre parcialidade) em `update()`.

- [ ] **Step 7: Run test to verify it passes**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test tests/Feature/SequenciaServiceHorarioFuncionamentoTest.php"`
Expected: PASS (3 tests)

- [ ] **Step 8: Run all sequência-related tests together to confirm no regression**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test --filter=Sequencia"`
Expected: todos verdes.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_21_000004_add_horario_funcionamento_to_sequencias.php app/Models/Sequencia.php app/Services/SequenciaService.php app/Http/Controllers/Painel/SequenciaController.php tests/Feature/SequenciaServiceHorarioFuncionamentoTest.php
git commit -m "feat(sequencias): horario de funcionamento com sequencia de repouso"
```

---

### Task 10: Frontend — abas de variação por mensagem

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `GET .../mensagens/{msgId}/variacoes`, `POST .../variacoes`, `PUT .../variacoes/{id}`, `DELETE .../variacoes/{id}`, `POST .../variacoes/gerar` (Tasks 3-5).

Este é um task de UI sem infraestrutura de teste automatizado no projeto para Alpine.js (o restante do `config.blade.php` também não tem testes de frontend — confirmado pelas Tasks 20/21 do plano de Kanban Colunas Dinâmicas, que documentaram a mesma limitação: sem MySQL rodando localmente, não há como abrir uma sessão autenticada no navegador neste ambiente). Trate como um task de implementação direta, revisado por leitura de código, com uma ressalva explícita de "precisa de 1 passada manual no navegador antes de liberar pra usuários" — não declare este task como verificado visualmente.

- [ ] **Step 1: Add Alpine state for variations**

Em `resources/views/kanban/config.blade.php`, localize o bloco de dados do componente Alpine (onde vivem `mensagensPor`, `editandoMsgId`, etc. — mesmo objeto de dados usado por `carregarMsgs()`) e adicione as novas propriedades de estado:

```js
        variacoesPor: {},       // { [mensagemId]: [ {id, conteudo, origem, protegida, ativa}, ... ] }
        abaVariacaoAberta: {},  // { [mensagemId]: bool } — controla se o painel de variações está expandido
        gerandoVariacoes: {},   // { [mensagemId]: bool } — spinner durante chamada IA
        novaVariacaoTexto: {},  // { [mensagemId]: string } — campo de criação manual de variação
```

- [ ] **Step 2: Add the methods**

Adicione estes métodos ao mesmo objeto Alpine, próximo aos outros métodos de mensagem (`toggleAtivoMsg`, `excluirMsg`):

```js
        async carregarVariacoes(seqId, msg) {
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}/variacoes`);
            this.variacoesPor[msg.id] = res.ok ? await res.json() : [];
        },

        async toggleAbaVariacoes(seqId, msg) {
            const abrindo = !this.abaVariacaoAberta[msg.id];
            this.abaVariacaoAberta[msg.id] = abrindo;
            if (abrindo && !this.variacoesPor[msg.id]) {
                await this.carregarVariacoes(seqId, msg);
            }
        },

        async gerarVariacoes(seqId, msg) {
            this.gerandoVariacoes[msg.id] = true;
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}/variacoes/gerar`, 'POST');
            this.gerandoVariacoes[msg.id] = false;
            if (res.ok) {
                await this.carregarVariacoes(seqId, msg);
                this.mostrarToast('Variações geradas com sucesso!', 'sucesso');
            } else {
                const erro = await res.json().catch(() => null);
                this.mostrarToast(erro?.message || 'Não foi possível gerar variações agora.', 'erro');
            }
        },

        async adicionarVariacaoManual(seqId, msg) {
            const conteudo = (this.novaVariacaoTexto[msg.id] || '').trim();
            if (!conteudo) return;
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}/variacoes`, 'POST', { conteudo });
            if (res.ok) {
                this.novaVariacaoTexto[msg.id] = '';
                await this.carregarVariacoes(seqId, msg);
            } else {
                this.mostrarToast('Não foi possível adicionar a variação.', 'erro');
            }
        },

        async toggleAtivaVariacao(seqId, msg, variacao) {
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}/variacoes/${variacao.id}`, 'PUT', { ativa: !variacao.ativa });
            if (res.ok) {
                await this.carregarVariacoes(seqId, msg);
            } else {
                const erro = await res.json().catch(() => null);
                this.mostrarToast(erro?.message || 'Esta versão não pode ser desativada.', 'erro');
            }
        },

        async excluirVariacao(seqId, msg, variacao) {
            if (!confirm('Excluir esta variação?')) return;
            const res = await this.api(`/api/painel/sequencias/${seqId}/mensagens/${msg.id}/variacoes/${variacao.id}`, 'DELETE');
            if (res.ok) {
                await this.carregarVariacoes(seqId, msg);
            } else {
                const erro = await res.json().catch(() => null);
                this.mostrarToast(erro?.message || 'Esta versão não pode ser excluída.', 'erro');
            }
        },
```

- [ ] **Step 3: Add the UI block**

Em `resources/views/kanban/config.blade.php`, dentro do `<template x-for="(msg, idx) in (mensagensPor[seq.id] || [])" ...>` (o mesmo bloco lido nas linhas 174-260 do arquivo atual), logo depois do `</div>` que fecha a área de exibição/edição do `conteudo` da mensagem (antes do fechamento da `div` externa do card da mensagem), adicione:

```html
                                    <div class="mt-2 pt-2 border-t border-gray-100">
                                        <button @click="toggleAbaVariacoes(seq.id, msg)"
                                                class="text-xs text-green-600 hover:text-green-700 font-medium flex items-center gap-1">
                                            <span x-text="abaVariacaoAberta[msg.id] ? '▾' : '▸'"></span>
                                            Variações
                                            <span x-show="(variacoesPor[msg.id] || []).length"
                                                  class="text-gray-400" x-text="'(' + (variacoesPor[msg.id] || []).length + ')'"></span>
                                        </button>

                                        <div x-show="abaVariacaoAberta[msg.id]" style="display:none" class="mt-2 space-y-2">
                                            <template x-for="variacao in (variacoesPor[msg.id] || [])" :key="variacao.id">
                                                <div class="flex items-start gap-2 bg-gray-50 border border-gray-200 rounded-lg p-2">
                                                    <span class="text-xs px-1.5 py-0.5 rounded-full flex-shrink-0"
                                                          :class="variacao.protegida ? 'bg-green-100 text-green-700' : 'bg-purple-50 text-purple-600'"
                                                          x-text="variacao.protegida ? 'original' : (variacao.origem === 'ia' ? 'IA' : 'manual')"></span>
                                                    <p class="text-xs text-gray-700 flex-1 whitespace-pre-wrap break-words" x-text="variacao.conteudo"></p>
                                                    <label class="flex items-center gap-1 flex-shrink-0" title="Ativa no sorteio de envio">
                                                        <input type="checkbox" :checked="variacao.ativa"
                                                               :disabled="variacao.protegida"
                                                               @change="toggleAtivaVariacao(seq.id, msg, variacao)"
                                                               class="w-3 h-3 accent-green-600">
                                                    </label>
                                                    <button x-show="!variacao.protegida"
                                                            @click="excluirVariacao(seq.id, msg, variacao)"
                                                            class="text-red-300 hover:text-red-500 flex-shrink-0 text-xs">✕</button>
                                                </div>
                                            </template>

                                            <div class="flex items-center gap-2">
                                                <input type="text" x-model="novaVariacaoTexto[msg.id]"
                                                       @keydown.enter="adicionarVariacaoManual(seq.id, msg)"
                                                       placeholder="Adicionar variação manual..."
                                                       class="flex-1 text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                                                <button @click="adicionarVariacaoManual(seq.id, msg)"
                                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1.5 rounded-lg">+</button>
                                            </div>

                                            <button @click="gerarVariacoes(seq.id, msg)"
                                                    :disabled="gerandoVariacoes[msg.id]"
                                                    class="text-xs text-purple-600 hover:text-purple-700 disabled:opacity-40 font-medium">
                                                <span x-show="!gerandoVariacoes[msg.id]">✨ Gerar variações com IA</span>
                                                <span x-show="gerandoVariacoes[msg.id]">Gerando...</span>
                                            </button>
                                        </div>
                                    </div>
```

- [ ] **Step 4: Manual browser verification (mandatory before considering this task done)**

Não há como automatizar este passo neste ambiente (sem MySQL local, sem sessão autenticada em navegador — mesma limitação documentada nas Tasks 20/21 do plano de Kanban Colunas Dinâmicas). Ao rodar este plano com acesso a um ambiente com banco de dados real:
1. Abrir `/kanban/config`, expandir uma sequência, expandir uma mensagem com "Variações".
2. Confirmar que a versão `original` aparece marcada, sem checkbox habilitado e sem botão de excluir.
3. Clicar em "Gerar variações com IA" e confirmar que 6 novas linhas aparecem.
4. Desativar uma variação IA e confirmar que ela não aparece mais elegível (mas continua na lista).
5. Adicionar uma variação manual e excluí-la.

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat(sequencias): UI de abas de variacao por mensagem na configuracao da sequencia"
```

---

### Task 11: Frontend — jitter e horário de funcionamento na UI da sequência

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `delay_jitter_segundos` (Task 7), `horario_ativo`/`horario_inicio`/`horario_fim`/`sequencia_repouso_id` (Task 9).

Mesma ressalva da Task 10: sem verificação em navegador real neste ambiente.

- [ ] **Step 1: Add jitter input next to the existing delay input**

No bloco de edição de mensagem lido nas linhas 234-244 do arquivo atual (onde já existe o campo "Aguarda" com `editMsgDelay`/`editMsgDelayUnidade`), adicione logo ao lado:

```html
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="text-xs text-gray-500">±</span>
                                                            <input type="number" x-model.number="editMsgJitter" min="0"
                                                                   class="w-14 text-xs border border-gray-300 rounded px-2 py-1"
                                                                   title="Variação aleatória em segundos, pra mais ou pra menos, em torno do tempo de espera">
                                                            <span class="text-xs text-gray-400">seg</span>
                                                        </div>
```

Adicione `editMsgJitter` ao estado Alpine (junto de `editMsgDelay`), inicialize em `iniciarEditarMsg()`:

```js
            this.editMsgJitter = msg.delay_jitter_segundos || 0;
```

E inclua no payload de `salvarMsg()`:

```js
            fd.append('delay_jitter_segundos', this.editMsgJitter || 0);
```

Repita o mesmo padrão (`novoJitter`, inicializado em `0`) no formulário de **nova** mensagem (`adicionarMsg()`), espelhando `novoDelay`/`novoDelayUnidade`.

- [ ] **Step 2: Add horário de funcionamento fields to the sequência edit form**

Localize o formulário de edição da sequência (função `salvarSeq`/`editarSeq`, e o modal ou painel onde `nome`/`descricao`/`coluna_kanban` da sequência são editados). Adicione:

```html
                <div class="space-y-2 border-t border-gray-100 pt-2 mt-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.horario_ativo" class="w-3.5 h-3.5 accent-green-600">
                        <span class="text-xs text-gray-600">Restringir a um horário de funcionamento</span>
                    </label>
                    <div x-show="form.horario_ativo" style="display:none" class="flex items-center gap-2 ml-5">
                        <input type="time" x-model="form.horario_inicio" class="text-xs border border-gray-300 rounded px-2 py-1">
                        <span class="text-xs text-gray-400">até</span>
                        <input type="time" x-model="form.horario_fim" class="text-xs border border-gray-300 rounded px-2 py-1">
                    </div>
                    <div x-show="form.horario_ativo" style="display:none" class="ml-5">
                        <label class="text-xs text-gray-500">Sequência de repouso (fora do horário)</label>
                        <select x-model="form.sequencia_repouso_id" class="w-full text-xs border border-gray-300 rounded px-2 py-1 bg-white">
                            <option :value="null">Nenhuma — só adia o envio pro próximo horário</option>
                            <template x-for="s in sequencias.filter(s2 => s2.id !== editando?.id)" :key="s.id">
                                <option :value="s.id" x-text="s.nome"></option>
                            </template>
                        </select>
                    </div>
                </div>
```

Localize o objeto `form` usado por `salvarSeq()` (o mesmo `this.form` referenciado em `await this.api(...'/sequencias'..., this.form)` nas linhas ~1127-1129 do arquivo atual) e adicione as chaves `horario_ativo: false, horario_inicio: null, horario_fim: null, sequencia_repouso_id: null` ao seu estado inicial e à função que popula `form` ao abrir "editar sequência" (`editarSeq()`), copiando de `seq.horario_ativo` etc.

- [ ] **Step 2: Manual browser verification (mandatory before considering this task done)**

Mesma ressalva da Task 10, Step 4 — sem ambiente de banco real disponível aqui. Ao rodar com acesso a um ambiente completo:
1. Ativar horário de funcionamento numa sequência, definir 08:00–18:00, salvar.
2. Confirmar que o campo de jitter aparece e aceita valores no formulário de mensagem.
3. Criar um ticket fora do horário configurado e confirmar (via log/fila) que o disparo é adiado ou usa a sequência de repouso, conforme configurado.

- [ ] **Step 3: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat(sequencias): UI de jitter de tempo e horario de funcionamento"
```

---

### Task 12: Regressão final

**Files:** nenhum (task de verificação)

- [ ] **Step 1: Run the full test suite**

Run: `wsl.exe bash -c "cd '/mnt/c/Users/PICHAU/Desktop/- LEAD CERTO/Antigravity/leadcerto/leadcerto-app' && php artisan test"`
Expected: todos os testes passam, exceto a falha pré-existente e conhecida em `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (não relacionada a este trabalho — confirmada como pré-existente desde o primeiro commit do repositório, ver histórico do plano de Kanban Colunas Dinâmicas).

- [ ] **Step 2: Confirm no dangling references to the old fixed-content flow**

Rode uma busca manual (`grep`/Grep tool) por `msg->conteudo` dentro de `app/Services/SequenciaService.php` para confirmar que restou só o fallback (`$variacao?->conteudo ?? $msg->conteudo`), não um uso direto sem sorteio.

- [ ] **Step 3: Commit (se algo precisou de ajuste nos passos acima)**

```bash
git add -A
git commit -m "test: regressao final da fase 1 de variacoes dinamicas"
```

(Só crie este commit se algo mudou; se a suíte já passou limpa no Step 1, não há o que commitar.)
