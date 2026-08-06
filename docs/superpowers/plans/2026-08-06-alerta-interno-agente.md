# Infra de Alerta Interno do Agente — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao sistema um mecanismo genérico e persistente de aviso privado ao atendente humano — nunca visível ao lead — que os Blocos 2/3/4 (ainda sem spec) vão usar depois pras Regras 1, 2, 3, 12 e 13 do documento de contexto.

**Architecture:** Tabela nova `alertas_internos` (tenant-scoped, `ticket_id` opcional), `AlertaInternoService::criar()` como ponto único de escrita reutilizável por consumidores futuros, API de leitura/marcação simples direto no model (sem serviço — mesmo padrão já usado em `KanbanColunaObjetivoController`), e um ícone novo na barra de topo ao lado do sino existente, componente Alpine próprio.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (produção) / SQLite (testes), Alpine.js v3, Tailwind CSS.

## Global Constraints

- Multi-tenant: o model novo usa `TenantScope` como global scope (padrão de `KanbanColunaObjetivo`/`Kanban`); controllers filtram `tenant_id` explicitamente também, não confiam só no global scope (mesmo padrão defensivo já usado em `KanbanColunaObjetivoController`).
- `ticket_id` é nullable e usa `nullOnDelete()`, não `cascadeOnDelete()` — um alerta sobrevive à exclusão do ticket que o originou (spec §7).
- `tipo` é string livre (não enum de banco) — cada bloco futuro introduz seus próprios valores sem precisar de migration nova.
- Nenhum consumidor de `AlertaInternoService::criar()` existe ainda neste plano — é infra pura, testada isoladamente, pronta pros Blocos 2/3/4 chamarem depois.
- Especificação completa: `docs/superpowers/specs/2026-08-06-alerta-interno-agente-design.md`. Documento de contexto (13 regras): `docs/superpowers/specs/2026-08-06-regras-atendimento-ia-humano-contexto.md`.

---

### Task 1: Migration e Model `AlertaInterno`

**Files:**
- Create: `database/migrations/2026_08_06_000001_create_alertas_internos_table.php`
- Create: `app/Models/AlertaInterno.php`
- Test: `tests/Feature/AlertaInternoModelTest.php`

**Interfaces:**
- Produces: `AlertaInterno` model com colunas `id, tenant_id, ticket_id, tipo, titulo, conteudo, lido_em, created_at, updated_at` — usado pelas Tasks 2 e 3.

- [ ] **Step 1: Criar a migration**

```php
<?php
// database/migrations/2026_08_06_000001_create_alertas_internos_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_internos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_atendimento')->nullOnDelete();
            $table->string('tipo', 50);
            $table->string('titulo', 150);
            $table->text('conteudo');
            $table->timestamp('lido_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lido_em']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_internos');
    }
};
```

- [ ] **Step 2: Criar o model**

```php
<?php
// app/Models/AlertaInterno.php
namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertaInterno extends Model
{
    protected $table = 'alertas_internos';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'tipo',
        'titulo',
        'conteudo',
        'lido_em',
    ];

    protected $casts = [
        'lido_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }
}
```

- [ ] **Step 3: Escrever o teste do model**

```php
<?php
// tests/Feature/AlertaInternoModelTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_alerta_sem_ticket_com_casts_corretos(): void
    {
        $tenant = Tenant::factory()->create();

        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'monitoramento_coluna',
            'titulo'    => '3 leads travados na coluna Orçamento',
            'conteudo'  => 'Nenhuma movimentação há mais de 2 dias.',
        ]);

        $this->assertNull($alerta->fresh()->ticket_id);
        $this->assertNull($alerta->fresh()->lido_em);
    }

    public function test_cria_alerta_vinculado_a_ticket_e_marca_lido(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Preço fora da tabela',
            'conteudo' => 'O lead perguntou sobre um item que não está na tabela de preços.',
        ]);

        $alerta->update(['lido_em' => now()]);

        $this->assertSame($ticket->id, $alerta->fresh()->ticket_id);
        $this->assertNotNull($alerta->fresh()->lido_em);
    }

    public function test_alerta_sobrevive_a_exclusao_do_ticket(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'migracao_coluna', 'titulo' => 'Migrou de coluna', 'conteudo' => 'x',
        ]);

        $ticket->delete();

        $this->assertNull($alerta->fresh()->ticket_id);
        $this->assertNotNull(AlertaInterno::find($alerta->id));
    }
}
```

- [ ] **Step 4: Rodar as migrations e o teste**

Run: `php artisan test --filter=AlertaInternoModelTest`
Expected: PASS (3 testes) — `RefreshDatabase` roda a migration nova automaticamente no SQLite em memória.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_06_000001_create_alertas_internos_table.php \
        app/Models/AlertaInterno.php tests/Feature/AlertaInternoModelTest.php
git commit -m "feat: schema e model do alerta interno do agente"
```

---

### Task 2: `AlertaInternoService::criar()`

**Files:**
- Create: `app/Services/AlertaInternoService.php`
- Test: `tests/Feature/AlertaInternoServiceTest.php`

**Interfaces:**
- Consumes: `AlertaInterno` (Task 1).
- Produces: `AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno` — assinatura estável, é o que os Blocos 2/3/4 vão chamar depois. Não mudar esses nomes/tipos de parâmetro sem atualizar a spec.

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/AlertaInternoServiceTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_persiste_alerta_sem_ticket(): void
    {
        $tenant = Tenant::factory()->create();

        $alerta = app(AlertaInternoService::class)->criar(
            $tenant->id, 'monitoramento_coluna', 'Título', 'Conteúdo do alerta'
        );

        $this->assertDatabaseHas('alertas_internos', [
            'id' => $alerta->id, 'tenant_id' => $tenant->id,
            'ticket_id' => null, 'tipo' => 'monitoramento_coluna',
        ]);
    }

    public function test_criar_persiste_alerta_vinculado_a_ticket(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $alerta = app(AlertaInternoService::class)->criar(
            $tenant->id, 'duvida_ia', 'Título', 'Conteúdo', $ticket->id
        );

        $this->assertSame($ticket->id, $alerta->fresh()->ticket_id);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=AlertaInternoServiceTest`
Expected: FAIL — `App\Services\AlertaInternoService` não existe.

- [ ] **Step 3: Implementar o service**

```php
<?php
// app/Services/AlertaInternoService.php
namespace App\Services;

use App\Models\AlertaInterno;

class AlertaInternoService
{
    public function criar(
        int $tenantId,
        string $tipo,
        string $titulo,
        string $conteudo,
        ?int $ticketId = null,
    ): AlertaInterno {
        return AlertaInterno::create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'tipo'      => $tipo,
            'titulo'    => $titulo,
            'conteudo'  => $conteudo,
        ]);
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=AlertaInternoServiceTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AlertaInternoService.php tests/Feature/AlertaInternoServiceTest.php
git commit -m "feat: AlertaInternoService::criar() como ponto único de escrita"
```

---

### Task 3: API — listar, marcar lido, marcar todos lidos

**Files:**
- Create: `app/Http/Controllers/Painel/AlertaInternoController.php`
- Modify: `routes/web.php` (adicionar rotas logo após o bloco de `/agenda-imediata`, ~linha 311)
- Test: Create `tests/Feature/AlertaInternoControllerTest.php`

**Interfaces:**
- Consumes: `AlertaInterno` (Task 1).
- Produces: `GET /api/painel/alertas` → `{data: [...], nao_lidos_count: int}`; `POST /api/painel/alertas/{id}/marcar-lido`; `POST /api/painel/alertas/marcar-todos-lidos` — usados pela Task 4 (UI).

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/AlertaInternoControllerTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_alertas_do_tenant_com_contagem_de_nao_lidos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);
        AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'B', 'conteudo' => 'x', 'lido_em' => now()]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJson(['nao_lidos_count' => 1]);
    }

    public function test_lista_ordena_mais_recente_primeiro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $antigo = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'Antigo', 'conteudo' => 'x', 'created_at' => now()->subDay()]);
        $novo   = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'Novo', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $novo->id);
        $response->assertJsonPath('data.1.id', $antigo->id);
    }

    public function test_marcar_lido_individual(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $alerta = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->postJson("/api/painel/alertas/{$alerta->id}/marcar-lido");

        $response->assertOk();
        $this->assertNotNull($alerta->fresh()->lido_em);
    }

    public function test_marcar_todos_lidos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $a = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);
        $b = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'B', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->postJson('/api/painel/alertas/marcar-todos-lidos');

        $response->assertOk();
        $this->assertNotNull($a->fresh()->lido_em);
        $this->assertNotNull($b->fresh()->lido_em);
    }

    public function test_isolamento_por_tenant(): void
    {
        $tenantA  = Tenant::factory()->create();
        $tenantB  = Tenant::factory()->create();
        $userA    = $this->criarUsuario($tenantA);
        $alertaB  = AlertaInterno::create(['tenant_id' => $tenantB->id, 'tipo' => 'duvida_ia', 'titulo' => 'De outro tenant', 'conteudo' => 'x']);

        $listagem = $this->actingAs($userA)->getJson('/api/painel/alertas');
        $listagem->assertJsonCount(0, 'data');

        $marcar = $this->actingAs($userA)->postJson("/api/painel/alertas/{$alertaB->id}/marcar-lido");
        $marcar->assertStatus(404);
    }

    public function test_lista_pagina_com_20_por_pagina(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        for ($i = 0; $i < 25; $i++) {
            AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => "Alerta {$i}", 'conteudo' => 'x']);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonCount(20, 'data');
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=AlertaInternoControllerTest`
Expected: FAIL em todos — rotas ainda não existem (404 inesperado nos que esperam 200).

- [ ] **Step 3: Criar o controller**

```php
<?php
// app/Http/Controllers/Painel/AlertaInternoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AlertaInterno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaInternoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $alertas = AlertaInterno::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(20);

        $naoLidos = AlertaInterno::where('tenant_id', $tenantId)
            ->whereNull('lido_em')
            ->count();

        return response()->json([
            'data'            => $alertas->items(),
            'nao_lidos_count' => $naoLidos,
        ]);
    }

    public function marcarLido(Request $request, int $id): JsonResponse
    {
        $alerta = AlertaInterno::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $alerta->update(['lido_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function marcarTodosLidos(Request $request): JsonResponse
    {
        AlertaInterno::where('tenant_id', $request->user()->tenant_id)
            ->whereNull('lido_em')
            ->update(['lido_em' => now()]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `routes/web.php`, adicione o `use` no topo junto aos outros controllers do Painel:

```php
use App\Http\Controllers\Painel\AlertaInternoController;
```

E logo depois do bloco `// Agenda imediata (sino)` (linha ~311), adicione (mesmo padrão de agrupamento já usado no bloco `/kanban/tickets`, em vez de repetir `->middleware()` em cada rota):

```php
    // Alertas internos do agente
    Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {
        Route::get('/alertas', [AlertaInternoController::class, 'index']);
        Route::post('/alertas/{id}/marcar-lido', [AlertaInternoController::class, 'marcarLido']);
        Route::post('/alertas/marcar-todos-lidos', [AlertaInternoController::class, 'marcarTodosLidos']);
    });
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=AlertaInternoControllerTest`
Expected: PASS (6 testes)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/AlertaInternoController.php routes/web.php \
        tests/Feature/AlertaInternoControllerTest.php
git commit -m "feat: API de listagem e marcação de lido dos alertas internos"
```

---

### Task 4: UI — ícone e dropdown na barra de topo

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: `GET/POST /api/painel/alertas[/...]` (Task 3).

- [ ] **Step 1: Adicionar o ícone e dropdown, ao lado do sino existente**

Em `resources/views/layouts/app.blade.php`, dentro do `<div class="flex items-center justify-end px-6 py-2 ...">` que hoje só contém o `<div x-data="agendaSino()" ...>` do sino (linha ~324-390), adicione um segundo bloco irmão logo depois do `</div>` que fecha o sino (linha 389) e antes do `</div>` que fecha a barra de topo (linha 390):

```html
            <div x-data="alertasDropdown()"
                 x-init="carregar(); setInterval(() => carregar(), 60000)"
                 @click.outside="aberto = false"
                 class="relative ml-2">
                <button @click="aberto = !aberto; if (aberto) marcarVisualizados()"
                        class="relative p-1.5 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                    </svg>
                    <template x-if="naoLidos > 0">
                        <span class="absolute -top-0.5 -right-0.5 bg-red-500 text-white text-xs font-bold rounded-full w-4 h-4 flex items-center justify-center leading-none"
                              x-text="naoLidos > 9 ? '9+' : naoLidos"></span>
                    </template>
                </button>

                <template x-if="aberto">
                    <div class="absolute right-0 top-full mt-1 w-80 bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden z-50">
                        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-800">Alertas do agente</span>
                            <button x-show="alertas.length > 0" @click="marcarTodosLidos()"
                                    class="text-xs text-gray-400 hover:text-gray-600">Marcar tudo como lido</button>
                        </div>

                        <div class="max-h-96 overflow-y-auto">
                            <template x-for="item in alertas" :key="item.id">
                                <div class="px-4 py-2.5 border-b border-gray-50 last:border-0"
                                     :class="!item.lido_em ? 'bg-blue-50/50' : ''">
                                    <div class="flex items-start gap-2">
                                        <span x-show="!item.lido_em" class="mt-1.5 w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-medium text-gray-800" x-text="item.titulo"></p>
                                            <p class="text-xs text-gray-400 mt-0.5" x-text="item.conteudo"></p>
                                            <a x-show="item.ticket_id" :href="'/kanban'" @click="aberto = false"
                                               class="text-xs text-green-600 font-medium hover:underline">Abrir ticket</a>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <template x-if="alertas.length === 0">
                            <div class="px-4 py-6 text-center text-xs text-gray-400">
                                Nenhum alerta por enquanto.
                            </div>
                        </template>
                    </div>
                </template>
            </div>
```

- [ ] **Step 2: Adicionar o componente Alpine**

No mesmo arquivo, dentro do bloco `<script>` já existente (logo depois da função `agendaSino()`, ~linha 447 após seu `}` de fechamento), adicione:

```js
function alertasDropdown() {
    return {
        aberto:   false,
        alertas:  [],
        naoLidos: 0,

        async carregar() {
            try {
                const res = await fetch('/api/painel/alertas', {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                if (res.ok) {
                    const data     = await res.json();
                    this.alertas   = data.data ?? [];
                    this.naoLidos  = data.nao_lidos_count ?? 0;
                }
            } catch (_) {}
        },

        async marcarVisualizados() {
            // Marca como lido ao abrir o dropdown, um a um, só os que ainda não foram —
            // evita uma segunda rota "marcar todos" disparando sem o usuário ter escolhido.
            const pendentes = this.alertas.filter(a => !a.lido_em);
            for (const alerta of pendentes) {
                alerta.lido_em = new Date().toISOString();
            }
            this.naoLidos = 0;
        },

        async marcarTodosLidos() {
            try {
                await fetch('/api/painel/alertas/marcar-todos-lidos', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                await this.carregar();
            } catch (_) {}
        },
    };
}
```

**Nota de decisão:** `marcarVisualizados()` (ao abrir o dropdown) só atualiza o estado local otimisticamente para zerar o badge na hora — não chama a API individualmente por item. A marcação real como lido no banco acontece via `marcarTodosLidos()` (botão explícito) ou individualmente numa evolução futura, se precisar. Abrir o dropdown limpa o badge visualmente sem exigir um clique extra, mas não perde o registro de "lido de verdade" no servidor até o usuário confirmar — evita marcar como lido no banco algo que a pessoa só viu de relance sem prestar atenção.

- [ ] **Step 3: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: ícone e dropdown de alertas internos na barra de topo"
```

---

## Depois de todas as tasks

- [ ] Rodar a suíte inteira uma última vez: `php artisan test` — esperado: só a falha pré-existente e conhecida de `ExampleTest`.
- [ ] `./deploy.sh` — migration roda automaticamente em produção.
- [ ] Testar manualmente em produção: abrir o painel, confirmar que o ícone novo aparece ao lado do sino (mesmo sem nenhum alerta ainda, já que nenhum bloco consumidor existe nesta entrega).
- [ ] Atualizar `TAREFAS.md`: marcar o Bloco 1 de `T-BASE-CONHECIMENTO-KANBAN` → na verdade este é um item novo, fora daquela frente — criar entrada própria `T-REGRAS-ATENDIMENTO-IA-HUMANO` no backlog referenciando os 4 blocos, com o Bloco 1 concluído.
