# Base de Conhecimento por Kanban e por Coluna — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao agente de IA uma base de conhecimento estruturada por Kanban (visão geral) e por coluna (instruções + checklist de objetivos configurável para avançar), substituindo o checklist hardcoded pra frete/mudança que existe hoje.

**Architecture:** Um campo texto novo em `kanbans` (conhecimento geral), reaproveitamento do `kanban_coluna_configs.ia_contexto` já existente (informações da coluna), uma tabela nova `kanban_coluna_objetivos` (checklist configurável por coluna) e um campo JSON em `tickets_atendimento` rastreando o progresso por lead. O agente reporta progresso via token na própria resposta (`[OBJETIVO_CUMPRIDO:<id>]`), mesmo mecanismo já usado pelos tokens de movimento de coluna em `SdrResponderService::responder()`.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (produção) / SQLite (testes), Alpine.js v3, Tailwind CSS.

## Global Constraints

- Multi-tenant: todo model novo usa `TenantScope` como global scope (padrão de `KanbanColunaConfig`/`Kanban`); leituras em contexto de job/webhook usam `withoutGlobalScopes()`.
- Paridade Uazapi/Covercut: esta spec não altera nada específico de canal — `SdrResponderService` já é canal-agnóstico (`$canal->servico()->enviarTexto()`), nenhuma mudança necessária aí.
- `derivarChecklist()` é removido nesta entrega — sem fallback hardcoded pra frete. Tenant sem objetivos cadastrados simplesmente não tem bloco de checklist no prompt.
- Toda migration de schema é aditiva (nullable ou com default) — não quebra tenants existentes.
- Especificação completa: `docs/superpowers/specs/2026-08-05-base-conhecimento-kanban-design.md`.

---

### Task 1: Migrations e Models

**Files:**
- Create: `database/migrations/2026_08_05_000002_add_conhecimento_geral_to_kanbans.php`
- Create: `database/migrations/2026_08_05_000003_create_kanban_coluna_objetivos_table.php`
- Create: `database/migrations/2026_08_05_000004_add_objetivos_cumpridos_to_tickets_atendimento.php`
- Create: `app/Models/KanbanColunaObjetivo.php`
- Modify: `app/Models/Kanban.php:19-24` (adicionar `conhecimento_geral` ao `$fillable`)
- Modify: `app/Models/TicketAtendimento.php:42-88` (adicionar `objetivos_cumpridos` ao `$fillable` e cast `array` em `casts()`)
- Test: `tests/Feature/KanbanColunaObjetivoModelTest.php`

**Interfaces:**
- Produces: `KanbanColunaObjetivo` model com colunas `id, tenant_id, coluna_kanban, texto, ordem, ativo` — usado pelas Tasks 3, 6 e 9.
- Produces: `Kanban::$conhecimento_geral` (string|null) — usado pela Task 2.
- Produces: `TicketAtendimento::$objetivos_cumpridos` (array, cast) — usado pelas Tasks 3, 4 e 10.

- [ ] **Step 1: Criar migration de `kanbans.conhecimento_geral`**

```php
<?php
// database/migrations/2026_08_05_000002_add_conhecimento_geral_to_kanbans.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->text('conhecimento_geral')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->dropColumn('conhecimento_geral');
        });
    }
};
```

- [ ] **Step 2: Criar migration da tabela `kanban_coluna_objetivos`**

```php
<?php
// database/migrations/2026_08_05_000003_create_kanban_coluna_objetivos_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('coluna_kanban', 50);
            $table->string('texto', 255);
            $table->unsignedSmallInteger('ordem')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'coluna_kanban', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_coluna_objetivos');
    }
};
```

- [ ] **Step 3: Criar migration de `tickets_atendimento.objetivos_cumpridos`**

```php
<?php
// database/migrations/2026_08_05_000004_add_objetivos_cumpridos_to_tickets_atendimento.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->json('objetivos_cumpridos')->nullable()->after('lista_itens');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('objetivos_cumpridos');
        });
    }
};
```

- [ ] **Step 4: Criar o model `KanbanColunaObjetivo`**

```php
<?php
// app/Models/KanbanColunaObjetivo.php
namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColunaObjetivo extends Model
{
    protected $table = 'kanban_coluna_objetivos';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'coluna_kanban',
        'texto',
        'ordem',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 5: Adicionar `conhecimento_geral` ao `Kanban::$fillable`**

Em `app/Models/Kanban.php`, troque:

```php
    protected $fillable = [
        'tenant_id',
        'tipo',
        'nome',
        'ordem',
    ];
```

por:

```php
    protected $fillable = [
        'tenant_id',
        'tipo',
        'nome',
        'ordem',
        'conhecimento_geral',
    ];
```

- [ ] **Step 6: Adicionar `objetivos_cumpridos` ao `TicketAtendimento`**

Em `app/Models/TicketAtendimento.php`, no `$fillable` (linha ~70), adicione `'objetivos_cumpridos',` logo depois de `'visualizado_em',`. No método `casts()` (linha ~73-88), adicione ao array retornado:

```php
            'objetivos_cumpridos'   => 'array',
```

- [ ] **Step 7: Escrever o teste do model**

```php
<?php
// tests/Feature/KanbanColunaObjetivoModelTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_objetivo_com_casts_corretos(): void
    {
        $tenant = Tenant::factory()->create();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id'     => $tenant->id,
            'coluna_kanban' => 'em_atendimento',
            'texto'         => 'Endereço de origem confirmado',
            'ordem'         => 1,
            'ativo'         => true,
        ]);

        $this->assertTrue($objetivo->fresh()->ativo);
        $this->assertIsInt($objetivo->fresh()->ordem);
    }

    public function test_ticket_persiste_objetivos_cumpridos_como_array(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'objetivos_cumpridos' => [1, 3],
        ]);

        $this->assertSame([1, 3], $ticket->fresh()->objetivos_cumpridos);
    }
}
```

- [ ] **Step 8: Rodar as migrations e o teste**

Run: `php artisan test --filter=KanbanColunaObjetivoModelTest`
Expected: PASS (2 testes) — `RefreshDatabase` roda as migrations novas automaticamente no SQLite em memória.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_05_000002_add_conhecimento_geral_to_kanbans.php \
        database/migrations/2026_08_05_000003_create_kanban_coluna_objetivos_table.php \
        database/migrations/2026_08_05_000004_add_objetivos_cumpridos_to_tickets_atendimento.php \
        app/Models/KanbanColunaObjetivo.php app/Models/Kanban.php app/Models/TicketAtendimento.php \
        tests/Feature/KanbanColunaObjetivoModelTest.php
git commit -m "feat: schema da base de conhecimento por Kanban e por coluna"
```

---

### Task 2: Injetar `kanban.conhecimento_geral` no prompt do agente

**Files:**
- Modify: `app/Services/SdrResponderService.php:254-260`
- Test: `tests/Feature/SdrResponderServiceHistoricoTest.php` (adicionar teste)

**Interfaces:**
- Consumes: `Kanban::$conhecimento_geral` (Task 1).
- Produces: nenhuma interface nova — só enriquece o `content` do system message já produzido por `montarHistorico()`.

- [ ] **Step 1: Escrever o teste**

Adicione ao final da classe em `tests/Feature/SdrResponderServiceHistoricoTest.php` (antes do `}` final):

```php
    public function test_conhecimento_geral_do_kanban_entra_no_prompt(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        \App\Models\Kanban::where('tenant_id', $ticket->tenant_id)->where('tipo', 'vendas')
            ->update(['conhecimento_geral' => 'Este Kanban atende só clientes da Zona Sul do Rio.']);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Oi! Vamos começar.');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringContainsString('Este Kanban atende só clientes da Zona Sul do Rio.', $mensagensCapturadas[0]['content']);
    }
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=test_conhecimento_geral_do_kanban_entra_no_prompt`
Expected: FAIL — `Failed asserting that ... contains "Este Kanban atende só clientes da Zona Sul do Rio."`

- [ ] **Step 3: Injetar o conhecimento geral do Kanban**

Em `app/Services/SdrResponderService.php`, dentro de `montarHistorico()`, logo depois do bloco que monta `$iaContexto` com `tenant->ia_contexto`/`tabela_precos_texto` (linhas 254-260) e antes do comentário `// Contexto específico da coluna atual`:

```php
        $kanban = \App\Models\Kanban::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('tipo', 'vendas')
            ->first();

        if ($kanban?->conhecimento_geral) {
            $iaContexto .= ($iaContexto ? "\n\n" : '') . "=== CONHECIMENTO GERAL DESTE KANBAN ===\n" . $kanban->conhecimento_geral . "\n===";
        }
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=SdrResponderServiceHistoricoTest`
Expected: PASS (todos os testes da classe, incluindo o novo)

- [ ] **Step 5: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceHistoricoTest.php
git commit -m "feat: injeta conhecimento geral do Kanban no prompt do agente"
```

---

### Task 3: Substituir `derivarChecklist()` pelo checklist configurável

**Files:**
- Modify: `app/Services/SdrResponderService.php:176-220` (remove `derivarChecklist`), `:297-298` e `:309-322` (troca de `$checklistState`)
- Test: Create `tests/Feature/SdrResponderServiceObjetivosTest.php`

**Interfaces:**
- Consumes: `KanbanColunaObjetivo` (Task 1), `TicketAtendimento::$objetivos_cumpridos` (Task 1).
- Produces: `SdrResponderService::montarBlocoObjetivos(TicketAtendimento $ticket): ?string` — usado só internamente por `montarHistorico()` (não precisa ser público, mas fica `private` como os outros helpers).

- [ ] **Step 1: Escrever os testes**

```php
<?php
// tests/Feature/SdrResponderServiceObjetivosTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceObjetivosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComPersona(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_bloco_de_objetivos_aparece_no_prompt_com_status_correto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens coletada', 'ordem' => 2, 'ativo' => true,
        ]);

        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $prompt = $mensagensCapturadas[0]['content'];
        $this->assertStringContainsString('✅ Endereço de origem confirmado', $prompt);
        $this->assertStringContainsString('❌ Lista de itens coletada: pendente', $prompt);
        $this->assertStringContainsString('OBJETIVO_CUMPRIDO', $prompt);
    }

    public function test_bloco_de_objetivos_nao_aparece_quando_coluna_nao_tem_nenhum(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringNotContainsString('OBJETIVOS DESTA ETAPA', $mensagensCapturadas[0]['content']);
    }

    public function test_objetivo_inativo_nao_aparece_no_bloco(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComPersona();

        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Objetivo desativado', 'ordem' => 1, 'ativo' => false,
        ]);

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertStringNotContainsString('Objetivo desativado', $mensagensCapturadas[0]['content']);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=SdrResponderServiceObjetivosTest`
Expected: FAIL no primeiro teste (`✅ Endereço de origem confirmado` não aparece — o prompt ainda usa `[STATUS_CHECKLIST]` hardcoded). Os outros dois passam por acidente (o bloco antigo também não contém essas strings) — não é problema, serão validados de verdade depois da implementação.

- [ ] **Step 3: Remover `derivarChecklist()` e criar `montarBlocoObjetivos()`**

Em `app/Services/SdrResponderService.php`, **apague inteiro** o método `derivarChecklist()` (linhas 176-220, do `private function derivarChecklist` até o `}` que fecha ele, logo antes de `private function montarHistorico`).

No lugar dele, adicione:

```php
    private function montarBlocoObjetivos(TicketAtendimento $ticket): ?string
    {
        $objetivos = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->get();

        if ($objetivos->isEmpty()) {
            return null;
        }

        $cumpridos = $ticket->objetivos_cumpridos ?? [];

        $linhas = $objetivos->map(function ($objetivo) use ($cumpridos) {
            $feito = in_array($objetivo->id, $cumpridos, true);
            return ($feito ? '✅ ' : '❌ ') . $objetivo->texto . ($feito ? '' : ': pendente');
        });

        return "=== OBJETIVOS DESTA ETAPA (marque quando cumprir) ===\n"
            . $linhas->implode("\n")
            . "\n\nPra marcar um objetivo como cumprido, inclua no final da sua resposta um token "
            . "[OBJETIVO_CUMPRIDO:<id>] — pode incluir mais de um na mesma resposta, um por linha. "
            . "NUNCA mencione ou explique esses tokens ao lead."
            . "\n===";
    }
```

Adicione o import no topo do arquivo, junto aos outros `use App\Models\...`:

```php
use App\Models\KanbanColunaObjetivo;
```

- [ ] **Step 4: Trocar a chamada dentro de `montarHistorico()`**

Troque a linha (~298):

```php
        $checklistState    = $this->derivarChecklist($ticket);
```

por:

```php
        $checklistState    = $this->montarBlocoObjetivos($ticket);
```

(a variável `$checklistState` continua com o mesmo nome — já é usada no `array_filter` do `$messages` logo abaixo, em `montarHistorico()`, então nenhuma outra linha precisa mudar.)

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SdrResponderServiceObjetivosTest`
Expected: PASS (3 testes)

- [ ] **Step 6: Rodar toda a suíte de `SdrResponderService` pra checar regressão**

Run: `php artisan test --filter=SdrResponderService`
Expected: PASS (todos — `derivarChecklist`/`STATUS_CHECKLIST` não é referenciado em nenhum outro teste, confirmado por busca antes de escrever este plano)

- [ ] **Step 7: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceObjetivosTest.php
git commit -m "feat: checklist de objetivos configurável substitui derivarChecklist hardcoded"
```

---

### Task 4: Agente reporta progresso via token na resposta

**Files:**
- Modify: `app/Services/SdrResponderService.php:54-83` (bloco de detecção de tokens em `responder()`)
- Test: Create `tests/Feature/SdrResponderServiceObjetivoTokenTest.php`

**Interfaces:**
- Consumes: `KanbanColunaObjetivo` (Task 1), `TicketAtendimento::$objetivos_cumpridos` (Task 1).
- Produces: nenhuma interface nova — comportamento observável via `TicketAtendimento::$objetivos_cumpridos` atualizado e o texto final enviado ao lead sem o token.

- [ ] **Step 1: Escrever os testes**

```php
<?php
// tests/Feature/SdrResponderServiceObjetivoTokenTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceObjetivoTokenTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComCanal(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_token_de_objetivo_marca_progresso_e_e_removido_da_mensagem_final(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($objetivo) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito, endereço anotado!\n[OBJETIVO_CUMPRIDO:{$objetivo->id}]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito, endereço anotado!', $resposta);
        $this->assertSame([$objetivo->id], $ticket->fresh()->objetivos_cumpridos);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Perfeito, endereço anotado!']);
    }

    public function test_multiplos_tokens_na_mesma_resposta_marcam_todos(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem', 'ordem' => 1, 'ativo' => true,
        ]);
        $obj2 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens', 'ordem' => 2, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj1, $obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Show, anotado tudo!\n[OBJETIVO_CUMPRIDO:{$obj1->id}]\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertEqualsCanonicalizing([$obj1->id, $obj2->id], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_objetivos_cumpridos_e_zerado_ao_mudar_de_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['objetivos_cumpridos' => [999]]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn("Vamos seguir!\n[AGUARDANDO_ORCAMENTO]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticketFresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $ticketFresco->coluna_kanban);
        $this->assertSame([], $ticketFresco->objetivos_cumpridos ?? []);
    }

    public function test_token_com_id_inexistente_e_ignorado_sem_quebrar(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito!\n[OBJETIVO_CUMPRIDO:999999]");
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertSame('Perfeito!', $resposta);
        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=SdrResponderServiceObjetivoTokenTest`
Expected: FAIL — `$resposta` ainda contém o texto `[OBJETIVO_CUMPRIDO:...]` (não é removido), e `objetivos_cumpridos` não é atualizado.

- [ ] **Step 3: Implementar a detecção do token em `responder()`**

Em `app/Services/SdrResponderService.php`, dentro de `responder()`, logo depois do bloco existente que resolve `$tokens`/`$resposta = trim(str_replace($tokens, '', $resposta));` (linhas 82-83) e antes do comentário `// ── 5. Enviar pelo canal certo`:

```php
        // ── 4.5. Detectar tokens de objetivo cumprido e aplicar ─────────────
        // Mesmo padrão dos tokens de movimento acima — o agente reporta na
        // própria resposta quais objetivos do checklist da coluna considera
        // cumpridos, e o sistema persiste isso no ticket antes de mandar a
        // mensagem pro lead sem o marcador.
        preg_match_all('/\[OBJETIVO_CUMPRIDO:(\d+)\]/', $resposta, $matchesObjetivos);
        if (! empty($matchesObjetivos[1])) {
            // Só aceita ids que realmente existem pra esse tenant/coluna — um
            // objetivo pode ter sido excluído entre a config ser lida (início
            // da chamada) e a resposta chegar, ou o modelo pode alucinar um id.
            // Sem essa validação, um id órfão infla pra sempre o "X/Y cumpridos"
            // do card sem corresponder a nenhum objetivo visível.
            $idsValidos = KanbanColunaObjetivo::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->pluck('id')
                ->all();

            $cumpridos = $ticket->objetivos_cumpridos ?? [];
            foreach ($matchesObjetivos[1] as $idTexto) {
                $id = (int) $idTexto;
                if (! in_array($id, $idsValidos, true)) {
                    Log::debug('SdrResponder: token de objetivo com id inexistente, ignorado', [
                        'ticket_id' => $ticket->id, 'id' => $id,
                    ]);
                    continue;
                }
                if (! in_array($id, $cumpridos, true)) {
                    $cumpridos[] = $id;
                }
            }
            $ticket->update(['objetivos_cumpridos' => $cumpridos]);
            Log::info('SdrResponder: objetivos marcados como cumpridos', [
                'ticket_id' => $ticket->id, 'ids' => $matchesObjetivos[1],
            ]);
        }
        $resposta = trim(preg_replace('/\[OBJETIVO_CUMPRIDO:\d+\]/', '', $resposta));
```

- [ ] **Step 4: Zerar `objetivos_cumpridos` ao mudar de coluna**

Ainda em `responder()`, no bloco que já monta `$updates` pra mover de coluna (linhas 72-76):

```php
                $papel   = \App\Models\KanbanColuna::papelDe($tenantId, $chave);
                $updates = $papel === \App\Enums\PapelColunaKanban::Encerramento
                    ? $ticket->dadosParaEncerrar(['etapa_ia' => $etapa], $chave)
                    : ['coluna_kanban' => $chave, 'etapa_ia' => $etapa];

                $ticket->update($updates);
```

troque as duas últimas linhas por:

```php
                $papel   = \App\Models\KanbanColuna::papelDe($tenantId, $chave);
                $updates = $papel === \App\Enums\PapelColunaKanban::Encerramento
                    ? $ticket->dadosParaEncerrar(['etapa_ia' => $etapa], $chave)
                    : ['coluna_kanban' => $chave, 'etapa_ia' => $etapa];
                $updates['objetivos_cumpridos'] = [];

                $ticket->update($updates);
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SdrResponderServiceObjetivoTokenTest`
Expected: PASS (3 testes)

- [ ] **Step 6: Rodar toda a suíte pra checar regressão**

Run: `php artisan test`
Expected: PASS em tudo, exceto a falha pré-existente conhecida de `ExampleTest::test_the_application_returns_a_successful_response` (não relacionada).

- [ ] **Step 7: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceObjetivoTokenTest.php
git commit -m "feat: agente reporta e persiste progresso do checklist via token na resposta"
```

---

### Task 5: API — Base de conhecimento do Kanban (conhecimento_geral)

**Files:**
- Create: `app/Http/Controllers/Painel/KanbanInfoController.php`
- Modify: `routes/web.php:360-362` (adicionar rotas dentro do grupo `role:admin,dono` já existente)
- Test: Create `tests/Feature/KanbanInfoControllerTest.php`

**Interfaces:**
- Consumes: `Kanban::$conhecimento_geral` (Task 1).
- Produces: `GET /api/painel/kanban/info` → `{conhecimento_geral: string}`; `PUT /api/painel/kanban/info` — usado pela Task 8 (UI).

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/KanbanInfoControllerTest.php
namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanInfoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_show_retorna_vazio_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info');

        $response->assertOk();
        $response->assertJson(['conhecimento_geral' => '']);
    }

    public function test_update_persiste_conhecimento_geral(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'conhecimento_geral' => 'Atendemos só Zona Sul do Rio de Janeiro.',
        ]);

        $response->assertOk();

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertSame('Atendemos só Zona Sul do Rio de Janeiro.', $kanban->conhecimento_geral);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=KanbanInfoControllerTest`
Expected: FAIL — rota `/api/painel/kanban/info` não existe (404).

- [ ] **Step 3: Criar o controller**

```php
<?php
// app/Http/Controllers/Painel/KanbanInfoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanInfoController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $kanban = Kanban::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'vendas')
            ->first();

        return response()->json([
            'conhecimento_geral' => $kanban?->conhecimento_geral ?? '',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conhecimento_geral' => 'nullable|string|max:20000',
        ]);

        $kanban = Kanban::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'vendas')
            ->firstOrFail();

        $kanban->update(['conhecimento_geral' => $validated['conhecimento_geral'] ?? null]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `routes/web.php`, dentro do grupo `Route::middleware('role:admin,dono')->group(function () {` que já contém as rotas de `kanban/coluna-config` (linha ~360), adicione logo abaixo:

```php
        Route::get('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'show']);
        Route::put('/kanban/coluna-config/{coluna}', [KanbanColunaConfigController::class, 'update']);
        // Base de conhecimento geral do Kanban
        Route::get('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'show']);
        Route::put('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'update']);
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=KanbanInfoControllerTest`
Expected: PASS (2 testes)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanInfoController.php routes/web.php tests/Feature/KanbanInfoControllerTest.php
git commit -m "feat: API da base de conhecimento geral do Kanban"
```

---

### Task 6: API — CRUD de objetivos por coluna

**Files:**
- Create: `app/Http/Controllers/Painel/KanbanColunaObjetivoController.php`
- Modify: `routes/web.php:207-223` (rota de listagem, grupo de roles amplo) e `routes/web.php:360-362` (rotas de escrita, grupo `role:admin,dono`)
- Test: Create `tests/Feature/KanbanColunaObjetivoControllerTest.php`

**Interfaces:**
- Consumes: `KanbanColunaObjetivo` (Task 1).
- Produces: `GET /api/painel/kanban/coluna-objetivos/{coluna}` (lista, roles amplos — usado pela Task 9 e Task 10); `POST/PUT/DELETE /api/painel/kanban/coluna-objetivos/{coluna}[/{id}]` e `POST .../reordenar` (admin/dono — usado pela Task 9).

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/KanbanColunaObjetivoControllerTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaObjetivoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_objetivos_da_coluna_em_ordem(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Segundo', 'ordem' => 2, 'ativo' => true]);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Primeiro', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-objetivos/em_atendimento');

        $response->assertOk();
        $response->assertJsonPath('0.texto', 'Primeiro');
        $response->assertJsonPath('1.texto', 'Segundo');
    }

    public function test_cria_objetivo_com_ordem_incremental(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Existente', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento', [
            'texto' => 'Novo objetivo',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('kanban_coluna_objetivos', [
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Novo objetivo', 'ordem' => 2,
        ]);
    }

    public function test_atualiza_texto_e_ativo(): void
    {
        $tenant   = Tenant::factory()->create();
        $user     = $this->criarUsuarioDono($tenant);
        $objetivo = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Antigo', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$objetivo->id}", [
            'texto' => 'Atualizado', 'ativo' => false,
        ]);

        $response->assertOk();
        $this->assertSame('Atualizado', $objetivo->fresh()->texto);
        $this->assertFalse($objetivo->fresh()->ativo);
    }

    public function test_exclui_e_reordena_os_restantes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        $obj1 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Um', 'ordem' => 1, 'ativo' => true]);
        $obj2 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Dois', 'ordem' => 2, 'ativo' => true]);
        $obj3 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Três', 'ordem' => 3, 'ativo' => true]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$obj1->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('kanban_coluna_objetivos', ['id' => $obj1->id]);
        $this->assertSame(1, $obj2->fresh()->ordem);
        $this->assertSame(2, $obj3->fresh()->ordem);
    }

    public function test_reordenar_aplica_nova_ordem(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        $obj1 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Um', 'ordem' => 1, 'ativo' => true]);
        $obj2 = KanbanColunaObjetivo::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'Dois', 'ordem' => 2, 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/coluna-objetivos/em_atendimento/reordenar', [
            'ids' => [$obj2->id, $obj1->id],
        ]);

        $response->assertOk();
        $this->assertSame(1, $obj2->fresh()->ordem);
        $this->assertSame(2, $obj1->fresh()->ordem);
    }

    public function test_isolamento_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = $this->criarUsuarioDono($tenantA);
        $objetivoB = KanbanColunaObjetivo::create(['tenant_id' => $tenantB->id, 'coluna_kanban' => 'em_atendimento', 'texto' => 'De outro tenant', 'ordem' => 1, 'ativo' => true]);

        $response = $this->actingAs($userA)->putJson("/api/painel/kanban/coluna-objetivos/em_atendimento/{$objetivoB->id}", [
            'texto' => 'Tentando alterar',
        ]);

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=KanbanColunaObjetivoControllerTest`
Expected: FAIL em todos — rotas ainda não existem (404).

- [ ] **Step 3: Criar o controller**

```php
<?php
// app/Http/Controllers/Painel/KanbanColunaObjetivoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\KanbanColunaObjetivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanColunaObjetivoController extends Controller
{
    public function index(Request $request, string $coluna): JsonResponse
    {
        $objetivos = KanbanColunaObjetivo::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->orderBy('ordem')
            ->get(['id', 'texto', 'ordem', 'ativo']);

        return response()->json($objetivos);
    }

    public function store(Request $request, string $coluna): JsonResponse
    {
        $validated = $request->validate(['texto' => 'required|string|max:255']);

        $tenantId = $request->user()->tenant_id;
        $ordem    = (KanbanColunaObjetivo::where('tenant_id', $tenantId)->where('coluna_kanban', $coluna)->max('ordem') ?? 0) + 1;

        $objetivo = KanbanColunaObjetivo::create([
            'tenant_id'     => $tenantId,
            'coluna_kanban' => $coluna,
            'texto'         => $validated['texto'],
            'ordem'         => $ordem,
            'ativo'         => true,
        ]);

        return response()->json($objetivo, 201);
    }

    public function update(Request $request, string $coluna, int $id): JsonResponse
    {
        $objetivo = KanbanColunaObjetivo::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->findOrFail($id);

        $validated = $request->validate([
            'texto' => 'sometimes|string|max:255',
            'ativo' => 'sometimes|boolean',
        ]);

        $objetivo->update($validated);

        return response()->json($objetivo->fresh());
    }

    public function destroy(Request $request, string $coluna, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        KanbanColunaObjetivo::where('tenant_id', $tenantId)
            ->where('coluna_kanban', $coluna)
            ->findOrFail($id)
            ->delete();

        KanbanColunaObjetivo::where('tenant_id', $tenantId)
            ->where('coluna_kanban', $coluna)
            ->orderBy('ordem')
            ->get()
            ->each(fn ($o, $i) => $o->update(['ordem' => $i + 1]));

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, string $coluna): JsonResponse
    {
        $dados = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;

        foreach ($dados['ids'] as $indice => $id) {
            KanbanColunaObjetivo::where('tenant_id', $tenantId)
                ->where('coluna_kanban', $coluna)
                ->where('id', $id)
                ->update(['ordem' => $indice + 1]);
        }

        return response()->json(['reordenado' => true]);
    }
}
```

- [ ] **Step 4: Adicionar a rota de listagem no grupo de roles amplo**

Em `routes/web.php`, dentro do grupo `Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {` que contém `/kanban/tickets` (linha ~207), adicione:

```php
        Route::get('/kanban/tickets', [KanbanController::class, 'index']);
        Route::get('/kanban/coluna-objetivos/{coluna}', [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'index']);
```

- [ ] **Step 5: Adicionar as rotas de escrita no grupo `role:admin,dono`**

No mesmo grupo da Task 5 (`routes/web.php`, linha ~360), adicione logo depois das rotas de `/kanban/info`:

```php
        Route::get('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'show']);
        Route::put('/kanban/info', [\App\Http\Controllers\Painel\KanbanInfoController::class, 'update']);
        // Checklist de objetivos por coluna
        Route::post('/kanban/coluna-objetivos/{coluna}',              [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'store']);
        Route::put('/kanban/coluna-objetivos/{coluna}/{id}',          [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'update']);
        Route::delete('/kanban/coluna-objetivos/{coluna}/{id}',       [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'destroy']);
        Route::post('/kanban/coluna-objetivos/{coluna}/reordenar',    [\App\Http\Controllers\Painel\KanbanColunaObjetivoController::class, 'reordenar']);
```

- [ ] **Step 6: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=KanbanColunaObjetivoControllerTest`
Expected: PASS (6 testes)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaObjetivoController.php routes/web.php tests/Feature/KanbanColunaObjetivoControllerTest.php
git commit -m "feat: API CRUD dos objetivos de checklist por coluna"
```

---

### Task 7: Migração dos 6 objetivos hardcoded do Frete Rio

**Files:**
- Create: `database/migrations/2026_08_05_000005_seed_objetivos_frete_rio.php`

**Interfaces:**
- Consumes: `kanban_coluna_objetivos` (Task 1).
- Produces: nada consumido por outra task — é o passo final de dados, roda depois que o resto da feature já está funcional.

- [ ] **Step 1: Criar a migration de seed**

```php
<?php
// database/migrations/2026_08_05_000005_seed_objetivos_frete_rio.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migra os 6 itens que eram hardcoded em
     * SdrResponderService::derivarChecklist() (removido nesta mesma entrega,
     * ver Task 3 do plano de 2026-08-05) pro novo formato configurável —
     * pra não perder a funcionalidade que o Frete Rio já tinha.
     */
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('nome', 'Frete Rio')->value('id');

        if (! $tenantId) {
            return; // ambiente sem o tenant Frete Rio (ex.: testes) — nada a fazer
        }

        $itens = [
            'Endereço de embarque confirmado',
            'Endereço de destino confirmado',
            'Lista de itens coletada',
            'Data e horário confirmados',
            'Escadas (lances reais) confirmadas',
            'Desmontagem/embalagem detalhada',
        ];

        $agora = now();

        foreach ($itens as $indice => $texto) {
            DB::table('kanban_coluna_objetivos')->insert([
                'tenant_id'     => $tenantId,
                'coluna_kanban' => 'em_atendimento',
                'texto'         => $texto,
                'ordem'         => $indice + 1,
                'ativo'         => true,
                'created_at'    => $agora,
                'updated_at'    => $agora,
            ]);
        }
    }

    /**
     * Não reverte a exclusão dos registros — reverter uma migration de dados
     * apagaria objetivos que o franqueado pode já ter editado desde então.
     * Se precisar desfazer, apagar manualmente pela tela de configuração.
     */
    public function down(): void
    {
        //
    }
};
```

- [ ] **Step 2: Rodar a migration localmente e conferir**

Run: `php artisan migrate`
Expected: migration `2026_08_05_000005_seed_objetivos_frete_rio` roda com sucesso. Em ambiente de teste local (sem tenant "Frete Rio" cadastrado), não insere nada — sem erro.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_08_05_000005_seed_objetivos_frete_rio.php
git commit -m "feat: migra os 6 objetivos hardcoded do Frete Rio pro checklist configurável"
```

---

### Task 8: UI — Base de conhecimento geral do Kanban

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `GET/PUT /api/painel/kanban/info` (Task 5).

- [ ] **Step 1: Adicionar o estado Alpine**

Em `resources/views/kanban/config.blade.php`, perto de onde `objetivo: {}` é declarado (linha ~1277, dentro do objeto de dados do componente Alpine principal), adicione um bloco novo de estado:

```php
        // Base de conhecimento geral do Kanban (não por coluna)
        conhecimentoGeral: '',
        conhecimentoGeralAlterado: false,
        conhecimentoGeralSalvando: false,
        conhecimentoGeralSalvo: false,
```

- [ ] **Step 2: Carregar o valor ao iniciar o componente**

Encontre o método `init()` (ou equivalente que roda no carregamento da página — geralmente chama `carregarColunas()`) e adicione uma chamada pra carregar o conhecimento geral junto. Se não houver um `init()` explícito, adicione este método e garanta que ele é chamado via `x-init="init()"` na tag raiz do componente (confira a tag `<div x-data="..." x-init="...">` no topo do arquivo):

```php
        async carregarConhecimentoGeral() {
            const res = await this.api('/api/painel/kanban/info');
            if (res.ok) {
                const json = await res.json();
                this.conhecimentoGeral = json.conhecimento_geral ?? '';
            }
        },

        async salvarConhecimentoGeral() {
            this.conhecimentoGeralSalvando = true;
            const res = await this.api('/api/painel/kanban/info', 'PUT', {
                conhecimento_geral: this.conhecimentoGeral,
            });
            this.conhecimentoGeralSalvando = false;
            if (res.ok) {
                this.conhecimentoGeralAlterado = false;
                this.conhecimentoGeralSalvo = true;
                setTimeout(() => { this.conhecimentoGeralSalvo = false; }, 3000);
            }
        },
```

Chame `this.carregarConhecimentoGeral();` dentro do `init()` do componente, junto com a chamada existente que carrega as colunas (ex.: `this.carregarColunas()`).

- [ ] **Step 3: Adicionar a seção na tela**

No topo do template, antes do primeiro `<template x-for="col in colunas" ...>` que renderiza a lista de colunas (procure por esse `x-for` pra achar o ponto certo — é onde a listagem de colunas começa), adicione:

```html
        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm p-5 mb-6">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s4.332.477 5.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <span class="text-sm font-semibold text-gray-700">Base de Conhecimento do Kanban</span>
            </div>
            <p class="text-xs text-gray-400 mb-3">O que a IA precisa saber sobre este Kanban como um todo — visão geral, estratégia, restrições que valem em qualquer coluna.</p>
            <textarea
                @input="conhecimentoGeral = $event.target.value; conhecimentoGeralAlterado = true"
                :value="conhecimentoGeral"
                placeholder="Ex: Este Kanban atende só clientes da Zona Sul do Rio de Janeiro."
                rows="4"
                class="w-full text-sm border border-gray-200 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-purple-400 resize-none bg-gray-50"
            ></textarea>
            <div class="flex items-center justify-end gap-2 mt-2">
                <span x-show="conhecimentoGeralSalvando" class="text-xs text-gray-400">Salvando...</span>
                <span x-show="conhecimentoGeralSalvo" class="text-xs text-green-600">✓ Salvo</span>
                <button @click="salvarConhecimentoGeral()"
                        :disabled="!conhecimentoGeralAlterado"
                        class="text-sm bg-purple-600 hover:bg-purple-700 disabled:opacity-40 text-white px-4 py-1.5 rounded-lg transition-colors">
                    Salvar
                </button>
            </div>
        </div>
```

- [ ] **Step 4: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: tela de base de conhecimento geral do Kanban"
```

---

### Task 9: UI — Editor de objetivos por coluna

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /api/painel/kanban/coluna-objetivos/{coluna}[/{id}]` e `.../reordenar` (Task 6).

- [ ] **Step 1: Adicionar o estado Alpine**

Junto do bloco `variacoesPor: {}` (linha ~1249), adicione:

```php
        objetivosPor: {},        // { [colunaKey]: [ {id, texto, ordem, ativo}, ... ] }
        objetivosCarregado: {},  // { [colunaKey]: bool } — evita recarregar toda vez que abre a seção
        novoObjetivoTexto: {},   // { [colunaKey]: string } — input de "adicionar objetivo"
```

- [ ] **Step 2: Métodos de carregar/CRUD**

Perto de `carregarVariacoes()` (linha ~1563), adicione:

```php
        async carregarObjetivos(colunaKey) {
            if (this.objetivosCarregado[colunaKey]) return;
            this.objetivosCarregado[colunaKey] = true;
            const res = await this.api(`/api/painel/kanban/coluna-objetivos/${colunaKey}`);
            this.objetivosPor[colunaKey] = res.ok ? await res.json() : [];
        },

        async adicionarObjetivo(colunaKey) {
            const texto = (this.novoObjetivoTexto[colunaKey] || '').trim();
            if (!texto) return;
            const res = await this.api(`/api/painel/kanban/coluna-objetivos/${colunaKey}`, 'POST', { texto });
            if (res.ok) {
                this.novoObjetivoTexto[colunaKey] = '';
                this.objetivosCarregado[colunaKey] = false;
                await this.carregarObjetivos(colunaKey);
            }
        },

        async toggleAtivoObjetivo(colunaKey, objetivo) {
            const res = await this.api(`/api/painel/kanban/coluna-objetivos/${colunaKey}/${objetivo.id}`, 'PUT', { ativo: !objetivo.ativo });
            if (res.ok) {
                this.objetivosCarregado[colunaKey] = false;
                await this.carregarObjetivos(colunaKey);
            }
        },

        async excluirObjetivo(colunaKey, objetivo) {
            if (!confirm('Excluir este objetivo?')) return;
            const res = await this.api(`/api/painel/kanban/coluna-objetivos/${colunaKey}/${objetivo.id}`, 'DELETE');
            if (res.ok) {
                this.objetivosCarregado[colunaKey] = false;
                await this.carregarObjetivos(colunaKey);
            }
        },
```

- [ ] **Step 3: Adicionar a seção no template, dentro de cada coluna**

No bloco "Agente de IA" por coluna (`resources/views/kanban/config.blade.php`, logo depois do checkbox "Transcrever áudio e analisar imagens/documentos nesta coluna" adicionado hoje mais cedo, e antes do `<div class="mt-3 flex items-center justify-between">` que tem o checkbox "Agente ativo nesta coluna"), adicione:

```html
                        <div class="mt-3 pt-3 border-t border-gray-100" x-init="carregarObjetivos(col.key)">
                            <label class="text-xs font-semibold text-gray-500 mb-1 block">Objetivos para avançar desta coluna</label>
                            <p class="text-xs text-gray-400 mb-2">O que o lead precisa ter respondido/confirmado antes de sair desta etapa. O agente marca sozinho conforme a conversa evolui.</p>

                            <div class="space-y-1.5 mb-2">
                                <template x-for="objetivo in (objetivosPor[col.key] || [])" :key="objetivo.id">
                                    <div class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-2 py-1.5">
                                        <span class="text-xs text-gray-700 flex-1" x-text="objetivo.texto"></span>
                                        <label class="flex items-center gap-1 flex-shrink-0" title="Objetivo ativo">
                                            <input type="checkbox" :checked="objetivo.ativo"
                                                   @change="toggleAtivoObjetivo(col.key, objetivo)"
                                                   class="w-3 h-3 accent-purple-600">
                                        </label>
                                        <button @click="excluirObjetivo(col.key, objetivo)"
                                                class="text-red-300 hover:text-red-500 flex-shrink-0 text-xs">✕</button>
                                    </div>
                                </template>
                            </div>

                            <div class="flex items-center gap-2">
                                <input type="text" :value="novoObjetivoTexto[col.key] || ''"
                                       @input="novoObjetivoTexto[col.key] = $event.target.value"
                                       @keydown.enter="adicionarObjetivo(col.key)"
                                       placeholder="Ex: Endereço de origem confirmado"
                                       class="flex-1 text-xs border border-gray-300 rounded-lg px-2 py-1.5">
                                <button @click="adicionarObjetivo(col.key)"
                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1.5 rounded-lg">+</button>
                            </div>
                        </div>
```

- [ ] **Step 4: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: editor de objetivos de checklist por coluna na tela de config"
```

---

### Task 10: UI — Progresso do checklist no card do Kanban

**Files:**
- Modify: `resources/views/kanban/index.blade.php`

**Interfaces:**
- Consumes: `GET /api/painel/kanban/coluna-objetivos/{coluna}` (Task 6), `TicketAtendimento::$objetivos_cumpridos` já presente automaticamente no JSON do ticket (Task 1 — nenhum trabalho de backend adicional, o model não restringe campos serializados).

- [ ] **Step 1: Adicionar o estado Alpine**

Junto de `itensAberto: false,` (linha ~634), adicione:

```php
        objetivosColuna:   [],
        objetivosAberto:   false,
```

- [ ] **Step 2: Carregar ao abrir o ticket**

Em `abrirTicket(ticket)` (linha ~830), logo depois de `this.limparMidia();` e antes de `this.mensagens = [];`, adicione:

```php
            this.objetivosColuna = [];
            this.objetivosAberto = false;
```

Depois da linha `await this.sincronizarTicketAtivo();`, adicione:

```php
            await this.carregarObjetivosColuna(ticket.coluna_kanban);
```

Adicione o método (perto de `carregarNotas`, linha ~854):

```php
        async carregarObjetivosColuna(colunaKey) {
            const res = await this.api(`/api/painel/kanban/coluna-objetivos/${colunaKey}`);
            this.objetivosColuna = res.ok ? await res.json() : [];
        },
```

- [ ] **Step 3: Renderizar o indicador de progresso**

Logo depois do bloco `{{-- Itens identificados nas imagens --}}` (linhas 343-365), adicione:

```html
                {{-- Progresso do checklist de objetivos da coluna --}}
                <template x-if="(objetivosColuna || []).length">
                    <div class="border-b">
                        <button @click="objetivosAberto = !objetivosAberto"
                                class="w-full flex items-center justify-between px-5 py-2 text-xs text-gray-500 hover:bg-gray-50 transition-colors">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span x-text="'Objetivos (' + (ticketAtivo.objetivos_cumpridos || []).length + '/' + objetivosColuna.length + ')'"></span>
                            </span>
                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="objetivosAberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <template x-if="objetivosAberto">
                            <div class="px-5 pb-3 space-y-1">
                                <template x-for="objetivo in objetivosColuna" :key="objetivo.id">
                                    <p class="text-xs text-gray-600 flex items-center gap-1.5">
                                        <span x-text="(ticketAtivo.objetivos_cumpridos || []).includes(objetivo.id) ? '✅' : '❌'"></span>
                                        <span x-text="objetivo.texto"></span>
                                    </p>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
```

- [ ] **Step 4: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/index.blade.php
git commit -m "feat: indicador de progresso do checklist de objetivos no card do Kanban"
```

---

## Depois de todas as tasks

- [ ] Rodar a suíte inteira uma última vez: `php artisan test` — esperado: só a falha pré-existente e conhecida de `ExampleTest`.
- [ ] `./deploy.sh` — migrations (incluindo o seed do Frete Rio) rodam automaticamente em produção.
- [ ] Testar manualmente em produção: abrir Configurações do Kanban, preencher a Base de Conhecimento geral, adicionar/editar objetivos numa coluna, confirmar que aparecem no card ao abrir um ticket daquela coluna.
- [ ] Atualizar `TAREFAS.md`: marcar a Frente 1 de `T-BASE-CONHECIMENTO-KANBAN` como concluída e deployada.
