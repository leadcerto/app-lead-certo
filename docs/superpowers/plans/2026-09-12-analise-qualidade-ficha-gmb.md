# Análise de Qualidade da Ficha (GMB) — Fundação + Categoria "Atividade" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a cada Perfil GMB uma nota de 0-100 (geral + por categoria) visível na listagem e numa tela de
diagnóstico estilo PageSpeed Insights, com a primeira categoria real ("Atividade") totalmente calculada —
as outras 6 categorias aparecem como "pendente" (estado real e testado, não quebrado) até seus próprios
planos.

**Architecture:** Um `GmbQualidadeService::avaliar(PerfilGmb $perfil): GmbQualidadeScore` orquestra o
cálculo de cada categoria e persiste o resultado (nota + diagnósticos em JSON) numa tabela nova
`gmb_qualidade_scores` (1 linha por perfil, recalculada sob demanda — nunca em toda carga de página). A
categoria "Atividade" lê só a tabela local `gmb_posts` (nenhuma chamada externa nesta primeira fatia). Uma
tela nova (`gmb-qualidade.show`) renderiza nota geral + as 7 categorias; a listagem de Perfis GMB ganha uma
coluna "Qualidade" linkando pra essa tela.

**Tech Stack:** Laravel 11, PHPUnit (`Tests\TestCase` + `RefreshDatabase`), Blade + Tailwind (sem build
step novo), MySQL.

**Spec:** `docs/superpowers/specs/2026-09-12-analise-qualidade-ficha-gmb-design.md`

## Global Constraints

- `GmbQualidadeScore` (novo model) MUST usar `App\Scopes\TenantScope` via `static::addGlobalScope(new TenantScope)` em `booted()`, igual `PerfilGmb`/`GmbPost` já fazem.
- Nota geral (`nota_geral`) é a **média só das categorias com status `calculado`** — categorias `pendente` nunca entram na média, pra não afundar artificialmente a nota enquanto o resto ainda não foi construído.
- As 6 categorias ainda não implementadas devem aparecer na tela com um estado "Em breve" real e testado (nota `null`, status `pendente`) — nunca um erro, nunca uma seção faltando.
- Rotas novas entram no MESMO grupo de middleware já existente em `routes/gmb-web.php`: `Route::middleware(['auth', 'tenant', 'role:admin,dono,diretor,diretor_marketing'])->prefix('admin/gmb')->name('admin.')`.
- Binding de rota: nomear o parâmetro da rota como `{perfil}` e o parâmetro do controller como `PerfilGmb $perfil` (não `$perfisGmb`) — como são rotas novas (não `Route::resource`), não há a pegadinha de nome de parâmetro já documentada em `PerfilGmbController::edit()`.

---

### Task 1: Migration + Model `GmbQualidadeScore`

**Files:**
- Create: `database/migrations/2026_09_12_000001_create_gmb_qualidade_scores_table.php`
- Create: `app/Models/GmbQualidadeScore.php`
- Test: `tests/Feature/GmbQualidadeScoreModelTest.php`

**Interfaces:**
- Produces: tabela `gmb_qualidade_scores` e model `GmbQualidadeScore` com `$fillable = ['tenant_id', 'perfil_gmb_id', 'nota_geral', 'categorias', 'avaliado_em']`, cast `categorias` como `array` e `avaliado_em` como `datetime`, relação `perfil(): BelongsTo` — consumido pela Task 2 (`GmbQualidadeService`) e Task 3 (controller).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GmbQualidadeScoreModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    public function test_tabela_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('gmb_qualidade_scores'));
        $this->assertTrue(Schema::hasColumns('gmb_qualidade_scores', [
            'id', 'tenant_id', 'perfil_gmb_id', 'nota_geral', 'categorias', 'avaliado_em',
            'created_at', 'updated_at',
        ]));
    }

    public function test_cria_score_vinculado_ao_perfil_e_so_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $perfil      = $this->criarPerfil($tenant);

        session(['tenant_id' => $tenant->id]);

        $score = GmbQualidadeScore::create([
            'tenant_id'      => $tenant->id,
            'perfil_gmb_id'  => $perfil->id,
            'nota_geral'     => 100,
            'categorias'     => ['atividade' => ['nota' => 100, 'status' => 'calculado']],
            'avaliado_em'    => now(),
        ]);

        GmbQualidadeScore::withoutGlobalScopes()->create([
            'tenant_id'     => $outroTenant->id,
            'perfil_gmb_id' => $this->criarPerfil($outroTenant)->id,
            'nota_geral'    => 50,
            'categorias'    => [],
            'avaliado_em'   => now(),
        ]);

        $this->assertSame(1, GmbQualidadeScore::count());
        $this->assertTrue($score->perfil->is($perfil));
        $this->assertIsArray($score->fresh()->categorias);
        $this->assertSame(100, $score->fresh()->categorias['atividade']['nota']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GmbQualidadeScoreModelTest`
Expected: FAIL — `Class "App\Models\GmbQualidadeScore" not found` (e a tabela não existe).

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
        Schema::create('gmb_qualidade_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('perfil_gmb_id')->constrained('perfis_gmb')->cascadeOnDelete()->unique();

            $table->unsignedTinyInteger('nota_geral')->nullable();
            $table->json('categorias');
            $table->dateTime('avaliado_em')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_qualidade_scores');
    }
};
```

Save as `database/migrations/2026_09_12_000001_create_gmb_qualidade_scores_table.php`.

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmbQualidadeScore extends Model
{
    protected $table = 'gmb_qualidade_scores';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'tenant_id',
        'perfil_gmb_id',
        'nota_geral',
        'categorias',
        'avaliado_em',
    ];

    protected function casts(): array
    {
        return [
            'categorias'  => 'array',
            'avaliado_em' => 'datetime',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilGmb::class, 'perfil_gmb_id');
    }
}
```

Save as `app/Models/GmbQualidadeScore.php`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter GmbQualidadeScoreModelTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_12_000001_create_gmb_qualidade_scores_table.php app/Models/GmbQualidadeScore.php tests/Feature/GmbQualidadeScoreModelTest.php
git commit -m "feat: adiciona tabela e model GmbQualidadeScore"
```

---

### Task 2: `GmbQualidadeService` — orquestrador + categoria "Atividade"

**Files:**
- Create: `app/Services/GmbQualidadeService.php`
- Test: `tests/Feature/GmbQualidadeServiceTest.php`

**Interfaces:**
- Consumes: `GmbQualidadeScore` (Task 1), `GmbPost` (já existe — `perfil_gmb_id`, `status`, `publicado_em`).
- Produces: `GmbQualidadeService::avaliar(PerfilGmb $perfil): GmbQualidadeScore` — consumido pela Task 3 (controller). Formato de cada categoria dentro de `categorias`: `['nota' => int|null, 'status' => 'calculado'|'pendente', 'label' => string, 'diagnosticos' => array]`. Cada diagnóstico: `['tipo' => 'ok'|'aviso'|'erro'|'pendente', 'mensagem' => string, 'acao_label' => ?string, 'acao_url' => ?string]`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbQualidadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmbQualidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    private function criarPost(PerfilGmb $perfil, string $status, ?\Carbon\Carbon $publicadoEm): GmbPost
    {
        return GmbPost::create([
            'tenant_id'     => $perfil->tenant_id,
            'perfil_gmb_id' => $perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'x',
            'data_agendada' => now(),
            'status'        => $status,
            'publicado_em'  => $publicadoEm,
        ]);
    }

    public function test_sem_nenhum_post_publicado_atividade_fica_zerada(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['atividade']['nota']);
        $this->assertSame('calculado', $score->categorias['atividade']['status']);
        $this->assertSame('erro', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
        $this->assertSame(0, $score->nota_geral);
    }

    public function test_post_publicado_ha_menos_de_7_dias_da_nota_maxima(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(2));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(100, $score->categorias['atividade']['nota']);
        $this->assertSame('ok', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
    }

    public function test_post_publicado_entre_8_e_14_dias_gera_aviso(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(10));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(60, $score->categorias['atividade']['nota']);
        $this->assertSame('aviso', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
        $this->assertNotNull($score->categorias['atividade']['diagnosticos'][0]['acao_url']);
    }

    public function test_post_publicado_ha_mais_de_14_dias_gera_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDays(30));

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(20, $score->categorias['atividade']['nota']);
        $this->assertSame('erro', $score->categorias['atividade']['diagnosticos'][0]['tipo']);
    }

    public function test_post_agendado_nao_publicado_e_ignorado_no_calculo(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'agendado', null);

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame(0, $score->categorias['atividade']['nota']);
    }

    public function test_categorias_ainda_nao_implementadas_ficam_pendentes_e_fora_da_media(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);
        $this->criarPost($perfil, 'publicado', now()->subDay());

        $score = app(GmbQualidadeService::class)->avaliar($perfil);

        $this->assertSame('pendente', $score->categorias['identidade']['status']);
        $this->assertNull($score->categorias['identidade']['nota']);
        $this->assertCount(7, $score->categorias);
        // nota_geral = so a media de 'atividade' (100), nao afetada pelas 6 pendentes
        $this->assertSame(100, $score->nota_geral);
    }

    public function test_avaliar_de_novo_atualiza_o_mesmo_registro_em_vez_de_duplicar(): void
    {
        $tenant = Tenant::factory()->create();
        $perfil = $this->criarPerfil($tenant);
        session(['tenant_id' => $tenant->id]);

        $service = app(GmbQualidadeService::class);
        $service->avaliar($perfil);
        $service->avaliar($perfil);

        $this->assertSame(1, \App\Models\GmbQualidadeScore::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GmbQualidadeServiceTest`
Expected: FAIL — `Class "App\Services\GmbQualidadeService" not found`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;

class GmbQualidadeService
{
    /**
     * Ordem em que as categorias aparecem na tela — mantida fixa aqui pra
     * garantir que a UI (Task 3) sempre encontre as 7 chaves, mesmo antes
     * de cada categoria estar implementada de verdade.
     */
    private const CATEGORIAS_LABELS = [
        'atividade'        => 'Atividade',
        'identidade'       => 'Identidade',
        'localizacao'      => 'Localização',
        'conteudo'         => 'Conteúdo',
        'reputacao'        => 'Reputação',
        'presenca_externa' => 'Presença Externa',
        'saude_risco'      => 'Saúde/Risco',
    ];

    public function avaliar(PerfilGmb $perfil): GmbQualidadeScore
    {
        $categorias = [];

        foreach (self::CATEGORIAS_LABELS as $chave => $label) {
            $categorias[$chave] = $chave === 'atividade'
                ? $this->avaliarAtividade($perfil)
                : $this->categoriaPendente($label);
        }

        $notasCalculadas = collect($categorias)
            ->where('status', 'calculado')
            ->pluck('nota');

        $notaGeral = $notasCalculadas->isNotEmpty()
            ? (int) round($notasCalculadas->avg())
            : null;

        return GmbQualidadeScore::updateOrCreate(
            ['perfil_gmb_id' => $perfil->id],
            [
                'tenant_id'   => $perfil->tenant_id,
                'nota_geral'  => $notaGeral,
                'categorias'  => $categorias,
                'avaliado_em' => now(),
            ]
        );
    }

    private function avaliarAtividade(PerfilGmb $perfil): array
    {
        $ultimoPost = GmbPost::withoutGlobalScopes()
            ->where('perfil_gmb_id', $perfil->id)
            ->where('status', 'publicado')
            ->orderByDesc('publicado_em')
            ->first();

        $acaoCriarPost = [
            'acao_label' => 'Criar publicação agora',
            'acao_url'   => route('admin.gmb-posts.create'),
        ];

        if (! $ultimoPost || ! $ultimoPost->publicado_em) {
            return [
                'nota'   => 0,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'erro',
                    'mensagem' => 'Nenhuma postagem publicada ainda neste perfil. O Google reduz a relevância de fichas sem atividade recente.',
                ], $acaoCriarPost)],
            ];
        }

        $diasSemPost = (int) $ultimoPost->publicado_em->diffInDays(now());

        if ($diasSemPost <= 7) {
            return [
                'nota'   => 100,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [[
                    'tipo'     => 'ok',
                    'mensagem' => "Último post publicado há {$diasSemPost} dia(s) — dentro do ritmo recomendado (ao menos 1x por semana).",
                ]],
            ];
        }

        if ($diasSemPost <= 14) {
            return [
                'nota'   => 60,
                'status' => 'calculado',
                'label'  => self::CATEGORIAS_LABELS['atividade'],
                'diagnosticos' => [array_merge([
                    'tipo'     => 'aviso',
                    'mensagem' => "Último post foi há {$diasSemPost} dias. O ideal é postar ao menos 1x por semana.",
                ], $acaoCriarPost)],
            ];
        }

        return [
            'nota'   => 20,
            'status' => 'calculado',
            'label'  => self::CATEGORIAS_LABELS['atividade'],
            'diagnosticos' => [array_merge([
                'tipo'     => 'erro',
                'mensagem' => "Último post foi há {$diasSemPost} dias. Perfis parados perdem relevância no Google.",
            ], $acaoCriarPost)],
        ];
    }

    private function categoriaPendente(string $label): array
    {
        return [
            'nota'   => null,
            'status' => 'pendente',
            'label'  => $label,
            'diagnosticos' => [[
                'tipo'     => 'pendente',
                'mensagem' => 'Essa categoria ainda não está disponível — chega em uma próxima atualização.',
            ]],
        ];
    }
}
```

Save as `app/Services/GmbQualidadeService.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GmbQualidadeServiceTest`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GmbQualidadeService.php tests/Feature/GmbQualidadeServiceTest.php
git commit -m "feat: adiciona GmbQualidadeService com categoria Atividade calculada"
```

---

### Task 3: Controller, rotas e tela de diagnóstico

**Files:**
- Create: `app/Http/Controllers/GmbQualidadeController.php`
- Modify: `routes/gmb-web.php`
- Create: `resources/views/gmb-qualidade/show.blade.php`
- Test: `tests/Feature/GmbQualidadeControllerTest.php`

**Interfaces:**
- Consumes: `GmbQualidadeService::avaliar()` (Task 2), `GmbQualidadeScore` (Task 1), `PerfilGmb` (existente).
- Produces: rotas `admin.gmb-qualidade.show` (`GET perfis-gmb/{perfil}/qualidade`) e `admin.gmb-qualidade.reavaliar` (`POST perfis-gmb/{perfil}/qualidade/reavaliar`) — consumidas pela Task 4 (link na listagem).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmbQualidadeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    public function test_acessar_diagnostico_pela_primeira_vez_calcula_e_exibe_a_nota(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");

        $response->assertOk();
        $response->assertViewIs('gmb-qualidade.show');
        $response->assertViewHas('score', fn ($score) => $score->nota_geral === 0);
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id]);
    }

    public function test_nao_acessa_diagnostico_de_perfil_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);
        $perfilAlheio = \App\Models\PerfilGmb::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'nome' => 'x', 'city' => 'x', 'state' => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=1', 'ativo' => true,
        ]);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfilAlheio->id}/qualidade");

        $response->assertForbidden();
    }

    public function test_reavaliar_recalcula_a_nota_apos_novo_post(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id, 'nota_geral' => 0]);

        GmbPost::create([
            'tenant_id' => $tenant->id, 'perfil_gmb_id' => $perfil->id, 'tipo' => 'novidade',
            'texto' => 'x', 'data_agendada' => now(), 'status' => 'publicado', 'publicado_em' => now(),
        ]);

        $response = $this->actingAs($dono)->post("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade/reavaliar");

        $response->assertRedirect("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id, 'nota_geral' => 100]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GmbQualidadeControllerTest`
Expected: FAIL — rota `admin.gmb-qualidade.show` não existe (404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;
use App\Services\GmbQualidadeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GmbQualidadeController extends Controller
{
    public function show(Request $request, PerfilGmb $perfil, GmbQualidadeService $service): View
    {
        abort_if($perfil->tenant_id !== $request->user()->tenantAtual(), 403);

        $score = GmbQualidadeScore::where('perfil_gmb_id', $perfil->id)->first()
            ?? $service->avaliar($perfil);

        return view('gmb-qualidade.show', ['perfil' => $perfil, 'score' => $score]);
    }

    public function reavaliar(Request $request, PerfilGmb $perfil, GmbQualidadeService $service): RedirectResponse
    {
        abort_if($perfil->tenant_id !== $request->user()->tenantAtual(), 403);

        $service->avaliar($perfil);

        return redirect()->route('admin.gmb-qualidade.show', $perfil)
            ->with('sucesso', 'Diagnóstico atualizado.');
    }
}
```

Save as `app/Http/Controllers/GmbQualidadeController.php`.

- [ ] **Step 4: Register routes**

In `routes/gmb-web.php`, logo depois do bloco da Apostila (`Route::view('apostila', ...)`), adicionar:

```php
    // ── Análise de Qualidade da Ficha ──────────────────────────────────────
    Route::get('perfis-gmb/{perfil}/qualidade', [\App\Http\Controllers\GmbQualidadeController::class, 'show'])
        ->name('gmb-qualidade.show');
    Route::post('perfis-gmb/{perfil}/qualidade/reavaliar', [\App\Http\Controllers\GmbQualidadeController::class, 'reavaliar'])
        ->name('gmb-qualidade.reavaliar');
```

- [ ] **Step 5: Write the view**

```blade
@extends('layouts.app')

@section('title', 'Qualidade da Ficha — ' . $perfil->nome)

@section('content')
<div class="max-w-4xl mx-auto space-y-6 pb-16">

    <div class="flex items-center justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
                <a href="{{ route('admin.perfis-gmb.index') }}" class="hover:underline">Perfis GMB</a>
                <span>/</span>
                <span class="text-gray-700">Qualidade</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-heading">📊 {{ $perfil->nome }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                Avaliado em {{ $score->avaliado_em?->format('d/m/Y H:i') ?? 'nunca' }}
            </p>
        </div>
        <form action="{{ route('admin.gmb-qualidade.reavaliar', $perfil) }}" method="POST">
            @csrf
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-semibold transition">
                🔄 Reavaliar agora
            </button>
        </form>
    </div>

    @if(session('sucesso'))
        <div class="p-3 bg-green-100 text-green-800 rounded-lg text-sm">✅ {{ session('sucesso') }}</div>
    @endif

    {{-- Nota geral --}}
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 flex items-center gap-6">
        @php
            $nota = $score->nota_geral;
            $corNota = is_null($nota) ? 'text-gray-400 border-gray-300' : ($nota >= 90 ? 'text-green-600 border-green-500' : ($nota >= 50 ? 'text-amber-500 border-amber-400' : 'text-red-600 border-red-500'));
        @endphp
        <div class="w-24 h-24 rounded-full border-4 {{ $corNota }} flex items-center justify-center flex-shrink-0">
            <span class="text-3xl font-bold {{ $corNota }}">{{ $nota ?? '—' }}</span>
        </div>
        <div>
            <div class="text-sm font-bold text-gray-800">Nota geral</div>
            <p class="text-xs text-gray-500 mt-1">Média das categorias já calculadas. Categorias ainda pendentes não entram nessa conta.</p>
        </div>
    </div>

    {{-- Categorias --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($score->categorias as $chave => $categoria)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-gray-900">{{ $categoria['label'] }}</h3>
                @if($categoria['status'] === 'pendente')
                    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">Em breve</span>
                @else
                    @php
                        $corCategoria = $categoria['nota'] >= 90 ? 'bg-green-100 text-green-700' : ($categoria['nota'] >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700');
                    @endphp
                    <span class="px-2 py-1 {{ $corCategoria }} rounded-full text-xs font-bold">{{ $categoria['nota'] }}</span>
                @endif
            </div>

            @foreach($categoria['diagnosticos'] as $diag)
                @php
                    $corDiag = match($diag['tipo']) {
                        'ok' => 'border-green-400 text-gray-700',
                        'aviso' => 'border-amber-400 text-gray-700',
                        'erro' => 'border-red-400 text-gray-700',
                        default => 'border-gray-300 text-gray-500',
                    };
                @endphp
                <div class="pl-3 border-l-2 {{ $corDiag }} text-sm mb-2">
                    <p>{{ $diag['mensagem'] }}</p>
                    @if(!empty($diag['acao_url']))
                        <a href="{{ $diag['acao_url'] }}" class="inline-block mt-1 text-xs font-semibold text-green-700 hover:underline">{{ $diag['acao_label'] }} →</a>
                    @endif
                </div>
            @endforeach
        </div>
        @endforeach
    </div>
</div>
@endsection
```

Save as `resources/views/gmb-qualidade/show.blade.php`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter GmbQualidadeControllerTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/GmbQualidadeController.php routes/gmb-web.php resources/views/gmb-qualidade/show.blade.php tests/Feature/GmbQualidadeControllerTest.php
git commit -m "feat: adiciona tela de diagnostico de qualidade da ficha GMB"
```

---

### Task 4: Coluna "Qualidade" na listagem de Perfis GMB

**Files:**
- Modify: `app/Http/Controllers/PerfilGmbController.php:13-21` (método `index`)
- Modify: `resources/views/admin/perfis-gmb/index.blade.php`
- Test: `tests/Feature/PerfilGmbControllerTest.php` (adicionar teste novo ao arquivo existente)

**Interfaces:**
- Consumes: `GmbQualidadeScore` (Task 1), rota `admin.gmb-qualidade.show` (Task 3).

- [ ] **Step 1: Write the failing test**

Adicionar ao final da classe em `tests/Feature/PerfilGmbControllerTest.php` (mesmo arquivo, reaproveitando os helpers `usuarioDono()` e `criarPerfil()` já existentes nele):

```php
    public function test_listagem_mostra_nota_de_qualidade_quando_ja_avaliado(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        \App\Models\GmbQualidadeScore::create([
            'tenant_id'     => $tenant->id,
            'perfil_gmb_id' => $perfil->id,
            'nota_geral'    => 75,
            'categorias'    => [],
            'avaliado_em'   => now(),
        ]);

        $response = $this->actingAs($dono)->get('/admin/gmb/perfis-gmb');

        $response->assertOk();
        $response->assertSee('75');
        $response->assertSee(route('admin.gmb-qualidade.show', $perfil));
    }

    public function test_listagem_mostra_ainda_nao_avaliado_quando_nao_ha_score(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->get('/admin/gmb/perfis-gmb');

        $response->assertOk();
        $response->assertSee('Ainda não avaliado');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PerfilGmbControllerTest`
Expected: FAIL nos 2 testes novos — a coluna/link ainda não existe na view.

- [ ] **Step 3: Update the controller**

Em `app/Http/Controllers/PerfilGmbController.php`, substituir o método `index()`:

```php
    public function index(Request $request)
    {
        $perfis = PerfilGmb::where('tenant_id', $request->user()->tenantAtual())
            ->withCount(['contatos as contatos_pendentes_count' => fn ($q) => $q->naoContatados()])
            ->with(['qualidadeScore' => fn ($q) => $q])
            ->orderBy('nome')
            ->paginate(20);

        return view('admin.perfis-gmb.index', compact('perfis'));
    }
```

- [ ] **Step 4: Add the relation used above**

Em `app/Models/PerfilGmb.php`, adicionar junto dos outros relacionamentos (perto de `posts()`):

```php
    public function qualidadeScore(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GmbQualidadeScore::class, 'perfil_gmb_id');
    }
```

- [ ] **Step 5: Update the view**

Em `resources/views/admin/perfis-gmb/index.blade.php`, adicionar a coluna no cabeçalho (depois de "Status"):

```blade
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Qualidade</th>
```

E no corpo da tabela, logo depois da célula de Status (antes da célula de Ações):

```blade
                    <td class="px-4 py-3 text-center">
                        @if($perfil->qualidadeScore)
                            @php
                                $nota = $perfil->qualidadeScore->nota_geral;
                                $corBadge = is_null($nota) ? 'bg-gray-100 text-gray-500' : ($nota >= 90 ? 'bg-green-100 text-green-700' : ($nota >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'));
                            @endphp
                            <a href="{{ route('admin.gmb-qualidade.show', $perfil) }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 {{ $corBadge }} rounded-full text-xs font-bold hover:opacity-80 transition">
                                {{ $nota ?? '—' }}
                            </a>
                        @else
                            <a href="{{ route('admin.gmb-qualidade.show', $perfil) }}" class="text-xs text-gray-400 hover:text-gray-600 hover:underline">
                                Ainda não avaliado
                            </a>
                        @endif
                    </td>
```

E atualizar o `colspan` da linha vazia (`@empty`) de `6` para `7`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter PerfilGmbControllerTest`
Expected: PASS (todos os testes do arquivo, incluindo os 2 novos)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PerfilGmbController.php app/Models/PerfilGmb.php resources/views/admin/perfis-gmb/index.blade.php tests/Feature/PerfilGmbControllerTest.php
git commit -m "feat: adiciona coluna de qualidade na listagem de Perfis GMB"
```

---

### Task 5: Rodar a suíte completa e revisar o branch

- [ ] **Step 1: Rodar todos os testes do projeto**

Run: `php artisan test`
Expected: PASS em tudo (nenhum teste pré-existente quebrado pelas mudanças em `PerfilGmb`/`PerfilGmbController`/`routes/gmb-web.php`).

- [ ] **Step 2: Revisão final**

Usar `superpowers:requesting-code-review` no diff completo desta feature antes de abrir PR/mergear, olhando especialmente:
- `nota_geral` nunca conta categoria `pendente` na média.
- Tenant isolation em `GmbQualidadeScore` e no controller (`abort_if`).
- View não quebra quando `categorias` ainda não tem nenhuma categoria calculada.

---

## Próximos planos (fora deste)

Cada categoria abaixo vira o seu próprio plano, seguindo exatamente o padrão desta Task 2 (um método
privado no `GmbQualidadeService`, testes cobrindo as faixas de nota, sem tocar no resto):

1. **Identidade** — primeira a integrar a Business Information API (`locations.get`).
2. **Localização** — reaproveita a mesma chamada de Identidade.
3. **Conteúdo** — fotos, catálogo, atributos.
4. **Reputação** — primeira integração com o endpoint de reviews da v4.
5. **Presença Externa + Saúde/Risco** — majoritariamente checklist manual.

Fora de escopo de qualquer versão próxima (já registrado no backlog e na spec): responder avaliação via
API, escrever direto na ficha do Google, verificação automática de Schema.org, comparação com concorrente.
