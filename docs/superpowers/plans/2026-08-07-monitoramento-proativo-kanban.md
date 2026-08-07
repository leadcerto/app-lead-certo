# Monitoramento Proativo de Kanban — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar as 13 regras (Bloco 4 de 4) — comando periódico que alerta
tickets travados além do tempo máximo configurado por coluna (Regra 3/12), e
detecção de migração atípica de coluna (movida manualmente por humano e/ou
pulando etapas) com alerta interno (Regra 13).

**Architecture:** Duas peças independentes que convergem no
`AlertaInternoService` já existente (Bloco 1). A Regra 3 é periódica — um
comando novo (`kanban:monitorar`, a cada 15min) varre `kanban_coluna_historico`
comparando com o limiar configurado por coluna. A Regra 13 é orientada a
evento — vive no hook `TicketAtendimento::updated()` já existente (único
ponto de convergência de toda troca de coluna hoje), que passa a gravar quem
moveu o ticket e, se for humano e/ou um salto de etapas, dispara o alerta na
hora.

**Tech Stack:** Laravel 13 / PHP 8.4, MySQL 8 (prod) / SQLite (testes),
Alpine.js v3 + Tailwind (config da coluna).

## Global Constraints

- `alertas_internos.tipo` é string livre (sem enum) — os 2 tipos novos deste
  bloco (`ticket_travado`, `migracao_atipica`) não exigem migration própria.
- Todo alerta é criado via `AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno`
  (`app/Services/AlertaInternoService.php`) — nunca criar `AlertaInterno::create()` direto.
- Toda query cross-tenant em comando/serviço usa `withoutGlobalScopes()`
  explicitamente (models têm `TenantScope` global).
- Testes usam `RefreshDatabase` + `Tenant::factory()->create()` (semeia
  Kanban "Vendas" com 8 colunas padrão via `TenantSetupService`/`TenantFactory::colunasPadrao()`:
  `lead_novo`(1) → `em_atendimento`(2) → `aguardando_orcamento`(3) →
  `aguardando_lead`(4) → `pagamento`(5) → `servico_agendado`(6) →
  `encerrado`(7) → `outros`(8) — os números são a `ordem`). Use essas chaves
  nos testes em vez de criar colunas customizadas, a não ser que o teste peça
  explicitamente o contrário.
- Boa parte da suíte existente cria tickets com `coluna_kanban` sendo uma
  string solta, sem nenhuma linha correspondente em `kanban_colunas` para o
  tenant (ex: `KanbanColunaHistoricoTest`, `ReassumirAgenteTest`). Qualquer
  lógica nova que dependa de `ordem`/config de coluna precisa tratar "coluna
  não cadastrada" sem lançar exceção — ver Tasks 5 e 6.
- `TicketAtendimento::$fillable` já tem um campo `origem` (origem do LEAD —
  ex. anúncio, indicação — não relacionado a este bloco). O campo `origem`
  criado neste bloco é em `kanban_coluna_historico`, uma tabela e um model
  diferentes (`KanbanColunaHistorico`). Não confundir os dois em nenhuma task.

---

### Task 1: Config de tempo máximo por coluna (Regra 12)

**Files:**
- Create: `database/migrations/2026_08_07_000004_add_tempo_maximo_permanencia_minutos_to_kanban_coluna_configs.php`
- Modify: `app/Models/KanbanColunaConfig.php`
- Modify: `tests/Feature/KanbanColunaConfigFillableTest.php`

**Interfaces:**
- Produces: coluna `kanban_coluna_configs.tempo_maximo_permanencia_minutos`
  (integer, nullable). `null` = coluna não monitorada. Sem campo `_ativo`
  separado — é intencional (ver nota abaixo), diferente do par
  `timeout_reassuncao_ativo`/`timeout_reassuncao_segundos` do Bloco 2. Task 3
  (comando `kanban:monitorar`) e Task 7 (UI) consomem esse campo por esse
  nome exato.

- [ ] **Step 1: Escrever a migration**

```php
<?php
// database/migrations/2026_08_07_000004_add_tempo_maximo_permanencia_minutos_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Regra 12 — tempo máximo (em minutos) que um ticket pode ficar
            // nessa coluna antes do comando kanban:monitorar (Regra 3)
            // alertar que travou. Null = coluna não monitorada. Sem valor
            // default de fallback (diferente de timeout_reassuncao_segundos,
            // Bloco 2): não existe um "tempo esperado" genérico que sirva
            // pra qualquer coluna, então null tem que significar "desligado"
            // de verdade, sem um número escondido assumindo o controle.
            $table->unsignedInteger('tempo_maximo_permanencia_minutos')->nullable()->after('aguardando_orientacao_mensagem');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('tempo_maximo_permanencia_minutos');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: migration `2026_08_07_000004_...` aplicada sem erro.

- [ ] **Step 3: Adicionar o campo ao `$fillable` do model**

Em `app/Models/KanbanColunaConfig.php`, adicionar `'tempo_maximo_permanencia_minutos',`
como último item do array `$fillable` (depois de `'aguardando_orientacao_mensagem',`).
Não precisa de entrada em `$casts` — nenhum dos outros campos inteiros
análogos (`sdr_delay_segundos`, `timeout_reassuncao_segundos`) tem cast
próprio neste model.

- [ ] **Step 4: Escrever o teste (mass assignment)**

Adicionar ao final da classe em `tests/Feature/KanbanColunaConfigFillableTest.php`
(mesmo padrão de `test_timeout_reassuncao_e_mass_assignable`, já presente no
arquivo):

```php
    public function test_tempo_maximo_permanencia_minutos_e_mass_assignable(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 120,
        ]);

        $this->assertSame(120, $config->fresh()->tempo_maximo_permanencia_minutos);
    }

    public function test_tempo_maximo_permanencia_minutos_e_nulo_por_padrao(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
        ]);

        $this->assertNull($config->fresh()->tempo_maximo_permanencia_minutos);
    }
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=KanbanColunaConfigFillableTest`
Expected: PASS (6 testes — 4 já existentes + 2 novos).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_07_000004_add_tempo_maximo_permanencia_minutos_to_kanban_coluna_configs.php app/Models/KanbanColunaConfig.php tests/Feature/KanbanColunaConfigFillableTest.php
git commit -m "feat: campo tempo_maximo_permanencia_minutos por coluna (Regra 12)"
```

---

### Task 2: Origem e dedup de alerta no histórico de coluna

**Files:**
- Create: `database/migrations/2026_08_07_000005_add_origem_and_alertado_em_to_kanban_coluna_historico.php`
- Modify: `app/Models/KanbanColunaHistorico.php`
- Modify: `tests/Feature/KanbanColunaHistoricoTest.php`

**Interfaces:**
- Produces: colunas `kanban_coluna_historico.origem` (string, nullable,
  valores usados a partir daqui: `'ia'` | `'humano'`) e `.alertado_em`
  (timestamp, nullable). Task 4 escreve `origem` a cada linha nova. Task 3
  (comando `kanban:monitorar`) lê e escreve `alertado_em`.

- [ ] **Step 1: Escrever a migration**

```php
<?php
// database/migrations/2026_08_07_000005_add_origem_and_alertado_em_to_kanban_coluna_historico.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_historico', function (Blueprint $table) {
            // Regra 13 (Bloco 4) — quem causou essa entrada na coluna. Só
            // marcado 'humano' pelos dois endpoints de movimentação manual
            // (KanbanController::mover/moverParaOutros, Task 6); todo o
            // resto (token de coluna da IA, followup automático, webhook,
            // botões) assume 'ia'. Linhas criadas antes deste bloco ficam
            // nulas — sem backfill, só passa a valer daqui pra frente.
            $table->string('origem', 10)->nullable()->after('coluna_anterior');
            // Regra 3 (Bloco 4) — marca que o comando kanban:monitorar já
            // alertou por essa permanência específica na coluna (dedup:
            // reseta sozinho quando uma nova linha é criada pra esse ticket).
            $table->timestamp('alertado_em')->nullable()->after('entrou_em');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_historico', function (Blueprint $table) {
            $table->dropColumn(['origem', 'alertado_em']);
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: migration `2026_08_07_000005_...` aplicada sem erro.

- [ ] **Step 3: Atualizar o model**

Em `app/Models/KanbanColunaHistorico.php`:
- Adicionar `'origem',` e `'alertado_em',` ao array `$fillable`.
- Adicionar `'alertado_em' => 'datetime'` ao array retornado por `casts()`
  (já existe `'entrou_em' => 'datetime'` lá — ficam os dois juntos).

O arquivo final do método `casts()` fica:

```php
    protected function casts(): array
    {
        return [
            'entrou_em'    => 'datetime',
            'alertado_em'  => 'datetime',
        ];
    }
```

- [ ] **Step 4: Escrever o teste (fillable/cast)**

Adicionar ao final da classe em `tests/Feature/KanbanColunaHistoricoTest.php`:

```php
    public function test_origem_e_alertado_em_sao_preenchiveis(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $registro = KanbanColunaHistorico::where('ticket_id', $ticket->id)->firstOrFail();
        $registro->update(['origem' => 'humano', 'alertado_em' => now()]);

        $registro->refresh();
        $this->assertSame('humano', $registro->origem);
        $this->assertNotNull($registro->alertado_em);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $registro->alertado_em);
    }
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=KanbanColunaHistoricoTest`
Expected: PASS (4 testes — 3 já existentes + 1 novo).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_07_000005_add_origem_and_alertado_em_to_kanban_coluna_historico.php app/Models/KanbanColunaHistorico.php tests/Feature/KanbanColunaHistoricoTest.php
git commit -m "feat: campos origem e alertado_em em kanban_coluna_historico (Regra 3/13)"
```

---

### Task 3: Comando `kanban:monitorar` (Regra 3)

**Depends on:** Task 1 (`kanban_coluna_configs.tempo_maximo_permanencia_minutos`),
Task 2 (`kanban_coluna_historico.alertado_em`).

**Files:**
- Create: `app/Console/Commands/MonitorarKanban.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/MonitorarKanbanTest.php`

**Interfaces:**
- Consumes: `AlertaInternoService::criar()` (assinatura no topo deste plano).
- Produces: comando artisan `kanban:monitorar` (com `--dry-run`), tipo de
  alerta `'ticket_travado'`.

- [ ] **Step 1: Escrever o comando**

```php
<?php
// app/Console/Commands/MonitorarKanban.php
namespace App\Console\Commands;

use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorarKanban extends Command
{
    protected $signature = 'kanban:monitorar
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Alerta tickets travados além do tempo máximo configurado por coluna (Regra 3/12)';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $travados = 0;

        // Mesmo padrão de "última linha por ticket" já usado em
        // ReassumirAgente (lá era "última mensagem"; aqui é "última entrada
        // de coluna") — junta com a config da coluna atual, ignora colunas
        // sem tempo_maximo_permanencia_minutos configurado, e só considera
        // tickets ainda abertos (um ticket encerrado parado na coluna de
        // Encerramento é esperado, não é "travado").
        $candidatos = DB::table('kanban_coluna_historico as h')
            ->join(DB::raw('(
                SELECT ticket_id, MAX(id) as max_id FROM kanban_coluna_historico GROUP BY ticket_id
            ) as ultimo'), function ($join) {
                $join->on('h.ticket_id', '=', 'ultimo.ticket_id')
                     ->on('h.id', '=', 'ultimo.max_id');
            })
            ->whereNull('h.alertado_em')
            ->join('kanban_coluna_configs as c', function ($join) {
                $join->on('c.tenant_id', '=', 'h.tenant_id')
                     ->on('c.coluna_kanban', '=', 'h.coluna');
            })
            ->whereNotNull('c.tempo_maximo_permanencia_minutos')
            ->join('tickets_atendimento as t', 't.id', '=', 'h.ticket_id')
            ->where('t.status', 'aberto')
            ->select('h.id as historico_id', 'h.tenant_id', 'h.ticket_id', 'h.coluna', 'h.entrou_em', 'c.tempo_maximo_permanencia_minutos')
            ->get();

        foreach ($candidatos as $row) {
            // absolute: true — mesmo padrão do ReassumirAgente (Bloco 2), evita
            // um valor negativo se o relógio/timezone divergir por algum motivo.
            $minutosParado = now()->diffInMinutes(Carbon::parse($row->entrou_em), absolute: true);

            if ($minutosParado < $row->tempo_maximo_permanencia_minutos) {
                continue;
            }

            // Reconfere o ticket antes de agir — mesmo padrão defensivo do
            // ReassumirAgente (achado 3 da revisão final do Bloco 2): entre a
            // query e agora, o ticket pode ter saído dessa coluna.
            $ticket = TicketAtendimento::withoutGlobalScopes()->with('contato')->find($row->ticket_id);
            if (! $ticket || $ticket->coluna_kanban !== $row->coluna) {
                continue;
            }

            $nomeContato = $ticket->contato?->nome ?? 'contato sem nome';
            $this->line("  ⏱ [travado] #{$ticket->id} — {$nomeContato} — {$row->coluna}");

            if ($dry) {
                continue;
            }

            try {
                $horas = round($minutosParado / 60, 1);
                $alertaService->criar(
                    $ticket->tenant_id,
                    'ticket_travado',
                    "{$nomeContato} travado há {$horas}h na coluna",
                    "O ticket está na coluna \"{$row->coluna}\" há {$horas} horas, além do tempo máximo configurado ({$row->tempo_maximo_permanencia_minutos} min).",
                    $ticket->id,
                );

                // Só marca alertado_em se o alerta foi criado com sucesso —
                // diferente do padrão do ReassumirAgente (lá a ação principal
                // era independente do alerta). Aqui o alerta É a ação
                // principal: se ele falhar, não faz sentido suprimir a
                // tentativa seguinte — deixa tentar de novo daqui a 15min.
                DB::table('kanban_coluna_historico')->where('id', $row->historico_id)->update(['alertado_em' => now()]);

                $travados++;
            } catch (\Exception $e) {
                Log::warning('MonitorarKanban: erro ao alertar ticket travado', [
                    'ticket_id' => $row->ticket_id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Travados alertados: {$travados}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrar no agendador**

Em `routes/console.php`, adicionar depois do bloco de `conversas:reassumir-agente`
(antes do bloco `contatos:enriquecer-conversas`):

```php
// A cada 15 min — Alerta tickets travados além do tempo máximo por coluna (Regra 3/12)
Schedule::command('kanban:monitorar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/kanban-monitorar.log'));
```

- [ ] **Step 3: Escrever os testes**

```php
<?php
// tests/Feature/MonitorarKanbanTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonitorarKanbanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicket(Tenant $tenant, string $coluna = 'aguardando_orcamento'): TicketAtendimento
    {
        $contato = Contato::factory()->create(['nome' => 'Marcos']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_alerta_ticket_travado_alem_do_tempo_maximo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00')); // 65 min depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
        ]);
    }

    public function test_nao_alerta_antes_do_tempo_maximo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 10:30:00')); // 30 min depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_coluna_sem_tempo_maximo_configurado_nunca_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        // Sem KanbanColunaConfig nenhuma pra essa coluna.

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00')); // 3 dias depois

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_ticket_encerrado_nao_e_candidato(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        $ticket->update(['status' => 'encerrado']);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_nao_repete_alerta_na_proxima_execucao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));
        $this->artisan('kanban:monitorar')->assertExitCode(0);
        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_ticket_sai_e_volta_pra_mesma_coluna_pode_alertar_de_novo(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));
        $this->artisan('kanban:monitorar')->assertExitCode(0);
        $this->assertSame(1, AlertaInterno::where('ticket_id', $ticket->id)->count());

        // Sai e volta pra mesma coluna — nova linha de histórico, alertado_em nulo de novo.
        $ticket->update(['coluna_kanban' => 'em_atendimento']);
        $ticket->update(['coluna_kanban' => 'aguardando_orcamento']);

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:10:00')); // mais 65min depois de reentrar

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertSame(2, AlertaInterno::where('ticket_id', $ticket->id)->count());
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar --dry-run')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $ticketA = $this->criarTicket($tenantA);
        KanbanColunaConfig::create([
            'tenant_id' => $tenantA->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        $tenantB = Tenant::factory()->create();
        $ticketB = $this->criarTicket($tenantB);
        // tenant B não tem config nenhuma pra essa coluna

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseHas('alertas_internos', ['ticket_id' => $ticketA->id]);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticketB->id]);
    }

    public function test_falha_ao_criar_alerta_nao_marca_alertado_em_tenta_de_novo_depois(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'tempo_maximo_permanencia_minutos' => 60,
        ]);

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:05:00'));

        $this->artisan('kanban:monitorar')->assertExitCode(0);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
        $this->assertNull(
            \App\Models\KanbanColunaHistorico::where('ticket_id', $ticket->id)->latest('id')->value('alertado_em')
        );
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=MonitorarKanbanTest`
Expected: PASS (9 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/MonitorarKanban.php routes/console.php tests/Feature/MonitorarKanbanTest.php
git commit -m "feat: comando kanban:monitorar — alerta tickets travados a cada 15min (Regra 3)"
```

---

### Task 4: Registrar quem moveu o ticket (origem)

**Depends on:** Task 2 (`kanban_coluna_historico.origem`).

**Files:**
- Modify: `app/Models/KanbanColuna.php`
- Modify: `app/Models/TicketAtendimento.php`
- Create: `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`

**Interfaces:**
- Produces: `KanbanColuna::ordemDe(int $tenantId, string $chave): ?int`
  (Task 5 usa pra calcular salto). `TicketAtendimento::$origemMudancaColuna`
  (propriedade pública, não-persistida, não é coluna de banco nem
  `$fillable`) — Task 6 seta essa propriedade antes de `->update()`.
- Consumes: nenhuma interface nova de tasks anteriores além do schema da
  Task 2.

- [ ] **Step 1: Adicionar `KanbanColuna::ordemDe()`**

Em `app/Models/KanbanColuna.php`, adicionar logo depois do método `papelDe()`
existente (mesmo padrão exato — `firstWhere` + null-safe):

```php
    public static function ordemDe(int $tenantId, string $chave): ?int
    {
        return static::doTenant($tenantId)->firstWhere('chave', $chave)?->ordem;
    }
```

- [ ] **Step 2: Adicionar a propriedade transiente e ler no hook**

Em `app/Models/TicketAtendimento.php`, adicionar a propriedade pública logo
depois da declaração `protected $table = 'tickets_atendimento';`:

```php
    /**
     * NÃO-PERSISTIDA — quem está causando a mudança de coluna neste update
     * específico. Setada só pelos dois endpoints de movimentação manual
     * (KanbanController::mover/moverParaOutros, Task 6) antes de chamar
     * ->update(). Lida pelo hook updated() abaixo pra gravar 'origem' no
     * histórico (Bloco 4, Regra 13) — sem isso o hook não teria como saber
     * quem iniciou a mudança, já que dezenas de pontos diferentes do código
     * chamam ->update(['coluna_kanban' => ...]).
     */
    public ?string $origemMudancaColuna = null;
```

Depois, no hook `static::updated()` já existente, alterar o bloco que cria
o `KanbanColunaHistorico` pra capturar `coluna_anterior`/`origem` em
variáveis (Task 5 vai reusar essas variáveis) e gravar `origem`:

```php
        static::updated(function (TicketAtendimento $ticket) {
            if ($ticket->wasChanged('coluna_kanban')) {
                $colunaAnterior = $ticket->getOriginal('coluna_kanban');
                $origem = $ticket->origemMudancaColuna ?? 'ia';

                KanbanColunaHistorico::create([
                    'tenant_id'       => $ticket->tenant_id,
                    'ticket_id'       => $ticket->id,
                    'coluna'          => $ticket->coluna_kanban,
                    'coluna_anterior' => $colunaAnterior,
                    'entrou_em'       => now(),
                    'origem'          => $origem,
                ]);
            }
        });
```

Isso substitui o corpo atual do `static::updated()` (o `if ($ticket->wasChanged('coluna_kanban')) { ... }`
inteiro) — as outras closures do `booted()` (`static::created()`, `static::updating()`)
ficam exatamente como estão.

- [ ] **Step 3: Escrever os testes**

```php
<?php
// tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoOrigemMudancaColunaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna = 'lead_novo'): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mudanca_de_coluna_sem_marcar_origem_grava_ia_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'ia',
        ]);
    }

    public function test_mudanca_de_coluna_com_propriedade_marcada_grava_humano(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'humano',
        ]);
    }

    public function test_criacao_inicial_do_ticket_nao_grava_origem(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $this->assertNull(
            KanbanColunaHistorico::where('ticket_id', $ticket->id)->whereNull('coluna_anterior')->value('origem')
        );
    }

    public function test_propriedade_transiente_nao_e_persistida_no_proprio_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertArrayNotHasKey('origem_mudanca_coluna', $ticket->fresh()->getAttributes());
        $this->assertArrayNotHasKey('origemMudancaColuna', $ticket->fresh()->getAttributes());
    }

    public function test_ordem_de_retorna_a_ordem_correta_e_null_se_a_coluna_nao_existir(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(1, \App\Models\KanbanColuna::ordemDe($tenant->id, 'lead_novo'));
        $this->assertSame(5, \App\Models\KanbanColuna::ordemDe($tenant->id, 'pagamento'));
        $this->assertNull(\App\Models\KanbanColuna::ordemDe($tenant->id, 'nao_existe'));
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoOrigemMudancaColunaTest`
Expected: PASS (5 testes).

Run também a suíte que já existia sobre o mesmo hook, pra garantir que nada
quebrou:
Run: `php artisan test --filter=KanbanColunaHistoricoTest`
Expected: PASS (4 testes, sem regressão).

- [ ] **Step 5: Commit**

```bash
git add app/Models/KanbanColuna.php app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
git commit -m "feat: registra quem moveu o ticket (origem) no histórico de coluna (Regra 13)"
```

---

### Task 5: Alerta de migração atípica (Regra 13)

**Depends on:** Task 4 (`KanbanColuna::ordemDe()`, `$origemMudancaColuna`,
variáveis `$colunaAnterior`/`$origem` já extraídas no hook).

**Files:**
- Modify: `app/Models/TicketAtendimento.php`
- Modify: `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`

**Interfaces:**
- Consumes: `AlertaInternoService::criar()`, `KanbanColuna::ordemDe()` (Task 4).
- Produces: tipo de alerta `'migracao_atipica'`.

- [ ] **Step 1: Adicionar o método privado de decisão**

Em `app/Models/TicketAtendimento.php`, adicionar um método privado estático
logo depois do fechamento de `protected static function booted(): void { ... }`:

```php
    /**
     * Regra 13 (Bloco 4) — migração atípica: movida manualmente por um
     * humano e/ou pulando mais de uma posição na ordem das colunas. Só
     * alerta, nunca bloqueia a movimentação (decisão de produto fechada —
     * evita travar um caso legítimo, ex. pular direto pra Encerrado, por
     * engano). Se os dois motivos se aplicarem ao mesmo evento, gera um
     * alerta só, não dois.
     *
     * Ordem desconhecida pra qualquer um dos dois lados (coluna sem registro
     * em kanban_colunas pro tenant — comum em testes e em tenants com
     * chaves de coluna sem cadastro formal) significa que o salto não pode
     * ser calculado: assume que não houve salto, não bloqueia nem falso-alarma.
     */
    private static function alertarSeMigracaoAtipica(self $ticket, ?string $colunaAnterior, string $origem): void
    {
        if ($colunaAnterior === null) {
            return; // entrada inicial (criação do ticket), não é uma migração
        }

        $ordemAntes  = \App\Models\KanbanColuna::ordemDe($ticket->tenant_id, $colunaAnterior);
        $ordemDepois = \App\Models\KanbanColuna::ordemDe($ticket->tenant_id, $ticket->coluna_kanban);
        $pulou = $ordemAntes !== null && $ordemDepois !== null && abs($ordemDepois - $ordemAntes) > 1;

        if ($origem !== 'humano' && ! $pulou) {
            return;
        }

        $motivos = [];
        if ($origem === 'humano') {
            $motivos[] = 'movida manualmente por um atendente';
        }
        if ($pulou) {
            $motivos[] = "pulou de \"{$colunaAnterior}\" direto pra \"{$ticket->coluna_kanban}\"";
        }

        try {
            app(\App\Services\AlertaInternoService::class)->criar(
                $ticket->tenant_id,
                'migracao_atipica',
                'Migração atípica de coluna',
                'O ticket foi ' . implode(' e ', $motivos) . '.',
                $ticket->id,
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('TicketAtendimento: erro ao criar alerta de migração atípica', [
                'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 2: Chamar o método no hook**

No `static::updated()` (alterado na Task 4), adicionar a chamada logo depois
de criar o `KanbanColunaHistorico`:

```php
        static::updated(function (TicketAtendimento $ticket) {
            if ($ticket->wasChanged('coluna_kanban')) {
                $colunaAnterior = $ticket->getOriginal('coluna_kanban');
                $origem = $ticket->origemMudancaColuna ?? 'ia';

                KanbanColunaHistorico::create([
                    'tenant_id'       => $ticket->tenant_id,
                    'ticket_id'       => $ticket->id,
                    'coluna'          => $ticket->coluna_kanban,
                    'coluna_anterior' => $colunaAnterior,
                    'entrou_em'       => now(),
                    'origem'          => $origem,
                ]);

                static::alertarSeMigracaoAtipica($ticket, $colunaAnterior, $origem);
            }
        });
```

- [ ] **Step 3: Escrever os testes**

Adicionar ao final da classe em `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`
(mesmo arquivo da Task 4 — usa o helper `criarTicket()` já definido lá):

```php
    public function test_movimento_adjacente_pela_ia_nao_gera_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        $ticket->update(['coluna_kanban' => 'em_atendimento']); // ordem 2, adjacente

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_movimento_manual_adjacente_gera_alerta_migracao_atipica(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']); // adjacente, mas manual

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }

    public function test_salto_de_mais_de_uma_coluna_gera_alerta_mesmo_pela_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        $ticket->update(['coluna_kanban' => 'pagamento']); // ordem 5, pula 3 colunas

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
        // A movimentação em si não é bloqueada.
        $this->assertSame('pagamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_movimento_manual_com_salto_gera_apenas_um_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'pagamento']); // manual + salto

        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'migracao_atipica')->count()
        );
    }

    public function test_coluna_sem_registro_em_kanban_colunas_nao_calcula_salto_nem_falha(): void
    {
        $tenant = Tenant::factory()->create();
        // Nenhuma KanbanColuna cadastrada com essas chaves — mesmo padrão de
        // boa parte da suíte existente (coluna_kanban é só uma string solta).
        $ticket = $this->criarTicket($tenant, 'coluna_solta_a');

        $ticket->update(['coluna_kanban' => 'coluna_solta_b']);

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id]);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_migracao(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');

        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        $ticket->origemMudancaColuna = 'humano';
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoOrigemMudancaColunaTest`
Expected: PASS (11 testes — 5 da Task 4 + 6 novos).

Run a suíte inteira de Kanban pra garantir que nada quebrou (esse hook é
usado por muita coisa):
Run: `php artisan test --filter=Kanban`
Expected: PASS, sem regressão nos testes já existentes.

- [ ] **Step 5: Commit**

```bash
git add app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
git commit -m "feat: alerta de migração atípica de coluna — manual e/ou salto (Regra 13)"
```

---

### Task 6: Marcar origem humana nos endpoints de movimentação manual

**Depends on:** Task 4 (propriedade `$origemMudancaColuna`).

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php`
- Modify: `tests/Feature/KanbanControllerMoverTest.php`
- Modify: `tests/Feature/KanbanControllerMoverParaOutrosTest.php`

**Interfaces:** nenhuma nova — só consome `$origemMudancaColuna` (Task 4).

- [ ] **Step 1: Marcar em `mover()`**

Em `app/Http/Controllers/Painel/KanbanController.php`, no método `mover()`,
adicionar a linha `$model->origemMudancaColuna = 'humano';` imediatamente
antes de `$model->update($updates);`:

```php
        $updates = ['coluna_kanban' => $colunaDepois];

        // Reabre o status se estava encerrado e foi movido manualmente pra fora
        // do Encerrado — sem isso a coluna muda mas o ticket continua com
        // status 'encerrado' por baixo, escondendo a caixa de mensagem inteira.
        if (KanbanColuna::papelDe($tenantId, $colunaAntes) === PapelColunaKanban::Encerramento
            && KanbanColuna::papelDe($tenantId, $colunaDepois) !== PapelColunaKanban::Encerramento) {
            $updates['status'] = 'aberto';
        }

        // Regra 13 (Bloco 4) — este é um dos dois únicos endpoints de
        // movimentação manual do sistema (drag-and-drop do board).
        $model->origemMudancaColuna = 'humano';
        $model->update($updates);
```

- [ ] **Step 2: Marcar em `moverParaOutros()`**

No método `moverParaOutros()`, mesma alteração antes de `$model->update([...]);`:

```php
        $model = TicketAtendimento::findOrFail($ticket);

        // Regra 13 (Bloco 4) — segundo dos dois endpoints de movimentação manual.
        $model->origemMudancaColuna = 'humano';
        $model->update([
            'coluna_kanban'      => $colunaOutros,
            'agente_responsavel' => 'humano',
            'vendedor_id'        => $request->user()->id,
        ]);
```

- [ ] **Step 3: Escrever os testes**

Adicionar ao final da classe em `tests/Feature/KanbanControllerMoverTest.php`:

```php
    public function test_mover_manualmente_grava_origem_humano_no_historico(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'em_atendimento',
        ])->assertOk();

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'humano',
        ]);
    }
```

Adicionar ao final da classe em `tests/Feature/KanbanControllerMoverParaOutrosTest.php`:

```php
    public function test_mover_para_outros_grava_origem_humano_no_historico(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/outros")->assertOk();

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'outros', 'origem' => 'humano',
        ]);
    }
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=KanbanControllerMoverTest`
Expected: PASS (7 testes — 6 já existentes + 1 novo).

Run: `php artisan test --filter=KanbanControllerMoverParaOutrosTest`
Expected: PASS (4 testes — 3 já existentes + 1 novo).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanControllerMoverTest.php tests/Feature/KanbanControllerMoverParaOutrosTest.php
git commit -m "feat: marca origem humana ao mover ticket manualmente (Regra 13)"
```

---

### Task 7: UI — config de tempo máximo por coluna

**Depends on:** Task 1 (`kanban_coluna_configs.tempo_maximo_permanencia_minutos`).

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php`
- Modify: `resources/views/kanban/config.blade.php`
- Create: `tests/Feature/KanbanColunaConfigTempoMaximoPermanenciaTest.php`

**Interfaces:** nenhuma nova — expõe o campo da Task 1 via API existente.

- [ ] **Step 1: Expor o campo no `show()`**

Em `app/Http/Controllers/Painel/KanbanColunaConfigController.php`, no método
`show()`, adicionar como última linha do array retornado (depois de
`'aguardando_orientacao_mensagem'`):

```php
            'tempo_maximo_permanencia_minutos' => $config?->tempo_maximo_permanencia_minutos ?? null,
```

- [ ] **Step 2: Validar e persistir no `update()`**

No método `update()`, adicionar à regra de validação (depois de
`'aguardando_orientacao_mensagem'`):

```php
            'tempo_maximo_permanencia_minutos' => 'sometimes|nullable|integer|min:1|max:43200',
```

(43200 minutos = 30 dias — teto generoso, mesma ordem de grandeza do maior
`_segundos` já existente no arquivo, `exclusao_definitiva_dias` com max
3650 dias, só que aqui em minutos e pra um propósito bem mais curto.)

Nenhuma outra mudança no `update()` — o campo já passa pelo mesmo
`array_filter`/`updateOrCreate` que todos os outros.

- [ ] **Step 3: Adicionar o campo na config da coluna (Alpine.js)**

Em `resources/views/kanban/config.blade.php`, três alterações:

**3a.** No objeto de dados do componente Alpine, logo depois de
`aguardandoOrientacaoMensagem: {},`:

```javascript
        // Tempo máximo de permanência (Regra 12) — minutos até o comando
        // kanban:monitorar alertar que o ticket travou nessa coluna.
        tempoMaximoPermanenciaMinutos: {},
```

**3b.** Na função que carrega os dados do servidor, logo depois da linha
`this.aguardandoOrientacaoMensagem[key] = json.aguardando_orientacao_mensagem ?? '';`:

```javascript
                this.tempoMaximoPermanenciaMinutos[key] = json.tempo_maximo_permanencia_minutos ?? null;
```

**3c.** No payload de salvamento, logo depois da linha
`aguardando_orientacao_mensagem: this.aguardandoOrientacaoMensagem[key] ?? '',`:

```javascript
                tempo_maximo_permanencia_minutos: this.tempoMaximoPermanenciaMinutos[key] || null,
```

**3d.** No HTML, logo depois do bloco `{{-- Mensagem de espera durante orientação --}}`
existente (que termina com `</textarea></div>`, imediatamente antes do
comentário `{{-- Exclusão definitiva --}}`), adicionar:

```html
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <label class="text-xs font-semibold text-gray-500 mb-1 block">Tempo máximo de permanência (Regra 12)</label>
                                <p class="text-xs text-gray-400 mb-2">
                                    Se um ticket ficar nessa coluna além desse tempo, você recebe um alerta
                                    interno (mesmo ícone dos outros alertas). Deixe em branco pra não monitorar.
                                </p>
                                <div class="flex items-center gap-2">
                                    <input type="number" min="1"
                                           :value="tempoMaximoPermanenciaMinutos[col.key] ?? ''"
                                           @input="tempoMaximoPermanenciaMinutos[col.key] = $event.target.value ? parseInt($event.target.value) : null; iaAlterado[col.key] = true"
                                           class="w-20 text-xs border border-gray-300 rounded px-2 py-1"
                                           placeholder="—">
                                    <span class="text-xs text-gray-500">minutos</span>
                                </div>
                            </div>
```

- [ ] **Step 4: Escrever o teste de API**

```php
<?php
// tests/Feature/KanbanColunaConfigTempoMaximoPermanenciaTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigTempoMaximoPermanenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_null_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');

        $response->assertOk();
        $response->assertJson(['tempo_maximo_permanencia_minutos' => null]);
    }

    public function test_update_salva_o_tempo_maximo_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'tempo_maximo_permanencia_minutos' => 120,
        ]);

        $response->assertOk();
        $this->assertSame(
            120,
            KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')->value('tempo_maximo_permanencia_minutos')
        );
    }

    public function test_update_rejeita_valor_nao_inteiro_ou_menor_que_um(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/aguardando_orcamento', [
            'tempo_maximo_permanencia_minutos' => 0,
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=KanbanColunaConfigTempoMaximoPermanenciaTest`
Expected: PASS (3 testes).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php resources/views/kanban/config.blade.php tests/Feature/KanbanColunaConfigTempoMaximoPermanenciaTest.php
git commit -m "feat: UI de config do tempo máximo de permanência por coluna (Regra 12)"
```

---

## Depois da Task 7

Rodar a suíte inteira (`php artisan test`) e confirmar que nenhum teste
pré-existente quebrou (exceto o `ExampleTest` flaky já conhecido, sem relação
com este bloco). Seguir pra revisão final de branch inteira (opus) e pro
fluxo de `superpowers:finishing-a-development-branch`, mesmo padrão dos
Blocos 1-3.
