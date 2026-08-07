# Handoff Humano ↔ Agente — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reassunção automática do agente de IA quando o humano assume uma conversa e some (sem responder nem o lead insistir), configurável por coluna do Kanban, notificando o humano via alerta interno quando isso acontece.

**Architecture:** Dois campos novos em `kanban_coluna_configs` (mesmo padrão de `auto_mover_ativo`/`auto_mover_segundos`), um comando agendado novo (`conversas:reassumir-agente`) que espelha `FollowupConversas` na direção oposta (`agente_responsavel = 'humano'` → volta pra `'bot'`), consumindo `AlertaInternoService::criar()` (já em produção) pra notificar o humano. A API de config já existe (`KanbanColunaConfigController`) — só ganha os dois campos novos.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (produção) / SQLite (testes), Alpine.js v3, Tailwind CSS.

## Global Constraints

- Timeout é por coluna do Kanban, não por workspace — decisão fechada com o Leonardo, mesmo padrão de `auto_mover_ativo`/`auto_mover_segundos` já em `kanban_coluna_configs`.
- Reassunção é silenciosa — nenhuma mensagem é enviada ao lead. Só `agente_responsavel` muda pra `'bot'` e um `AlertaInterno` é criado.
- A trava de fala do agente quando `agente_responsavel = 'humano'` (Regra 4) já está em produção (`UazapiWebhookController.php:394`) — este plano não mexe nela.
- `AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno` já existe (`app/Services/AlertaInternoService.php`) — usar exatamente essa assinatura, sem modificá-la.
- Especificação completa: `docs/superpowers/specs/2026-08-06-handoff-humano-agente-design.md`.

---

### Task 1: Migration e Model — campos de timeout de reassunção

**Files:**
- Create: `database/migrations/2026_08_06_000002_add_timeout_reassuncao_to_kanban_coluna_configs.php`
- Modify: `app/Models/KanbanColunaConfig.php`
- Test: `tests/Feature/KanbanColunaConfigFillableTest.php` (adicionar caso)

**Interfaces:**
- Produces: `KanbanColunaConfig::$timeout_reassuncao_ativo` (bool), `::$timeout_reassuncao_segundos` (int|null) — usados pelas Tasks 2 e 3.

- [ ] **Step 1: Criar a migration**

```php
<?php
// database/migrations/2026_08_06_000002_add_timeout_reassuncao_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Reassunção automática do agente quando o humano assume e some —
            // independente dos Estágios de silêncio e do Auto-mover (que agem
            // do lado do lead, não do atendente). Ver Regra 1/4/8 em
            // docs/superpowers/specs/2026-08-06-regras-atendimento-ia-humano-contexto.md.
            $table->boolean('timeout_reassuncao_ativo')->default(false)->after('auto_mover_mensagem');
            $table->unsignedInteger('timeout_reassuncao_segundos')->nullable()->after('timeout_reassuncao_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['timeout_reassuncao_ativo', 'timeout_reassuncao_segundos']);
        });
    }
};
```

- [ ] **Step 2: Adicionar os campos ao model**

Em `app/Models/KanbanColunaConfig.php`, no `$fillable`, adicione logo depois de `'auto_mover_mensagem',`:

```php
        'timeout_reassuncao_ativo',
        'timeout_reassuncao_segundos',
```

No `$casts`, adicione:

```php
        'timeout_reassuncao_ativo'   => 'boolean',
```

Arquivo final do `$casts` fica:

```php
    protected $casts = [
        'ia_ativo'                  => 'boolean',
        'transcricao_ativa'         => 'boolean',
        'auto_mover_ativo'          => 'boolean',
        'exclusao_definitiva_ativo' => 'boolean',
        'timeout_reassuncao_ativo'  => 'boolean',
    ];
```

- [ ] **Step 3: Escrever o teste**

Abra `tests/Feature/KanbanColunaConfigFillableTest.php`, veja o padrão dos testes existentes (cada campo tem seu próprio teste de mass-assignment) e adicione ao final da classe (antes do `}` final):

```php
    public function test_timeout_reassuncao_e_mass_assignable(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->assertTrue($config->fresh()->timeout_reassuncao_ativo);
        $this->assertSame(3600, $config->fresh()->timeout_reassuncao_segundos);
    }
```

Se o arquivo não seguir esse padrão exato de teste por campo, adicione a asserção equivalente ao teste geral de mass-assignment já existente, mantendo o estilo do arquivo.

- [ ] **Step 4: Rodar as migrations e o teste**

Run: `php artisan test --filter=KanbanColunaConfigFillableTest`
Expected: PASS — `RefreshDatabase` roda a migration nova automaticamente.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_06_000002_add_timeout_reassuncao_to_kanban_coluna_configs.php \
        app/Models/KanbanColunaConfig.php tests/Feature/KanbanColunaConfigFillableTest.php
git commit -m "feat: schema do timeout de reassunção do agente por coluna"
```

---

### Task 2: Comando `conversas:reassumir-agente`

**Files:**
- Create: `app/Console/Commands/ReassumirAgente.php`
- Modify: `routes/console.php` (adicionar o agendamento, logo após o bloco `conversas:followup`, ~linha 37)
- Test: Create `tests/Feature/ReassumirAgenteTest.php`

**Interfaces:**
- Consumes: `KanbanColunaConfig::$timeout_reassuncao_ativo`/`$timeout_reassuncao_segundos` (Task 1), `AlertaInternoService::criar()` (já existe).
- Produces: comando artisan `conversas:reassumir-agente {--dry-run}` — nenhuma outra task deste plano depende dele diretamente (a UI da Task 4 só edita a config que ele lê).

- [ ] **Step 1: Escrever os testes**

```php
<?php
// tests/Feature/ReassumirAgenteTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReassumirAgenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-06 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketAssumidoPeloHumano(int $minutosDeSilencio, string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcos']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Já te retorno!',
            'enviado_em' => now()->subMinutes($minutosDeSilencio),
        ]);

        return $ticket;
    }

    public function test_reassume_quando_silencio_ultrapassa_o_timeout_configurado(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(70);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600, // 1h
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('bot', $ticket->agente_responsavel);
        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'tipo' => 'reassuncao_automatica',
        ]);
    }

    public function test_nao_reassume_quando_silencio_ainda_nao_atingiu_o_timeout(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(30); // 30 min, limite é 60

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('humano', $ticket->agente_responsavel);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_nao_reassume_quando_toggle_esta_desativado(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => false, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_nao_reassume_quando_nao_ha_config_para_a_coluna(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120, 'coluna_sem_config');

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $ticketA = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticketA->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $ticketB = $this->criarTicketAssumidoPeloHumano(120);
        // tenant B não tem config nenhuma pra essa coluna

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('bot', $ticketA->fresh()->agente_responsavel);
        $this->assertSame('humano', $ticketB->fresh()->agente_responsavel);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente --dry-run')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_ticket_ja_reassumido_nao_gera_segundo_alerta_na_proxima_execucao(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(120);
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);
        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_ticket_sem_nenhuma_mensagem_nao_e_candidato(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        // Nenhuma Mensagem criada — o ticket não tem "última mensagem" nenhuma.

        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 60,
        ]);

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_reassuncao(): void
    {
        $ticket = $this->criarTicketAssumidoPeloHumano(70);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'timeout_reassuncao_ativo' => true, 'timeout_reassuncao_segundos' => 3600,
        ]);

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        $this->artisan('conversas:reassumir-agente')->assertExitCode(0);

        // A reassunção em si (o que mais importa pro lead não ficar esperando
        // pra sempre) não deve depender do alerta ter sido criado com sucesso.
        $this->assertSame('bot', $ticket->fresh()->agente_responsavel);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=ReassumirAgenteTest`
Expected: FAIL — comando `conversas:reassumir-agente` não existe.

- [ ] **Step 3: Criar o comando**

```php
<?php
// app/Console/Commands/ReassumirAgente.php
namespace App\Console\Commands;

use App\Models\KanbanColunaConfig;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReassumirAgente extends Command
{
    protected $signature = 'conversas:reassumir-agente
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Reassume automaticamente conversas onde o humano assumiu e ficou em silêncio além do timeout configurado por coluna (Regra 1)';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $reassumidos = 0;

        // Mesmo padrão de "última mensagem por ticket" já usado em
        // FollowupConversas — silêncio conta desde a última mensagem da
        // conversa, de qualquer remetente (humano ou lead).
        $candidatos = DB::table('tickets_atendimento as t')
            ->join(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em
                FROM mensagens m1
                INNER JOIN (
                    SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id
                ) m2 ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->where('t.agente_responsavel', 'humano')
            ->where('t.status', 'aberto')
            ->select('t.id', 't.tenant_id', 't.coluna_kanban', 'ultima.ultima_em')
            ->get();

        foreach ($candidatos as $row) {
            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $row->tenant_id)
                ->where('coluna_kanban', $row->coluna_kanban)
                ->first();

            if (! $config?->timeout_reassuncao_ativo || ! $config->timeout_reassuncao_segundos) {
                continue;
            }

            $silencioSegundos = now()->diffInSeconds(Carbon::parse($row->ultima_em), absolute: true);

            if ($silencioSegundos < $config->timeout_reassuncao_segundos) {
                continue;
            }

            $ticket = TicketAtendimento::withoutGlobalScopes()->with('contato')->find($row->id);
            if (! $ticket) {
                continue;
            }

            $this->line("  ↺ [reassumir] #{$ticket->id} — {$ticket->contato?->nome}");

            if ($dry) {
                continue;
            }

            try {
                $ticket->update(['agente_responsavel' => 'bot']);

                $horas = round($silencioSegundos / 3600, 1);
                $nomeContato = $ticket->contato?->nome ?? 'contato sem nome';
                $alertaService->criar(
                    $ticket->tenant_id,
                    'reassuncao_automatica',
                    "Agente reassumiu a conversa após {$horas}h de silêncio",
                    "O atendente não respondeu, e {$nomeContato} também não escreveu, por {$horas} horas — o agente de IA retomou o atendimento automaticamente.",
                    $ticket->id,
                );
                $reassumidos++;
            } catch (\Exception $e) {
                Log::warning('ReassumirAgente: erro ao reassumir', [
                    'ticket_id' => $row->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reassumidos: {$reassumidos}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Adicionar o agendamento**

Em `routes/console.php`, logo depois do bloco `Schedule::command('conversas:followup')` (linhas ~34-37), adicione:

```php
// A cada 5 min — Reassume conversas onde o humano assumiu e sumiu além do timeout
Schedule::command('conversas:reassumir-agente')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/reassumir-agente.log'));
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=ReassumirAgenteTest`
Expected: PASS (9 testes)

- [ ] **Step 6: Rodar toda a suíte pra checar regressão**

Run: `php artisan test`
Expected: PASS em tudo, exceto a falha pré-existente e conhecida de `ExampleTest`.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ReassumirAgente.php routes/console.php tests/Feature/ReassumirAgenteTest.php
git commit -m "feat: comando conversas:reassumir-agente (Regra 1)"
```

---

### Task 3: API — expor os campos de timeout na config da coluna

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php:20-39` (`show()`) e `:44-65` (`update()`)
- Test: `tests/Feature/KanbanColunaConfigAutoMoverTest.php` (adicionar caso de teste equivalente pro timeout — mesmo estilo já usado nesse arquivo pro `auto_mover_*`)

**Interfaces:**
- Consumes: `KanbanColunaConfig::$timeout_reassuncao_ativo`/`$timeout_reassuncao_segundos` (Task 1).
- Produces: `GET /api/painel/kanban/coluna-config/{coluna}` ganha `timeout_reassuncao_ativo`/`timeout_reassuncao_segundos` na resposta; `PUT` no mesmo endpoint aceita os dois campos — usado pela Task 4 (UI). Rota já existe, não precisa criar nada novo em `routes/web.php`.

- [ ] **Step 1: Escrever o teste**

Adicione ao final de `tests/Feature/KanbanColunaConfigAutoMoverTest.php` (antes do `}` final da classe), seguindo exatamente o padrão dos testes de `auto_mover_*` já no arquivo:

```php
    public function test_persiste_configuracao_de_timeout_de_reassuncao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento', [
            'timeout_reassuncao_ativo'    => true,
            'timeout_reassuncao_segundos' => 3600,
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'em_atendimento')->first();
        $this->assertTrue($config->timeout_reassuncao_ativo);
        $this->assertSame(3600, $config->timeout_reassuncao_segundos);
    }

    public function test_show_retorna_defaults_de_timeout_de_reassuncao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/em_atendimento');

        $response->assertOk();
        $response->assertJson([
            'timeout_reassuncao_ativo'    => false,
            'timeout_reassuncao_segundos' => 3600,
        ]);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=KanbanColunaConfigAutoMoverTest`
Expected: FAIL nos dois novos — a resposta não inclui os campos novos ainda.

- [ ] **Step 3: Atualizar `show()`**

Em `app/Http/Controllers/Painel/KanbanColunaConfigController.php`, no método `show()`, adicione logo depois da linha `'exclusao_definitiva_dias' => ...`:

```php
            'timeout_reassuncao_ativo'    => $config?->timeout_reassuncao_ativo    ?? false,
            'timeout_reassuncao_segundos' => $config?->timeout_reassuncao_segundos ?? 3600,
```

- [ ] **Step 4: Atualizar `update()`**

No mesmo arquivo, método `update()`, dentro do array de `$request->validate([...])`, adicione logo depois de `'exclusao_definitiva_dias' => 'sometimes|integer|min:1|max:3650',`:

```php
            'timeout_reassuncao_ativo'    => 'sometimes|boolean',
            'timeout_reassuncao_segundos' => 'sometimes|integer|min:60|max:604800',
```

Nenhuma outra mudança necessária no método — `$update = array_filter($validated, ...)` e `KanbanColunaConfig::updateOrCreate(...)` já lidam com qualquer campo novo automaticamente, já que os dois campos estão no `$fillable` (Task 1).

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=KanbanColunaConfigAutoMoverTest`
Expected: PASS (6 testes — 4 já existiam + 2 novos)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php tests/Feature/KanbanColunaConfigAutoMoverTest.php
git commit -m "feat: API expõe timeout de reassunção do agente na config da coluna"
```

---

### Task 4: UI — checkbox e campo de tempo na tela de config

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `GET/PUT /api/painel/kanban/coluna-config/{coluna}` (Task 3).

- [ ] **Step 1: Adicionar o estado Alpine**

Em `resources/views/kanban/config.blade.php`, logo depois do bloco de estado `// Transferência automática de coluna por silêncio` (linhas ~1377-1386, onde ficam `autoMoverAtivo: {}` etc.), adicione:

```php
        // Reassunção automática do agente quando o humano assume e some
        timeoutReassuncaoAtivo: {},
        timeoutReassuncaoDelay: {},
        timeoutReassuncaoDelayUnidade: {},
```

- [ ] **Step 2: Carregar os valores em `carregarIa()`**

No mesmo arquivo, dentro do método `carregarIa(key)`, logo depois do bloco que popula `this.exclusaoDefinitivaAtivo[key]`/`this.exclusaoDefinitivaDias[key]`/`this.exclusaoDefinitivaSalvo[key]` (linhas ~1837-1842), adicione:

```php
                this.timeoutReassuncaoAtivo[key] = json.timeout_reassuncao_ativo ?? false;
                const tr = this.segundosParaDisplay(json.timeout_reassuncao_segundos ?? 3600);
                this.timeoutReassuncaoDelay[key]        = tr.valor;
                this.timeoutReassuncaoDelayUnidade[key] = tr.unidade;
```

- [ ] **Step 3: Incluir no `salvarIa()`**

No método `salvarIa(key)`, dentro do objeto passado pro `this.api('/api/painel/kanban/coluna-config/${key}', 'PUT', {...})` (linhas ~1882-1897), adicione logo depois de `exclusao_definitiva_dias: ...`:

```php
                timeout_reassuncao_ativo:    this.timeoutReassuncaoAtivo[key] ?? false,
                timeout_reassuncao_segundos: this.delayParaSegundos(this.timeoutReassuncaoDelay[key] ?? 1, this.timeoutReassuncaoDelayUnidade[key] || 'hora'),
```

- [ ] **Step 4: Adicionar a seção no template**

No mesmo arquivo, logo depois do bloco `<div class="mt-2 p-3 bg-red-50 border border-red-200 rounded-xl">` que fecha a explicação do "Auto-mover" (linhas ~914-919, procure pelo texto "Como configurar" dentro da caixa vermelha do auto-mover — é o parágrafo que termina com "Roda junto com os Estágios de silêncio"), adicione, ainda dentro do mesmo bloco pai de "Agente de IA" da coluna:

```html
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <label class="flex items-center gap-2 mb-2 cursor-pointer">
                                <input type="checkbox"
                                       :checked="timeoutReassuncaoAtivo[col.key]"
                                       @change="timeoutReassuncaoAtivo[col.key] = $event.target.checked; iaAlterado[col.key] = true"
                                       class="w-3.5 h-3.5 accent-purple-600">
                                <span class="text-xs font-semibold text-gray-500">Reassumir automaticamente após silêncio do atendente</span>
                            </label>

                            <template x-if="timeoutReassuncaoAtivo[col.key]">
                                <div class="flex flex-wrap items-center gap-2 mb-2">
                                    <span class="text-xs text-gray-500">Depois de</span>
                                    <input type="number" min="1"
                                           :value="timeoutReassuncaoDelay[col.key] ?? 1"
                                           @input="timeoutReassuncaoDelay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                           class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                    <select :value="timeoutReassuncaoDelayUnidade[col.key] || 'hora'"
                                            @change="timeoutReassuncaoDelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                            class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                        <option value="seg">seg</option>
                                        <option value="min">min</option>
                                        <option value="hora">hora</option>
                                    </select>
                                    <span class="text-xs text-gray-500">de silêncio, o agente retoma sozinho</span>
                                </div>
                            </template>
                            <div class="mt-2 p-3 bg-purple-50 border border-purple-200 rounded-xl">
                                <p class="text-xs font-semibold text-purple-800 mb-1">Como configurar</p>
                                <p class="text-xs text-purple-700 leading-relaxed">
                                    Quando um atendente assume a conversa (o agente para de falar automaticamente), esse tempo conta o silêncio desde a última mensagem da conversa — sua ou do lead. Se ninguém escrever nada até esse limite, o agente de IA retoma o atendimento sozinho, sem mandar nenhuma mensagem pro lead — só volta a responder normalmente na próxima vez que ele escrever. Você recebe um alerta interno (ícone ao lado do sino, na barra de topo) toda vez que isso acontece. Roda a cada 5 minutos, sem restrição de horário.
                                </p>
                            </div>
                        </div>
```

- [ ] **Step 5: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 6: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: tela de config do timeout de reassunção do agente por coluna"
```

---

## Depois de todas as tasks

- [ ] Rodar a suíte inteira uma última vez: `php artisan test` — esperado: só a falha pré-existente e conhecida de `ExampleTest`.
- [ ] `./deploy.sh` — migration roda automaticamente em produção.
- [ ] Testar manualmente em produção: assumir um ticket como humano, configurar um timeout curto (ex: 2 min) na coluna dele, esperar, confirmar que o `agente_responsavel` volta pra `bot` e que um alerta aparece no ícone da barra de topo.
- [ ] Atualizar `TAREFAS.md`: marcar o Bloco 2 de `T-REGRAS-ATENDIMENTO-IA-HUMANO` como concluído e deployado.
