# Agendamento de Postagens — Facebook & Instagram (Meta) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a tenant schedule and publish posts (text + image, optional CTA button, optional comment-to-DM trigger) to their linked Facebook Page and/or Instagram Business account, with a weekly calendar view mirroring the existing GMB posting scheduler.

**Architecture:** New `meta_posts` table + `MetaPost` model (same shape/conventions as `GmbPost`). A `MetaPostPublishService` reuses the existing `MetaService::publicarPostFacebookPage()` / `publicarPostInstagram()` methods (already implemented, untouched by this plan) to do the actual Graph API calls, and on success auto-creates rows in the existing `meta_campanhas_gatilho` table so Comment-to-DM "just works" without a separate configuration step. A scheduled artisan command (`meta:publicar-posts`, same pattern as `gmb:publicar-posts`) publishes due posts every few minutes. UI mirrors `gmb-posts/index.blade.php`.

**Tech Stack:** Laravel 11, PHPUnit (`Tests\TestCase` + `RefreshDatabase`), Blade + Tailwind (utility classes only, no build step changes), MySQL.

**Spec:** `docs/superpowers/specs/2026-09-04-agendamento-postagens-meta-design.md`

## Global Constraints

- Every tenant-scoped model (`MetaPost`) MUST use `App\Scopes\TenantScope` via `static::addGlobalScope(new TenantScope())` in `booted()`, exactly like `MetaPagina`, `MetaContaInstagram`, `MetaCampanhaGatilho`, and `GmbPost` already do.
- CTA button fields (`cta_tipo`, `cta_url`) are only meaningful when `canal_alvo` includes `facebook` — Instagram organic posts do not support link buttons via the Graph API.
- A retry ("Publicar Agora" on a `falha` post with `canal_alvo = ambos`) must NOT re-publish to a channel that already has a `facebook_post_id` / `instagram_media_id` recorded — only the channel that actually failed gets retried.
- Comment-to-DM trigger rows (`meta_campanhas_gatilho`) are created automatically and **active** (`ativo = true`) immediately on successful publish — no separate manual activation step.
- No image library, no batch/AI generator, no templates in this version (explicitly deferred in the spec, section 7).

---

### Task 1: Migrations — `meta_posts` table + `meta_post_id` on `meta_campanhas_gatilho`

**Files:**
- Create: `database/migrations/2026_09_04_000004_create_meta_posts_table.php`
- Create: `database/migrations/2026_09_04_000005_add_meta_post_id_to_meta_campanhas_gatilho_table.php`
- Test: `tests/Feature/MetaPostMigrationTest.php`

**Interfaces:**
- Produces: `meta_posts` table (columns listed below) and `meta_campanhas_gatilho.meta_post_id` (nullable FK to `meta_posts.id`, `nullOnDelete()`), both consumed by Task 2/3 models.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MetaPostMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_posts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meta_posts'));
        $this->assertTrue(Schema::hasColumns('meta_posts', [
            'id', 'tenant_id', 'user_id', 'canal_alvo', 'meta_pagina_id',
            'meta_conta_instagram_id', 'texto', 'imagem_url', 'cta_tipo', 'cta_url',
            'modo_gatilho', 'palavras_chave', 'resposta_publica_comentario', 'mensagem_direct',
            'data_agendada', 'publicado_em', 'status', 'facebook_post_id',
            'instagram_media_id', 'log_erro', 'tentativas', 'created_at', 'updated_at',
        ]));
    }

    public function test_meta_campanhas_gatilho_has_meta_post_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('meta_campanhas_gatilho', 'meta_post_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaPostMigrationTest`
Expected: FAIL — `meta_posts` table does not exist.

- [ ] **Step 3: Write the `meta_posts` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('canal_alvo', ['facebook', 'instagram', 'ambos']);
            $table->foreignId('meta_pagina_id')->nullable()->constrained('meta_paginas')->nullOnDelete();
            $table->foreignId('meta_conta_instagram_id')->nullable()->constrained('meta_contas_instagram')->nullOnDelete();

            $table->text('texto');
            $table->string('imagem_url', 500)->nullable();

            $table->enum('cta_tipo', ['NENHUM', 'BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'])->default('NENHUM');
            $table->string('cta_url', 500)->nullable();

            $table->enum('modo_gatilho', ['nenhum', 'qualquer_comentario', 'palavra_chave'])->default('nenhum');
            $table->json('palavras_chave')->nullable();
            $table->string('resposta_publica_comentario', 500)->nullable();
            $table->text('mensagem_direct')->nullable();

            $table->dateTime('data_agendada');
            $table->dateTime('publicado_em')->nullable();
            $table->enum('status', ['agendado', 'publicando', 'publicado', 'falha', 'cancelado'])->default('agendado');

            $table->string('facebook_post_id')->nullable();
            $table->string('instagram_media_id')->nullable();
            $table->text('log_erro')->nullable();
            $table->unsignedInteger('tentativas')->default(0);

            $table->timestamps();

            $table->index(['status', 'data_agendada'], 'idx_meta_posts_agendamento');
            $table->index(['tenant_id'], 'idx_meta_posts_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_posts');
    }
};
```

Save as `database/migrations/2026_09_04_000004_create_meta_posts_table.php`.

- [ ] **Step 4: Write the `meta_post_id` column migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_campanhas_gatilho', function (Blueprint $table) {
            $table->foreignId('meta_post_id')->nullable()->after('id')
                ->constrained('meta_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meta_campanhas_gatilho', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_post_id');
        });
    }
};
```

Save as `database/migrations/2026_09_04_000005_add_meta_post_id_to_meta_campanhas_gatilho_table.php`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter MetaPostMigrationTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_04_000004_create_meta_posts_table.php database/migrations/2026_09_04_000005_add_meta_post_id_to_meta_campanhas_gatilho_table.php tests/Feature/MetaPostMigrationTest.php
git commit -m "feat: adiciona tabela meta_posts e meta_post_id em meta_campanhas_gatilho"
```

---

### Task 2: `MetaPost` model

**Files:**
- Create: `app/Models/MetaPost.php`
- Test: `tests/Feature/MetaPostModelTest.php`

**Interfaces:**
- Consumes: `meta_posts` table (Task 1).
- Produces: `MetaPost` model with fillable fields, casts, relations (`tenant()`, `pagina()`, `contaInstagram()`, `autor()`), scopes (`scopeProntosParaPublicar`, `scopeAgendados`, `scopePublicados`, `scopeFalhas`), and helpers (`podeCancelar()`, `statusBadge()`) — consumed by Tasks 3-9.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPostModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarPagina(Tenant $tenant): MetaPagina
    {
        $token = MetaToken::create([
            'tenant_id'    => $tenant->id,
            'access_token' => 'token-teste',
        ]);

        return MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1111111111',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'page-token-teste',
            'ativo'             => true,
        ]);
    }

    public function test_cria_post_agendado_apenas_para_o_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $pagina      = $this->criarPagina($tenant);

        session(['tenant_id' => $tenant->id]);

        MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Postagem de teste',
            'data_agendada'  => now()->addHour(),
            'status'         => 'agendado',
        ]);

        MetaPost::withoutGlobalScopes()->create([
            'tenant_id'      => $outroTenant->id,
            'canal_alvo'     => 'facebook',
            'texto'          => 'Postagem de outro tenant',
            'data_agendada'  => now()->addHour(),
            'status'         => 'agendado',
        ]);

        $this->assertSame(1, MetaPost::count());
    }

    public function test_scope_prontos_para_publicar_so_pega_agendados_no_passado(): void
    {
        $tenant = Tenant::factory()->create();
        $pagina = $this->criarPagina($tenant);
        session(['tenant_id' => $tenant->id]);

        $vencido = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Vencido', 'data_agendada' => now()->subMinute(), 'status' => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Futuro', 'data_agendada' => now()->addDay(), 'status' => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Ja publicado', 'data_agendada' => now()->subDay(), 'status' => 'publicado',
        ]);

        $prontos = MetaPost::prontosParaPublicar()->get();

        $this->assertCount(1, $prontos);
        $this->assertSame($vencido->id, $prontos->first()->id);
    }

    public function test_pode_cancelar_apenas_quando_agendado(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $agendado = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now()->addHour(), 'status' => 'agendado',
        ]);
        $publicado = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now()->subHour(), 'status' => 'publicado',
        ]);

        $this->assertTrue($agendado->podeCancelar());
        $this->assertFalse($publicado->podeCancelar());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaPostModelTest`
Expected: FAIL — `Class "App\Models\MetaPost" not found`

- [ ] **Step 3: Write the model**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaPost extends Model
{
    protected $table = 'meta_posts';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'canal_alvo',
        'meta_pagina_id',
        'meta_conta_instagram_id',
        'texto',
        'imagem_url',
        'cta_tipo',
        'cta_url',
        'modo_gatilho',
        'palavras_chave',
        'resposta_publica_comentario',
        'mensagem_direct',
        'data_agendada',
        'publicado_em',
        'status',
        'facebook_post_id',
        'instagram_media_id',
        'log_erro',
        'tentativas',
    ];

    protected function casts(): array
    {
        return [
            'palavras_chave' => 'array',
            'data_agendada'  => 'datetime',
            'publicado_em'   => 'datetime',
            'tentativas'     => 'integer',
        ];
    }

    // ── Relacionamentos ──

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pagina(): BelongsTo
    {
        return $this->belongsTo(MetaPagina::class, 'meta_pagina_id');
    }

    public function contaInstagram(): BelongsTo
    {
        return $this->belongsTo(MetaContaInstagram::class, 'meta_conta_instagram_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ──

    public function scopeProntosParaPublicar(Builder $query): Builder
    {
        return $query->where('status', 'agendado')
                     ->where('data_agendada', '<=', now());
    }

    public function scopeAgendados(Builder $query): Builder
    {
        return $query->where('status', 'agendado');
    }

    public function scopePublicados(Builder $query): Builder
    {
        return $query->where('status', 'publicado');
    }

    public function scopeFalhas(Builder $query): Builder
    {
        return $query->where('status', 'falha');
    }

    // ── Helpers ──

    public function podeCancelar(): bool
    {
        return $this->status === 'agendado';
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'publicado'  => ['label' => 'Publicado', 'class' => 'bg-green-100 text-green-800 border-green-200'],
            'agendado'   => ['label' => 'Agendado', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
            'publicando' => ['label' => 'Publicando...', 'class' => 'bg-blue-100 text-blue-800 border-blue-200'],
            'falha'      => ['label' => 'Falha no Envio', 'class' => 'bg-red-100 text-red-800 border-red-200'],
            'cancelado'  => ['label' => 'Cancelado', 'class' => 'bg-gray-100 text-gray-800 border-gray-200'],
            default      => ['label' => ucfirst($this->status), 'class' => 'bg-gray-100 text-gray-700 border-gray-200'],
        };
    }
}
```

Save as `app/Models/MetaPost.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MetaPostModelTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/MetaPost.php tests/Feature/MetaPostModelTest.php
git commit -m "feat: adiciona model MetaPost com TenantScope, scopes e helpers de status"
```

---

### Task 3: `MetaCampanhaGatilho` — adiciona `meta_post_id`

**Files:**
- Modify: `app/Models/MetaCampanhaGatilho.php`
- Test: `tests/Feature/MetaCampanhaGatilhoMetaPostTest.php`

**Interfaces:**
- Consumes: `meta_campanhas_gatilho.meta_post_id` column (Task 1), `MetaPost` model (Task 2).
- Produces: `MetaCampanhaGatilho::metaPost(): BelongsTo` and `meta_post_id` in `$fillable` — consumed by Task 4 (auto-creation of gatilhos).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPost;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaCampanhaGatilhoMetaPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_gatilho_pode_ser_vinculado_a_um_meta_post(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now(), 'status' => 'agendado',
        ]);

        $gatilho = MetaCampanhaGatilho::create([
            'tenant_id'        => $tenant->id,
            'nome'             => 'Auto — Post #' . $post->id,
            'canal_alvo'       => 'facebook',
            'modo_gatilho'     => 'palavra_chave',
            'palavras_chave'   => ['orçamento'],
            'mensagem_direct'  => 'Oi! Segue nosso site: frete.rio.br',
            'meta_post_id'     => $post->id,
            'ativo'            => true,
        ]);

        $this->assertTrue($gatilho->metaPost->is($post));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaCampanhaGatilhoMetaPostTest`
Expected: FAIL — `Call to undefined relationship [metaPost]` (or mass-assignment error on `meta_post_id`).

- [ ] **Step 3: Update the model**

In `app/Models/MetaCampanhaGatilho.php`, add `'meta_post_id'` to `$fillable` (after `'tenant_id'`) and add the relation method next to `paginaFacebook()`:

```php
    public function metaPost(): BelongsTo
    {
        return $this->belongsTo(MetaPost::class, 'meta_post_id');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MetaCampanhaGatilhoMetaPostTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/MetaCampanhaGatilho.php tests/Feature/MetaCampanhaGatilhoMetaPostTest.php
git commit -m "feat: relaciona MetaCampanhaGatilho ao MetaPost que o originou"
```

---

### Task 4: `MetaPostPublishService`

**Files:**
- Create: `app/Services/MetaPostPublishService.php`
- Test: `tests/Feature/MetaPostPublishServiceTest.php`

**Interfaces:**
- Consumes: `MetaPost` (Task 2), `MetaCampanhaGatilho` (Task 3), existing `MetaService::publicarPostFacebookPage(string $pageId, string $pageAccessToken, array $dados): ?string` and `MetaService::publicarPostInstagram(string $igUserId, string $accessToken, array $dados): ?string` (`app/Services/MetaService.php` — already exist, not modified by this plan).
- Produces: `MetaPostPublishService::publicar(MetaPost $post): bool` — consumed by Task 5 (scheduled command) and Task 7 (controller "Publicar Agora").

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Services\MetaPostPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaPostPublishServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPaginaEConta(Tenant $tenant): array
    {
        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);

        $pagina = MetaPagina::create([
            'tenant_id' => $tenant->id, 'meta_token_id' => $token->id,
            'facebook_page_id' => '1111111111', 'nome' => 'Frete Rio',
            'page_access_token' => 'page-tok', 'ativo' => true,
        ]);

        $conta = MetaContaInstagram::create([
            'tenant_id' => $tenant->id, 'meta_pagina_id' => $pagina->id,
            'instagram_business_id' => '2222222222', 'username' => 'frete.rio.br', 'ativo' => true,
        ]);

        return [$pagina, $conta];
    }

    public function test_publica_em_ambos_canais_e_cria_dois_gatilhos(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos'        => Http::response(['id' => 'FB_POST_123'], 200),
            'graph.facebook.com/*/media_publish'  => Http::response(['id' => 'IG_MEDIA_789'], 200),
            'graph.facebook.com/*/media'          => Http::response(['id' => 'IG_CONTAINER_456'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'ambos',
            'meta_pagina_id' => $pagina->id, 'meta_conta_instagram_id' => $conta->id,
            'texto' => 'Frete rápido no Rio!', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now(), 'status' => 'agendado',
            'modo_gatilho' => 'palavra_chave', 'palavras_chave' => ['orçamento'],
            'mensagem_direct' => 'Oi! Aqui está nosso WhatsApp: 21999999999',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertTrue($sucesso);
        $post->refresh();
        $this->assertSame('publicado', $post->status);
        $this->assertSame('FB_POST_123', $post->facebook_post_id);
        $this->assertSame('IG_MEDIA_789', $post->instagram_media_id);
        $this->assertNotNull($post->publicado_em);

        $this->assertSame(2, MetaCampanhaGatilho::where('meta_post_id', $post->id)->count());
        $this->assertDatabaseHas('meta_campanhas_gatilho', [
            'meta_post_id' => $post->id, 'canal_alvo' => 'facebook', 'post_id_especifico' => 'FB_POST_123', 'ativo' => true,
        ]);
        $this->assertDatabaseHas('meta_campanhas_gatilho', [
            'meta_post_id' => $post->id, 'canal_alvo' => 'instagram', 'post_id_especifico' => 'IG_MEDIA_789', 'ativo' => true,
        ]);
    }

    public function test_falha_parcial_nao_cria_gatilho_e_preserva_canal_que_deu_certo(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos'        => Http::response(['id' => 'FB_POST_123'], 200),
            'graph.facebook.com/*/media'          => Http::response(['error' => ['message' => 'boom']], 400),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'ambos',
            'meta_pagina_id' => $pagina->id, 'meta_conta_instagram_id' => $conta->id,
            'texto' => 'x', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now(), 'status' => 'agendado',
            'modo_gatilho' => 'nenhum',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertFalse($sucesso);
        $post->refresh();
        $this->assertSame('falha', $post->status);
        $this->assertSame('FB_POST_123', $post->facebook_post_id, 'Facebook publicou e o id deve ficar gravado');
        $this->assertNull($post->instagram_media_id);
        $this->assertSame(0, MetaCampanhaGatilho::count());
    }

    public function test_retry_nao_republica_no_canal_que_ja_tinha_dado_certo(): void
    {
        Http::fake([
            'graph.facebook.com/*/media_publish' => Http::response(['id' => 'IG_MEDIA_789'], 200),
            'graph.facebook.com/*/media'         => Http::response(['id' => 'IG_CONTAINER_456'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        // Simula estado deixado por uma tentativa anterior: Facebook já publicou.
        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'ambos',
            'meta_pagina_id' => $pagina->id, 'meta_conta_instagram_id' => $conta->id,
            'texto' => 'x', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now(), 'status' => 'falha',
            'facebook_post_id' => 'FB_POST_JA_PUBLICADO',
            'modo_gatilho' => 'nenhum',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertTrue($sucesso);
        $post->refresh();
        $this->assertSame('publicado', $post->status);
        $this->assertSame('FB_POST_JA_PUBLICADO', $post->facebook_post_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/photos'));
    }

    public function test_modo_gatilho_nenhum_nao_cria_regra_comment_to_dm(): void
    {
        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_123'], 200)]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'x', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now(), 'status' => 'agendado', 'modo_gatilho' => 'nenhum',
        ]);

        app(MetaPostPublishService::class)->publicar($post);

        $this->assertSame(0, MetaCampanhaGatilho::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaPostPublishServiceTest`
Expected: FAIL — `Class "App\Services\MetaPostPublishService" not found`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPost;
use Illuminate\Support\Facades\Log;

class MetaPostPublishService
{
    public function __construct(private MetaService $meta) {}

    public function publicar(MetaPost $post): bool
    {
        $post->update(['tentativas' => $post->tentativas + 1]);

        $sucessoFacebook = $post->facebook_post_id ? true : null;
        $sucessoInstagram = $post->instagram_media_id ? true : null;

        try {
            if (in_array($post->canal_alvo, ['facebook', 'ambos'], true) && ! $post->facebook_post_id) {
                $pagina = $post->pagina;
                $id = $this->meta->publicarPostFacebookPage($pagina->facebook_page_id, $pagina->page_access_token, [
                    'legenda'    => $post->texto,
                    'imagem_url' => $post->imagem_url,
                    'link'       => $post->cta_url,
                ]);
                $sucessoFacebook = $id !== null;
                if ($sucessoFacebook) {
                    $post->facebook_post_id = $id;
                }
            }

            if (in_array($post->canal_alvo, ['instagram', 'ambos'], true) && ! $post->instagram_media_id) {
                $conta = $post->contaInstagram;
                $id = $this->meta->publicarPostInstagram($conta->instagram_business_id, $conta->pagina->page_access_token, [
                    'legenda'    => $post->texto,
                    'imagem_url' => $post->imagem_url,
                ]);
                $sucessoInstagram = $id !== null;
                if ($sucessoInstagram) {
                    $post->instagram_media_id = $id;
                }
            }
        } catch (\Exception $e) {
            Log::error('MetaPostPublishService exceção', ['post_id' => $post->id, 'erro' => $e->getMessage()]);
            $post->status = 'falha';
            $post->log_erro = 'Exceção: ' . $e->getMessage();
            $post->save();
            return false;
        }

        $falhouAlgumCanalSolicitado =
            ($post->canal_alvo !== 'instagram' && $sucessoFacebook === false) ||
            ($post->canal_alvo !== 'facebook' && $sucessoInstagram === false);

        if ($falhouAlgumCanalSolicitado) {
            $post->status = 'falha';
            $post->log_erro = $this->montarLogErro($sucessoFacebook, $sucessoInstagram);
            $post->save();
            return false;
        }

        $post->status = 'publicado';
        $post->publicado_em = now();
        $post->log_erro = null;
        $post->save();

        $this->criarGatilhosComentario($post);

        return true;
    }

    private function montarLogErro(?bool $sucessoFacebook, ?bool $sucessoInstagram): string
    {
        $partes = [];
        if ($sucessoFacebook === false) {
            $partes[] = 'Falha ao publicar no Facebook.';
        }
        if ($sucessoInstagram === false) {
            $partes[] = 'Falha ao publicar no Instagram.';
        }
        return implode(' ', $partes) ?: 'Falha desconhecida ao publicar.';
    }

    private function criarGatilhosComentario(MetaPost $post): void
    {
        if ($post->modo_gatilho === 'nenhum') {
            return;
        }

        $base = [
            'tenant_id'                   => $post->tenant_id,
            'nome'                        => "Auto — Post #{$post->id}",
            'modo_gatilho'                => $post->modo_gatilho,
            'palavras_chave'              => $post->palavras_chave,
            'resposta_publica_comentario' => $post->resposta_publica_comentario,
            'mensagem_direct'             => $post->mensagem_direct,
            'meta_post_id'                => $post->id,
            'ativo'                       => true,
        ];

        if ($post->facebook_post_id) {
            MetaCampanhaGatilho::create($base + [
                'canal_alvo'         => 'facebook',
                'facebook_pagina_id' => $post->meta_pagina_id,
                'post_id_especifico' => $post->facebook_post_id,
            ]);
        }

        if ($post->instagram_media_id) {
            MetaCampanhaGatilho::create($base + [
                'canal_alvo'         => 'instagram',
                'instagram_conta_id' => $post->meta_conta_instagram_id,
                'post_id_especifico' => $post->instagram_media_id,
            ]);
        }
    }
}
```

Save as `app/Services/MetaPostPublishService.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter MetaPostPublishServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/MetaPostPublishService.php tests/Feature/MetaPostPublishServiceTest.php
git commit -m "feat: adiciona MetaPostPublishService com retry seguro e auto-criacao de gatilhos Comment-to-DM"
```

---

### Task 5: Comando agendado `meta:publicar-posts`

**Files:**
- Create: `app/Console/Commands/PublicarMetaPostsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/PublicarMetaPostsCommandTest.php`

**Interfaces:**
- Consumes: `MetaPost::prontosParaPublicar()` (Task 2), `MetaPostPublishService::publicar()` (Task 4).
- Produces: artisan command `meta:publicar-posts`, scheduled — consumed by nothing else in this plan (terminal integration point, verified end-to-end by its own test).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicarMetaPostsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_publica_apenas_posts_agendados_vencidos(): void
    {
        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_1'], 200)]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        $pagina = MetaPagina::create([
            'tenant_id' => $tenant->id, 'meta_token_id' => $token->id,
            'facebook_page_id' => '1', 'nome' => 'Frete Rio', 'page_access_token' => 'p', 'ativo' => true,
        ]);

        $vencido = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'x', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now()->subMinute(), 'status' => 'agendado',
        ]);
        $futuro = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'x', 'data_agendada' => now()->addDay(), 'status' => 'agendado',
        ]);

        $this->artisan('meta:publicar-posts')->assertExitCode(0);

        $this->assertSame('publicado', $vencido->fresh()->status);
        $this->assertSame('agendado', $futuro->fresh()->status);
    }

    public function test_sem_posts_pendentes_nao_falha(): void
    {
        $this->artisan('meta:publicar-posts')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PublicarMetaPostsCommandTest`
Expected: FAIL — command `meta:publicar-posts` does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\MetaPost;
use App\Services\MetaPostPublishService;
use Illuminate\Console\Command;

class PublicarMetaPostsCommand extends Command
{
    protected $signature = 'meta:publicar-posts';
    protected $description = 'Verifica e publica no Facebook/Instagram os posts agendados cujo horário já chegou';

    public function handle(MetaPostPublishService $service): int
    {
        $posts = MetaPost::withoutGlobalScopes()
            ->where('status', 'agendado')
            ->where('data_agendada', '<=', now())
            ->get();

        $total = $posts->count();

        if ($total === 0) {
            $this->info('Nenhum post agendado para publicação neste momento.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$total} post(s) para publicar no Facebook/Instagram.");

        $sucessos = 0;
        $falhas = 0;

        foreach ($posts as $post) {
            $this->line("Publicando Post #{$post->id} (canal: {$post->canal_alvo})...");

            $ok = $service->publicar($post);

            if ($ok) {
                $sucessos++;
                $this->info(" -> Post #{$post->id} publicado com sucesso!");
            } else {
                $falhas++;
                $this->error(" -> Falha ao publicar Post #{$post->id}. Verifique logs.");
            }
        }

        $this->info("Resultado: {$sucessos} publicados, {$falhas} falhas.");
        return self::SUCCESS;
    }
}
```

Save as `app/Console/Commands/PublicarMetaPostsCommand.php`.

- [ ] **Step 4: Register the schedule**

In `routes/console.php`, the existing GMB block (around line 97) reads:

```php
// A cada 1 minuto - Publica posts agendados do Google Meu Negócio cujo horário já chegou
Schedule::command('gmb:publicar-posts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/gmb-publicar-posts.log'));
```

Add this block immediately after it, mirroring the same modifiers and log-per-command convention:

```php

// A cada 1 minuto - Publica posts agendados do Facebook/Instagram cujo horário já chegou
Schedule::command('meta:publicar-posts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/meta-publicar-posts.log'));
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter PublicarMetaPostsCommandTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PublicarMetaPostsCommand.php routes/console.php tests/Feature/PublicarMetaPostsCommandTest.php
git commit -m "feat: adiciona comando agendado meta:publicar-posts"
```

---

### Task 6: `MetaPostController` — calendário (index) e criação (store)

**Files:**
- Create: `app/Http/Controllers/MetaPostController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MetaPostControllerTest.php`

**Interfaces:**
- Consumes: `MetaPost` (Task 2), `MetaPagina`/`MetaContaInstagram` (existing), `MetaPostPublishService` (Task 4, injected but only used by Task 7's `publicarAgora`).
- Produces: routes `meta-posts.index` (`GET /meta-posts`), `meta-posts.store` (`POST /meta-posts`) — consumed by Task 8/9 views and Task 7 (same controller, added there).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MetaPostControllerTest extends TestCase
{
    use RefreshDatabase;

    private function dono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function criarPagina(Tenant $tenant): MetaPagina
    {
        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        return MetaPagina::create([
            'tenant_id' => $tenant->id, 'meta_token_id' => $token->id,
            'facebook_page_id' => '1', 'nome' => 'Frete Rio', 'page_access_token' => 'p', 'ativo' => true,
        ]);
    }

    public function test_calendario_mostra_apenas_posts_da_semana_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);
        $pagina      = $this->criarPagina($tenant);

        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Dentro da semana', 'data_agendada' => now(), 'status' => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Semana que vem', 'data_agendada' => now()->addWeeks(2), 'status' => 'agendado',
        ]);
        MetaPost::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'De outro tenant', 'data_agendada' => now(), 'status' => 'agendado',
        ]);

        $response = $this->actingAs($dono)->get(route('meta-posts.index'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_semana'] === 1);
    }

    public function test_cria_post_com_imagem_enviada_por_upload(): void
    {
        Storage::fake('public');

        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Frete rápido no Rio!',
            'imagem'         => UploadedFile::fake()->image('foto.jpg'),
            'cta_tipo'       => 'CALL',
            'cta_url'        => 'https://frete.rio.br',
            'modo_gatilho'   => 'nenhum',
            'data_agendada'  => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index'));
        $this->assertDatabaseHas('meta_posts', [
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'texto' => 'Frete rápido no Rio!',
            'cta_tipo' => 'CALL', 'status' => 'agendado',
        ]);
        $post = MetaPost::first();
        $this->assertNotNull($post->imagem_url);
        Storage::disk('public')->assertExists(str_replace(Storage::disk('public')->url(''), '', $post->imagem_url));
    }

    public function test_canal_instagram_nao_exige_pagina_do_facebook(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);
        $conta  = \App\Models\MetaContaInstagram::create([
            'tenant_id' => $tenant->id, 'meta_pagina_id' => $pagina->id,
            'instagram_business_id' => '2', 'username' => 'frete.rio.br', 'ativo' => true,
        ]);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'               => 'instagram',
            'meta_conta_instagram_id'  => $conta->id,
            'texto'                    => 'Só Instagram',
            'imagem_url'               => 'https://cdn.exemplo.com/foto.jpg',
            'modo_gatilho'             => 'nenhum',
            'data_agendada'            => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index'));
        $this->assertDatabaseHas('meta_posts', ['canal_alvo' => 'instagram', 'meta_conta_instagram_id' => $conta->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaPostControllerTest`
Expected: FAIL — route `meta-posts.index` not defined.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MetaPostController extends Controller
{
    private function getTenantId(Request $request): int
    {
        $user = $request->user();
        if ($user->podeTrocarTenant()) {
            return (int) ($request->query('tenant_id') ?? session('tenant_id') ?? $user->tenant_id);
        }
        return (int) $user->tenant_id;
    }

    public function index(Request $request): View
    {
        $tenantId = $this->getTenantId($request);

        $semana = $request->filled('semana') ? Carbon::parse($request->semana) : now();
        $inicioSemana = $semana->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $fimSemana    = $semana->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();

        $postsSemana = MetaPost::with(['pagina', 'contaInstagram', 'autor'])
            ->where('tenant_id', $tenantId)
            ->whereBetween('data_agendada', [$inicioSemana, $fimSemana])
            ->orderBy('data_agendada')
            ->get();

        $postsPorDia = $postsSemana->groupBy(fn ($post) => $post->data_agendada->translatedFormat('l, d/m/Y'));

        $paginas = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $contasInstagram = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->get();

        $stats = [
            'total_semana' => $postsSemana->count(),
            'agendados'    => $postsSemana->where('status', 'agendado')->count(),
            'publicados'   => $postsSemana->where('status', 'publicado')->count(),
            'falhas'       => $postsSemana->where('status', 'falha')->count(),
        ];

        return view('meta-posts.index', compact('postsPorDia', 'paginas', 'contasInstagram', 'stats', 'semana'));
    }

    public function create(Request $request): View
    {
        $tenantId = $this->getTenantId($request);
        $paginas = MetaPagina::where('tenant_id', $tenantId)->where('ativo', true)->get();
        $contasInstagram = MetaContaInstagram::where('tenant_id', $tenantId)->where('ativo', true)->get();

        return view('meta-posts.create', compact('paginas', 'contasInstagram'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->getTenantId($request);

        $validated = $request->validate([
            'canal_alvo'               => 'required|in:facebook,instagram,ambos',
            'meta_pagina_id'           => 'required_if:canal_alvo,facebook,ambos|nullable|exists:meta_paginas,id',
            'meta_conta_instagram_id'  => 'required_if:canal_alvo,instagram,ambos|nullable|exists:meta_contas_instagram,id',
            'texto'                    => 'required|string|max:2200',
            'imagem'                   => 'nullable|image|max:10240',
            'imagem_url'               => 'nullable|url',
            'cta_tipo'                 => 'nullable|in:NENHUM,BOOK,ORDER,SHOP,LEARN_MORE,SIGN_UP,CALL',
            'cta_url'                  => 'nullable|url',
            'modo_gatilho'             => 'required|in:nenhum,qualquer_comentario,palavra_chave',
            'palavras_chave_texto'     => 'nullable|string',
            'resposta_publica_comentario' => 'nullable|string|max:500',
            'mensagem_direct'          => 'nullable|string|max:1000',
            'data_agendada'            => 'required|date',
        ]);

        $imagemUrl = $validated['imagem_url'] ?? null;
        if ($request->hasFile('imagem')) {
            $caminho = $request->file('imagem')->store('meta-posts', 'public');
            $imagemUrl = Storage::disk('public')->url($caminho);
        }

        $palavrasArray = [];
        if (! empty($validated['palavras_chave_texto'])) {
            $palavrasArray = array_values(array_filter(array_map('trim', explode(',', $validated['palavras_chave_texto']))));
        }

        MetaPost::create([
            'tenant_id'                   => $tenantId,
            'user_id'                     => $request->user()->id,
            'canal_alvo'                  => $validated['canal_alvo'],
            'meta_pagina_id'               => $validated['meta_pagina_id'] ?? null,
            'meta_conta_instagram_id'      => $validated['meta_conta_instagram_id'] ?? null,
            'texto'                        => $validated['texto'],
            'imagem_url'                   => $imagemUrl,
            'cta_tipo'                     => $validated['canal_alvo'] !== 'instagram' ? ($validated['cta_tipo'] ?? 'NENHUM') : 'NENHUM',
            'cta_url'                      => $validated['canal_alvo'] !== 'instagram' ? ($validated['cta_url'] ?? null) : null,
            'modo_gatilho'                 => $validated['modo_gatilho'],
            'palavras_chave'               => $palavrasArray,
            'resposta_publica_comentario'  => $validated['resposta_publica_comentario'] ?? null,
            'mensagem_direct'              => $validated['mensagem_direct'] ?? null,
            'data_agendada'                => Carbon::parse($validated['data_agendada']),
            'status'                       => 'agendado',
        ]);

        return redirect()->route('meta-posts.index', ['semana' => Carbon::parse($validated['data_agendada'])->toDateString()])
            ->with('sucesso', 'Postagem agendada com sucesso!');
    }
}
```

Save as `app/Http/Controllers/MetaPostController.php`.

- [ ] **Step 4: Register routes**

In `routes/web.php`, right after the existing `meta.desconectar` route block (inside the same `Route::middleware(['auth', 'tenant'])` group, so `session('tenant_id')` from `EnsureTenant` is already available), add:

```php
    // Agendamento de Postagens Meta (Facebook & Instagram)
    Route::get('/meta-posts', [\App\Http\Controllers\MetaPostController::class, 'index'])
        ->name('meta-posts.index')
        ->middleware('role:admin,dono,growth_manager');
    Route::get('/meta-posts/criar', [\App\Http\Controllers\MetaPostController::class, 'create'])
        ->name('meta-posts.create')
        ->middleware('role:admin,dono,growth_manager');
    Route::post('/meta-posts', [\App\Http\Controllers\MetaPostController::class, 'store'])
        ->name('meta-posts.store')
        ->middleware('role:admin,dono,growth_manager');
```

- [ ] **Step 5: Create a minimal placeholder view (real view comes in Task 8)**

```blade
@extends('layouts.app')
@section('content')
<div>Postagens Meta — em construção</div>
@endsection
```

Save as `resources/views/meta-posts/index.blade.php` (temporary, overwritten by Task 8).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter MetaPostControllerTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MetaPostController.php routes/web.php resources/views/meta-posts/index.blade.php tests/Feature/MetaPostControllerTest.php
git commit -m "feat: adiciona MetaPostController (calendario + criacao) e rotas meta-posts"
```

---

### Task 7: `MetaPostController` — "Publicar Agora" e cancelamento

**Files:**
- Modify: `app/Http/Controllers/MetaPostController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MetaPostControllerPublicarCancelarTest.php`

**Interfaces:**
- Consumes: `MetaPostPublishService::publicar()` (Task 4), `MetaCampanhaGatilho` (Task 3).
- Produces: routes `meta-posts.publicar-agora`, `meta-posts.destroy` — consumed by Task 8/9 views (buttons).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaPostControllerPublicarCancelarTest extends TestCase
{
    use RefreshDatabase;

    private function dono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    public function test_publicar_agora_dispara_o_servico_de_publicacao(): void
    {
        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_1'], 200)]);

        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $token  = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        $pagina = MetaPagina::create([
            'tenant_id' => $tenant->id, 'meta_token_id' => $token->id,
            'facebook_page_id' => '1', 'nome' => 'Frete Rio', 'page_access_token' => 'p', 'ativo' => true,
        ]);
        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'x', 'imagem_url' => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada' => now()->addDay(), 'status' => 'agendado',
        ]);

        $response = $this->actingAs($dono)->post(route('meta-posts.publicar-agora', $post));

        $response->assertRedirect();
        $this->assertSame('publicado', $post->fresh()->status);
    }

    public function test_cancelar_marca_status_e_desativa_gatilhos_vinculados(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now()->addDay(), 'status' => 'agendado',
        ]);
        $gatilho = MetaCampanhaGatilho::create([
            'tenant_id' => $tenant->id, 'nome' => 'Auto', 'canal_alvo' => 'facebook',
            'modo_gatilho' => 'qualquer_comentario', 'mensagem_direct' => 'oi',
            'meta_post_id' => $post->id, 'ativo' => true,
        ]);

        $response = $this->actingAs($dono)->delete(route('meta-posts.destroy', $post));

        $response->assertRedirect();
        $this->assertSame('cancelado', $post->fresh()->status);
        $this->assertFalse($gatilho->fresh()->ativo);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter MetaPostControllerPublicarCancelarTest`
Expected: FAIL — route `meta-posts.publicar-agora` not defined.

- [ ] **Step 3: Add the two actions to the controller**

In `app/Http/Controllers/MetaPostController.php`, add `use App\Models\MetaCampanhaGatilho;` and `use App\Services\MetaPostPublishService;` to the imports, then add these two methods at the end of the class:

```php
    public function publicarAgora(MetaPost $post, MetaPostPublishService $publishService): RedirectResponse
    {
        $sucesso = $publishService->publicar($post);

        if ($sucesso) {
            return back()->with('sucesso', 'Post publicado com sucesso!');
        }

        $erro = $post->fresh()->log_erro ?: 'Erro desconhecido ao comunicar com a Meta.';
        return back()->with('erro', 'Falha ao publicar: ' . $erro);
    }

    public function destroy(MetaPost $post): RedirectResponse
    {
        $post->update(['status' => 'cancelado']);

        MetaCampanhaGatilho::where('meta_post_id', $post->id)->update(['ativo' => false]);

        return back()->with('sucesso', 'Postagem cancelada.');
    }
```

- [ ] **Step 4: Register the two routes**

In `routes/web.php`, right after the `meta-posts.store` route added in Task 6, add:

```php
    Route::post('/meta-posts/{post}/publicar-agora', [\App\Http\Controllers\MetaPostController::class, 'publicarAgora'])
        ->name('meta-posts.publicar-agora')
        ->middleware('role:admin,dono,growth_manager');
    Route::delete('/meta-posts/{post}', [\App\Http\Controllers\MetaPostController::class, 'destroy'])
        ->name('meta-posts.destroy')
        ->middleware('role:admin,dono,growth_manager');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter MetaPostControllerPublicarCancelarTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MetaPostController.php routes/web.php tests/Feature/MetaPostControllerPublicarCancelarTest.php
git commit -m "feat: adiciona publicar-agora e cancelamento (com desativacao de gatilhos) ao MetaPostController"
```

---

### Task 8: View do calendário (`meta-posts/index.blade.php`) + link no menu

**Files:**
- Modify: `resources/views/meta-posts/index.blade.php` (replaces Task 6's placeholder)
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: `$postsPorDia`, `$paginas`, `$contasInstagram`, `$stats`, `$semana` (from `MetaPostController::index`, Task 6), `MetaPost::statusBadge()` (Task 2), routes `meta-posts.create`, `meta-posts.publicar-agora`, `meta-posts.destroy` (Tasks 6-7).
- Produces: nothing consumed by later tasks — this is a leaf UI task, verified manually + by Task 6's existing `test_calendario_mostra_apenas_posts_da_semana_do_proprio_tenant` (already covers the controller side; this task is markup only, no new assertions).

- [ ] **Step 1: Write the view**

```blade
@extends('layouts.app')

@section('title', 'Postagens Meta — Lead Certo')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📅 Agendamentos de Postagens (Facebook & Instagram)</h1>
            <p class="text-sm text-gray-500 mt-1">
                Semana de {{ $semana->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m') }}
                a {{ $semana->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m/Y') }}
            </p>
        </div>
        <a href="{{ route('meta-posts.create') }}"
           class="px-3.5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            + Individual
        </a>
    </div>

    <div class="flex gap-2">
        <a href="?semana={{ $semana->copy()->subWeek()->toDateString() }}"
           class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300 transition">
            ← Semana Anterior
        </a>
        <a href="?semana={{ now()->toDateString() }}"
           class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-xs font-bold hover:bg-green-200 transition">
            Semana Atual
        </a>
        <a href="?semana={{ $semana->copy()->addWeek()->toDateString() }}"
           class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold hover:bg-gray-300 transition">
            Próxima Semana →
        </a>
    </div>

    @if(session('sucesso'))
        <div class="p-3 bg-green-100 text-green-800 rounded-xl text-sm flex items-center gap-2">
            <span>✅</span><span>{{ session('sucesso') }}</span>
        </div>
    @endif
    @if(session('erro'))
        <div class="p-3 bg-red-100 text-red-800 rounded-xl text-sm flex items-center gap-2">
            <span>⚠️</span><span>{{ session('erro') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 text-center">
            <p class="text-2xl font-bold text-gray-800 font-mono">{{ $stats['total_semana'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Total da Semana</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-amber-100 p-4 text-center">
            <p class="text-2xl font-bold text-amber-600 font-mono">{{ $stats['agendados'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Agendados</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-green-100 p-4 text-center">
            <p class="text-2xl font-bold text-green-600 font-mono">{{ $stats['publicados'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Publicados</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-red-100 p-4 text-center">
            <p class="text-2xl font-bold text-red-600 font-mono">{{ $stats['falhas'] }}</p>
            <p class="text-xs text-gray-500 mt-0.5">Falhas / Atenção</p>
        </div>
    </div>

    @forelse($postsPorDia as $dia => $postsDoDia)
        <div class="space-y-3">
            <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider">{{ $dia }}</h2>

            <div class="space-y-3">
                @foreach($postsDoDia as $post)
                    @php $badge = $post->statusBadge(); @endphp

                    <div class="bg-white rounded-xl shadow-sm p-4 border-l-4 {{ str_contains($badge['class'], 'red') ? 'border-red-500' : (str_contains($badge['class'], 'green') ? 'border-green-500' : 'border-amber-400') }} hover:shadow transition">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="flex gap-3 flex-1 min-w-[240px]">
                                @if($post->imagem_url)
                                    <img src="{{ $post->imagem_url }}" alt="Mídia" class="w-16 h-16 rounded-lg object-cover border border-gray-200 flex-shrink-0">
                                @else
                                    <div class="w-16 h-16 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 flex-shrink-0 text-[10px]">
                                        Sem foto
                                    </div>
                                @endif

                                <div class="space-y-1 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-gray-100 text-gray-700">
                                            {{ match($post->canal_alvo) { 'facebook' => '📘 Facebook', 'instagram' => '📷 Instagram', default => '📘📷 Ambos' } }}
                                        </span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border {{ $badge['class'] }}">
                                            {{ $badge['label'] }}
                                        </span>
                                    </div>

                                    <p class="text-xs text-gray-600 line-clamp-2 leading-relaxed whitespace-pre-line">{{ $post->texto }}</p>

                                    <div class="flex items-center gap-3 text-[11px] text-gray-400 pt-1">
                                        <span>⏰ Horário: <strong class="text-gray-700 font-mono">{{ $post->data_agendada->format('H:i') }}</strong></span>
                                        @if($post->cta_tipo !== 'NENHUM')
                                            <span>🔘 Botão: <strong class="text-gray-700">{{ $post->cta_tipo }}</strong></span>
                                        @endif
                                        @if($post->modo_gatilho !== 'nenhum')
                                            <span>💬 Gatilho: <strong class="text-gray-700">{{ $post->modo_gatilho === 'qualquer_comentario' ? 'Qualquer comentário' : 'Palavra-chave' }}</strong></span>
                                        @endif
                                    </div>

                                    @if($post->status === 'falha' && $post->log_erro)
                                        <p class="text-[11px] text-red-600 mt-1">{{ $post->log_erro }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex gap-2 flex-shrink-0">
                                @if($post->status === 'falha')
                                    <form method="POST" action="{{ route('meta-posts.publicar-agora', $post) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold hover:bg-blue-700 transition">
                                            Publicar Agora
                                        </button>
                                    </form>
                                @endif
                                @if($post->podeCancelar())
                                    <form method="POST" action="{{ route('meta-posts.destroy', $post) }}" onsubmit="return confirm('Cancelar esta postagem?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-xs font-semibold hover:bg-gray-200 transition">
                                            Cancelar
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-sm text-gray-400">
            Nenhuma postagem agendada para esta semana.
        </div>
    @endforelse
</div>
@endsection
```

Save as `resources/views/meta-posts/index.blade.php` (overwrite Task 6's placeholder).

- [ ] **Step 2: Add sidebar link**

In `resources/views/layouts/app.blade.php`, find the `{{-- Integrações --}}` block (`@if($verIntegra) ... @endif`, right after the "Minhas Avaliações" link) and add this new link immediately after that `@endif` closes:

```blade
            {{-- Postagens Meta (Facebook & Instagram) --}}
            @if($verIntegra)
            <a href="{{ route('meta-posts.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('meta-posts.*') ? 'bg-green-600 text-white' : 'text-gray-300 hover:bg-gray-700' }}">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                Postagens Meta
            </a>
            @endif
```

- [ ] **Step 3: Manually verify**

Run: `php artisan test --filter MetaPostControllerTest` (re-run Task 6's test to confirm the real view renders without error, replacing the placeholder)
Expected: PASS (3 tests, same as Task 6 — this step just proves the new markup doesn't break the existing assertions)

- [ ] **Step 4: Commit**

```bash
git add resources/views/meta-posts/index.blade.php resources/views/layouts/app.blade.php
git commit -m "feat: tela de calendario de postagens Meta e link no menu lateral"
```

---

### Task 9: Formulário de criação (`meta-posts/create.blade.php`)

**Files:**
- Create: `resources/views/meta-posts/create.blade.php`

**Interfaces:**
- Consumes: `$paginas`, `$contasInstagram` (from `MetaPostController::create`, Task 6), route `meta-posts.store` (Task 6).
- Produces: nothing consumed by later tasks — leaf UI task.

- [ ] **Step 1: Write the view**

```blade
@extends('layouts.app')

@section('title', 'Nova Postagem Meta — Lead Certo')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <div>
        <h1 class="text-xl font-bold text-gray-800">Nova Postagem — Facebook & Instagram</h1>
        <a href="{{ route('meta-posts.index') }}" class="text-xs text-gray-500 hover:text-gray-700">← Voltar ao calendário</a>
    </div>

    @if($errors->any())
        <div class="p-3 bg-red-100 text-red-800 rounded-xl text-sm">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('meta-posts.store') }}" enctype="multipart/form-data" x-data="{ canal: 'facebook' }" class="bg-white rounded-2xl shadow-sm p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Canal</label>
            <select name="canal_alvo" x-model="canal" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="facebook">Facebook</option>
                <option value="instagram">Instagram</option>
                <option value="ambos">Ambos</option>
            </select>
        </div>

        <div x-show="canal === 'facebook' || canal === 'ambos'">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Página do Facebook</label>
            <select name="meta_pagina_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Selecione...</option>
                @foreach($paginas as $pagina)
                    <option value="{{ $pagina->id }}">{{ $pagina->nome }}</option>
                @endforeach
            </select>
        </div>

        <div x-show="canal === 'instagram' || canal === 'ambos'">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Conta do Instagram</label>
            <select name="meta_conta_instagram_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Selecione...</option>
                @foreach($contasInstagram as $conta)
                    <option value="{{ $conta->id }}">@{{ $conta->username }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Texto</label>
            <textarea name="texto" rows="4" maxlength="2200" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Imagem (upload)</label>
            <input type="file" name="imagem" accept="image/*" class="w-full text-sm">
            <p class="text-[11px] text-gray-400 mt-1">Ou cole uma URL de imagem já hospedada:</p>
            <input type="url" name="imagem_url" placeholder="https://..." class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm mt-1">
        </div>

        <div x-show="canal === 'facebook' || canal === 'ambos'" class="border-t border-gray-100 pt-4 space-y-3">
            <p class="text-xs font-semibold text-gray-500 uppercase">Botão (só vale para o Facebook)</p>
            <div class="grid grid-cols-2 gap-3">
                <select name="cta_tipo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="NENHUM">Sem botão</option>
                    <option value="CALL">Ligar</option>
                    <option value="LEARN_MORE">Saiba Mais</option>
                    <option value="SHOP">Comprar</option>
                    <option value="ORDER">Pedir</option>
                    <option value="BOOK">Agendar</option>
                    <option value="SIGN_UP">Cadastrar</option>
                </select>
                <input type="url" name="cta_url" placeholder="URL do botão" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>

        <div class="border-t border-gray-100 pt-4 space-y-3" x-data="{ gatilho: 'nenhum' }">
            <p class="text-xs font-semibold text-gray-500 uppercase">Gatilho de Comentário (Comment-to-DM)</p>
            <select name="modo_gatilho" x-model="gatilho" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="nenhum">Sem automação</option>
                <option value="qualquer_comentario">Qualquer comentário</option>
                <option value="palavra_chave">Palavra-chave específica</option>
            </select>

            <div x-show="gatilho !== 'nenhum'" class="space-y-3">
                <div x-show="gatilho === 'palavra_chave'">
                    <label class="block text-xs text-gray-600 mb-1">Palavras-chave (separadas por vírgula)</label>
                    <input type="text" name="palavras_chave_texto" placeholder="orçamento, quero, preço" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Resposta pública no comentário (opcional)</label>
                    <input type="text" name="resposta_publica_comentario" maxlength="500" placeholder="Te chamei no direct! Confira lá 😉" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Mensagem enviada no Direct</label>
                    <textarea name="mensagem_direct" rows="3" maxlength="1000" placeholder="Oi! Segue nosso site: frete.rio.br ou fale no WhatsApp: 21999999999" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"></textarea>
                </div>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Data e hora agendada</label>
            <input type="datetime-local" name="data_agendada" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>

        <button type="submit" class="w-full py-2.5 bg-green-600 text-white rounded-xl text-sm font-semibold hover:bg-green-700 transition">
            Agendar Postagem
        </button>
    </form>
</div>
@endsection
```

Save as `resources/views/meta-posts/create.blade.php`.

Note: `x-data`/`x-show` requires Alpine.js — confirm it's already loaded globally in `layouts/app.blade.php` (it is, used by the GMB sidebar submenu at `resources/views/layouts/app.blade.php:330`). No new script tag needed.

- [ ] **Step 2: Manually verify**

Run: `php artisan test --filter MetaPostControllerTest` (re-run Task 6's `store` tests once more — this step only adds the `create` GET view, no new assertions needed since the form target was already tested)
Expected: PASS (3 tests)

- [ ] **Step 3: Commit**

```bash
git add resources/views/meta-posts/create.blade.php
git commit -m "feat: formulario de criacao de postagem Meta com CTA e gatilho condicionais"
```

---

## Post-Plan Verification

After all 9 tasks:

```bash
php artisan test --filter "MetaPost|PublicarMetaPosts|MetaCampanhaGatilhoMetaPost"
```

Expected: all green (18 tests across the 6 new test files).

Then a manual smoke test in the browser (Frete Rio tenant, already has 1 Facebook page + 1 Instagram account linked from the earlier fix):
1. Sidebar → "Postagens Meta" → calendar loads, empty state shows.
2. "+ Individual" → fill form (canal "Ambos", texto, upload uma imagem, CTA "Ligar", gatilho "palavra_chave" com "orçamento") → agendar para daqui a 2 minutos.
3. Wait for the scheduler (or run `php artisan meta:publicar-posts` by hand) → refresh calendar → post shows "Publicado".
4. Integrações → Configurar Comment-to-DM → confirm 2 new rules appeared (Facebook + Instagram), both active, both named "Auto — Post #N".
