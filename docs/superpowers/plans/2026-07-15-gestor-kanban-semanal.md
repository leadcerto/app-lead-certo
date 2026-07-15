# Gestor do Kanban — Relatório Semanal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um agente IA que roda toda semana (sábado 00:00), lê a atividade e as conversas da semana de cada coluna do Kanban de cada tenant ativo, e produz um relatório com números (entradas/avanços/travados por coluna) + análise qualitativa + sugestão de prompt por coluna, mais uma síntese geral. O dono revisa o relatório numa tela nova e copia/cola a sugestão manualmente no contexto da IA da coluna.

**Architecture:** Um novo model/tabela (`kanban_coluna_historico`) registra automaticamente toda mudança de `coluna_kanban` via evento do Eloquent (sem precisar tocar nos ~15 pontos do código que já movem tickets hoje). `GestorKanbanService` lê esse histórico + as conversas amostradas de cada coluna, chama `OpenRouterService` duas vezes por coluna com atividade (análise) e uma vez no final (síntese), e persiste tudo em `gestor_kanban_relatorios`. Um Artisan command agendado roda o serviço pra cada tenant ativo. Duas telas novas: uma pro dono ver os relatórios (`/kanban/relatorios`), outra só pro `admin` editar o prompt global do Gestor (`/admin/gestor-kanban`).

**Tech Stack:** Laravel 13, PHP 8.4, MySQL (produção) / SQLite (testes), Alpine.js, Tailwind, `OpenRouterService` já existente.

## Global Constraints

- Cadência: semanal, sábado 00:00, cobrindo os 7 dias anteriores (`Schedule::command(...)->weeklyOn(6, '00:00')`).
- O prompt do Gestor é **global** (uma linha só, sem `tenant_id`) e só o perfil `admin` pode editá-lo (nunca `dono`) — middleware `role:admin` nas rotas de config, `role:admin,dono` nas rotas de visualização de relatório.
- Novo franqueado já nasce com o Gestor funcionando — o prompt inicial vem semeado pela própria migration, sem passo manual de setup.
- **Histórico de coluna começa a contar a partir do deploy desta feature — não faz backfill do passado** (decisão explícita do usuário: "podemos registrar daqui pra frente e deixar o que passou"). A primeira semana de relatório pode vir com números parciais/zerados pra tickets que já estavam em alguma coluna antes do deploy — isso é esperado, não é bug.
- Todas as consultas do `GestorKanbanService` rodam em contexto de console/scheduler (sem usuário autenticado) — sempre usar `withoutGlobalScopes()` + filtro explícito por `tenant_id`, igual ao padrão já usado em `GerarResumoTicketJob` e `UazapiWebhookController`.
- Sugestão de prompt nunca é aplicada sozinha — só aparece pro dono copiar/colar manualmente no `ia_contexto` da coluna. Fora de escopo nesta versão: envio por WhatsApp/e-mail, botão "aplicar direto", geração sob demanda (só o agendado semanal).
- Coluna sem nenhuma atividade na semana (entradas=0, avanços=0, travados=0) não gera chamada de IA pra ela — economiza tokens; entra no relatório com `analise: 'Sem atividade nesta coluna na semana.'` e `sugestao_prompt: null`.
- Tenant sem nenhuma atividade na semana inteira (todas as colunas sem atividade) não gera relatório algum.
- Rodar o comando de novo pra uma semana já processada não duplica — `updateOrCreate` por `(tenant_id, semana_inicio)`.
- Falha na chamada de IA pra uma coluna não aborta o tenant inteiro — loga warning, essa coluna fica com `analise: null`, resto do relatório segue.

---

### Task 1: Histórico automático de mudança de coluna

**Files:**
- Create: `database/migrations/2026_07_15_190000_create_kanban_coluna_historico_table.php`
- Create: `app/Models/KanbanColunaHistorico.php`
- Modify: `app/Models/TicketAtendimento.php:14-17` (método `booted()`)
- Test: `tests/Feature/KanbanColunaHistoricoTest.php`

**Interfaces:**
- Produces: toda vez que um `TicketAtendimento` é criado ou tem `coluna_kanban` alterado (via `$model->update([...])` ou `Model::create([...])`), uma linha é gravada em `kanban_coluna_historico` com `tenant_id`, `ticket_id`, `coluna` (pra onde foi), `coluna_anterior` (de onde veio, `null` na criação), `entrou_em` (timestamp exato do evento). Tasks 4-7 (`GestorKanbanService`) consomem essa tabela via `App\Models\KanbanColunaHistorico`.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaHistoricoTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_ticket_registra_entrada_na_coluna_inicial(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'tenant_id'       => $tenant->id,
            'ticket_id'       => $ticket->id,
            'coluna'          => 'lead_novo',
            'coluna_anterior' => null,
        ]);
    }

    public function test_mudar_coluna_registra_nova_entrada_com_coluna_anterior(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'tenant_id'       => $tenant->id,
            'ticket_id'       => $ticket->id,
            'coluna'          => 'em_atendimento',
            'coluna_anterior' => 'lead_novo',
        ]);

        $this->assertSame(2, KanbanColunaHistorico::where('ticket_id', $ticket->id)->count());
    }

    public function test_atualizar_ticket_sem_mudar_coluna_nao_registra_nada(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $ticket->update(['agente_responsavel' => 'humano']);

        $this->assertSame(1, KanbanColunaHistorico::where('ticket_id', $ticket->id)->count());
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=KanbanColunaHistoricoTest`
Expected: FAIL (tabela `kanban_coluna_historico` não existe / classe `KanbanColunaHistorico` não existe)

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
        Schema::create('kanban_coluna_historico', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->string('coluna', 50);
            $table->string('coluna_anterior', 50)->nullable();
            $table->timestamp('entrou_em');

            $table->index(['tenant_id', 'coluna', 'entrou_em']);
            $table->index(['tenant_id', 'coluna_anterior', 'entrou_em']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_coluna_historico');
    }
};
```

- [ ] **Step 4: Criar o model**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColunaHistorico extends Model
{
    protected $table = 'kanban_coluna_historico';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'coluna',
        'coluna_anterior',
        'entrou_em',
    ];

    protected function casts(): array
    {
        return ['entrou_em' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }
}
```

- [ ] **Step 5: Adicionar o hook de eventos em `TicketAtendimento`**

Em `app/Models/TicketAtendimento.php`, o método `booted()` atual (linhas 14-17) é:

```php
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }
```

Substituir por:

```php
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::created(function (TicketAtendimento $ticket) {
            KanbanColunaHistorico::create([
                'tenant_id'       => $ticket->tenant_id,
                'ticket_id'       => $ticket->id,
                'coluna'          => $ticket->coluna_kanban,
                'coluna_anterior' => null,
                'entrou_em'       => now(),
            ]);
        });

        static::updated(function (TicketAtendimento $ticket) {
            if ($ticket->wasChanged('coluna_kanban')) {
                KanbanColunaHistorico::create([
                    'tenant_id'       => $ticket->tenant_id,
                    'ticket_id'       => $ticket->id,
                    'coluna'          => $ticket->coluna_kanban,
                    'coluna_anterior' => $ticket->getOriginal('coluna_kanban'),
                    'entrou_em'       => now(),
                ]);
            }
        });
    }
```

Adicionar o import no topo do arquivo, junto aos demais `use`:

```php
use App\Scopes\TenantScope;
```
já existe — adicionar logo abaixo:
```php
use App\Models\KanbanColunaHistorico;
```

(Não é estritamente necessário já que está no mesmo namespace `App\Models`, mas mantém explícito — pode usar `KanbanColunaHistorico::create(...)` direto sem `use` já que ambos os models estão em `App\Models`.)

- [ ] **Step 6: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=KanbanColunaHistoricoTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Rodar a suíte inteira pra garantir que o hook novo não quebrou nenhum teste existente que cria/atualiza tickets**

Run: `php artisan test`
Expected: mesma contagem de falhas de antes desta task (só a falha pré-existente do `ExampleTest`, se ainda existir)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_15_190000_create_kanban_coluna_historico_table.php app/Models/KanbanColunaHistorico.php app/Models/TicketAtendimento.php tests/Feature/KanbanColunaHistoricoTest.php
git commit -m "Adiciona histórico automático de mudança de coluna do Kanban"
```

---

### Task 2: Tabelas e models do Gestor (config global + relatórios)

**Files:**
- Create: `database/migrations/2026_07_15_190001_create_gestor_kanban_config_table.php`
- Create: `database/migrations/2026_07_15_190002_create_gestor_kanban_relatorios_table.php`
- Create: `app/Models/GestorKanbanConfig.php`
- Create: `app/Models/GestorKanbanRelatorio.php`
- Test: `tests/Feature/GestorKanbanModelsTest.php`

**Interfaces:**
- Consumes: nenhuma (task independente de Task 1, pode rodar em paralelo).
- Produces: `GestorKanbanConfig::first()` sempre retorna uma linha (semeada pela migration) com `prompt_coluna` e `prompt_sintese`. `GestorKanbanRelatorio` é tenant-scoped, tem `dados` (array/JSON) e `sintese_geral` (string). Tasks 4-9 dependem destes dois models.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\GestorKanbanConfig;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_global_ja_vem_semeada_pela_migration(): void
    {
        $config = GestorKanbanConfig::first();

        $this->assertNotNull($config);
        $this->assertNotEmpty($config->prompt_coluna);
        $this->assertNotEmpty($config->prompt_sintese);
    }

    public function test_existe_apenas_uma_linha_de_config(): void
    {
        $this->assertSame(1, GestorKanbanConfig::count());
    }

    public function test_relatorio_e_isolado_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        GestorKanbanRelatorio::create([
            'tenant_id'     => $tenantA->id,
            'semana_inicio' => '2026-07-06',
            'semana_fim'    => '2026-07-12',
            'dados'         => ['lead_novo' => ['entradas' => 3]],
            'sintese_geral' => 'Semana ok para o tenant A.',
        ]);

        GestorKanbanRelatorio::create([
            'tenant_id'     => $tenantB->id,
            'semana_inicio' => '2026-07-06',
            'semana_fim'    => '2026-07-12',
            'dados'         => ['lead_novo' => ['entradas' => 9]],
            'sintese_geral' => 'Semana ok para o tenant B.',
        ]);

        session(['tenant_id' => $tenantA->id]);
        $this->actingAs(\App\Models\User::factory()->create(['tenant_id' => $tenantA->id]));

        $relatorios = GestorKanbanRelatorio::all();

        $this->assertCount(1, $relatorios);
        $this->assertSame('Semana ok para o tenant A.', $relatorios->first()->sintese_geral);
    }

    public function test_indice_unico_por_tenant_e_semana(): void
    {
        $tenant = Tenant::factory()->create();

        GestorKanbanRelatorio::create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'primeira',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        GestorKanbanRelatorio::create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'duplicada',
        ]);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanModelsTest`
Expected: FAIL (tabelas/classes não existem)

- [ ] **Step 3: Criar a migration da config global, já com o seed inicial no `up()`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestor_kanban_config', function (Blueprint $table) {
            $table->id();
            $table->text('prompt_coluna');
            $table->text('prompt_sintese');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        DB::table('gestor_kanban_config')->insert([
            'prompt_coluna' => <<<'PROMPT'
Você é o Gestor do Kanban do Lead Certo — um analista que audita o fluxo de vendas de uma coluna específica do funil de atendimento de uma empresa (frete/mudança ou outro nicho, dependendo do cliente) durante uma semana.

Você recebe: o nome da coluna, os números da semana (quantos tickets entraram, quantos avançaram para a próxima etapa, quantos estão travados nela) e uma amostra de conversas reais dessa coluna.

Sua função:
1. Identificar o principal motivo de perda ou travamento nesta coluna, com base nas conversas reais (não invente — cite padrões que você realmente viu nas amostras).
2. Avaliar se o agente de IA responsável por esta coluna está seguindo bem o objetivo dela ou cometendo erros recorrentes.
3. Sugerir um ajuste concreto e específico para o prompt do agente de IA desta coluna — não genérico ("melhore o atendimento"), mas acionável ("pare de perguntar X duas vezes", "sempre confirme Y antes de Z").

Responda SEMPRE exatamente neste formato, sem nada antes ou depois:

ANÁLISE:
<sua análise em até 6 linhas, direta, citando padrões reais das conversas>

SUGESTÃO_PROMPT:
<texto pronto para o dono colar direto no campo "Contexto da IA" desta coluna — só o texto do ajuste, sem explicação sobre por que sugeriu>
PROMPT,
            'prompt_sintese' => <<<'PROMPT'
Você é o Gestor do Kanban do Lead Certo. Você recebe as análises de todas as colunas do funil de vendas de uma empresa, referentes à última semana, e deve escrever uma síntese geral curta (até 8 linhas) para o dono do negócio.

Destaque: (1) onde está o maior gargalo da semana, (2) se algum problema se repete em mais de uma coluna, (3) uma prioridade clara de onde focar primeiro na próxima semana. Tom direto, sem enrolação, como um relatório executivo.
PROMPT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('gestor_kanban_config');
    }
};
```

- [ ] **Step 4: Criar a migration dos relatórios**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestor_kanban_relatorios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('semana_inicio');
            $table->date('semana_fim');
            $table->json('dados');
            $table->text('sintese_geral')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'semana_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestor_kanban_relatorios');
    }
};
```

- [ ] **Step 5: Criar `app/Models/GestorKanbanConfig.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GestorKanbanConfig extends Model
{
    protected $table = 'gestor_kanban_config';

    protected $fillable = [
        'prompt_coluna',
        'prompt_sintese',
        'updated_by',
    ];
}
```

- [ ] **Step 6: Criar `app/Models/GestorKanbanRelatorio.php`**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GestorKanbanRelatorio extends Model
{
    protected $table = 'gestor_kanban_relatorios';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'semana_inicio',
        'semana_fim',
        'dados',
        'sintese_geral',
    ];

    protected function casts(): array
    {
        return [
            'semana_inicio' => 'date',
            'semana_fim'    => 'date',
            'dados'         => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 7: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanModelsTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_15_190001_create_gestor_kanban_config_table.php database/migrations/2026_07_15_190002_create_gestor_kanban_relatorios_table.php app/Models/GestorKanbanConfig.php app/Models/GestorKanbanRelatorio.php tests/Feature/GestorKanbanModelsTest.php
git commit -m "Adiciona tabelas e models do Gestor do Kanban (config global + relatórios semanais)"
```

---

### Task 3: `GestorKanbanService::coletarNumerosColuna()`

**Files:**
- Create: `app/Services/GestorKanbanService.php`
- Test: `tests/Feature/GestorKanbanServiceNumerosTest.php`

**Interfaces:**
- Consumes: `KanbanColunaHistorico` (Task 1), `TicketAtendimento` (existente).
- Produces: `GestorKanbanService::coletarNumerosColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim): array` retornando `['entradas' => int, 'avancos' => int, 'travados' => int, 'tag_desfecho_breakdown' => array]`. Task 7 (orquestração) chama este método.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GestorKanbanServiceNumerosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(int $tenantId, string $coluna, ?string $tagDesfecho = null, ?Carbon $encerradoEm = null): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenantId, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => $coluna === 'encerrado' ? 'encerrado' : 'aberto',
            'aberto_em' => now(), 'tag_desfecho' => $tagDesfecho, 'encerrado_em' => $encerradoEm,
        ]);
    }

    public function test_conta_entradas_na_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenant->id, 'lead_novo');
        $this->criarTicket($tenant->id, 'lead_novo');

        Carbon::setTestNow('2026-07-01 10:00:00'); // fora da semana
        $this->criarTicket($tenant->id, 'lead_novo');
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);

        $this->assertSame(2, $numeros['entradas']);
    }

    public function test_conta_avancos_na_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-01 10:00:00');
        $ticket = $this->criarTicket($tenant->id, 'lead_novo');
        Carbon::setTestNow('2026-07-08 10:00:00');
        $ticket->update(['coluna_kanban' => 'em_atendimento']);
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);

        $this->assertSame(1, $numeros['avancos']);
    }

    public function test_conta_travados_como_quem_esta_na_coluna_desde_antes_da_semana(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-06-20 10:00:00');
        $this->criarTicket($tenant->id, 'em_atendimento'); // travado: entrou bem antes da semana

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenant->id, 'em_atendimento'); // não travado: entrou durante a semana
        Carbon::setTestNow();

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'em_atendimento', $inicio, $fim);

        $this->assertSame(1, $numeros['travados']);
    }

    public function test_breakdown_de_tag_desfecho_so_para_coluna_encerrado(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $this->criarTicket($tenant->id, 'encerrado', 'preco', Carbon::parse('2026-07-08 10:00:00'));
        $this->criarTicket($tenant->id, 'encerrado', 'preco', Carbon::parse('2026-07-09 10:00:00'));
        $this->criarTicket($tenant->id, 'encerrado', 'vendido', Carbon::parse('2026-07-10 10:00:00'));

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'encerrado', $inicio, $fim);

        $this->assertSame(['preco' => 2, 'vendido' => 1], $numeros['tag_desfecho_breakdown']);

        $numerosOutraColuna = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'lead_novo', $inicio, $fim);
        $this->assertSame([], $numerosOutraColuna['tag_desfecho_breakdown']);
    }

    public function test_isola_numeros_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        $this->criarTicket($tenantA->id, 'lead_novo');
        $this->criarTicket($tenantB->id, 'lead_novo');
        $this->criarTicket($tenantB->id, 'lead_novo');
        Carbon::setTestNow();

        $numerosA = app(GestorKanbanService::class)->coletarNumerosColuna($tenantA, 'lead_novo', $inicio, $fim);
        $numerosB = app(GestorKanbanService::class)->coletarNumerosColuna($tenantB, 'lead_novo', $inicio, $fim);

        $this->assertSame(1, $numerosA['entradas']);
        $this->assertSame(2, $numerosB['entradas']);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanServiceNumerosTest`
Expected: FAIL (classe `GestorKanbanService` não existe)

- [ ] **Step 3: Criar o serviço com `coletarNumerosColuna()`**

```php
<?php

namespace App\Services;

use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Support\Carbon;

class GestorKanbanService
{
    public function coletarNumerosColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim): array
    {
        $entradas = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $avancos = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_anterior', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $travados = $this->travadosNaColuna($tenant, $coluna, $inicio);

        $tagDesfechoBreakdown = [];
        if ($coluna === 'encerrado') {
            $tagDesfechoBreakdown = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', 'encerrado')
                ->whereBetween('encerrado_em', [$inicio, $fim])
                ->whereNotNull('tag_desfecho')
                ->selectRaw('tag_desfecho, count(*) as total')
                ->groupBy('tag_desfecho')
                ->pluck('total', 'tag_desfecho')
                ->toArray();
        }

        return [
            'entradas'               => $entradas,
            'avancos'                => $avancos,
            'travados'               => $travados,
            'tag_desfecho_breakdown' => $tagDesfechoBreakdown,
        ];
    }

    /**
     * Tickets que estão atualmente na coluna e entraram nela antes do início
     * da semana analisada — ou seja, já estavam parados ali a semana inteira.
     */
    private function travadosNaColuna(Tenant $tenant, string $coluna, Carbon $inicioSemana): int
    {
        $ticketIds = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', $coluna)
            ->pluck('id');

        if ($ticketIds->isEmpty()) {
            return 0;
        }

        return KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('ticket_id', $ticketIds)
            ->selectRaw('ticket_id, MAX(entrou_em) as ultima_entrada')
            ->groupBy('ticket_id')
            ->havingRaw('MAX(entrou_em) < ?', [$inicioSemana])
            ->get()
            ->count();
    }
}
```

- [ ] **Step 4: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanServiceNumerosTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GestorKanbanService.php tests/Feature/GestorKanbanServiceNumerosTest.php
git commit -m "Adiciona GestorKanbanService::coletarNumerosColuna()"
```

---

### Task 4: `GestorKanbanService::amostrarConversasColuna()`

**Files:**
- Modify: `app/Services/GestorKanbanService.php`
- Test: `tests/Feature/GestorKanbanServiceAmostraTest.php`

**Interfaces:**
- Consumes: `TicketAtendimento`, `Mensagem` (existentes).
- Produces: `GestorKanbanService::amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): \Illuminate\Support\Collection` retornando uma Collection de `TicketAtendimento` (com `mensagens` carregado). `GestorKanbanService::formatarConversa(TicketAtendimento $ticket): string` retornando o texto formatado (usa `resumo_ia` se o ticket estiver encerrado e tiver resumo; senão formata a thread completa). Task 5 (`analisarColuna`) consome os dois.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GestorKanbanServiceAmostraTest extends TestCase
{
    use RefreshDatabase;

    public function test_amostra_prioriza_os_mais_travados_ate_o_limite(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        Carbon::setTestNow('2026-06-01 10:00:00');
        $maisAntigo = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Carbon::setTestNow('2026-07-10 10:00:00');
        $maisRecente = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $amostra = app(GestorKanbanService::class)->amostrarConversasColuna($tenant, 'em_atendimento', $inicio, $fim, 1);

        $this->assertCount(1, $amostra);
        $this->assertSame($maisAntigo->id, $amostra->first()->id);
        $this->assertTrue(true !== $amostra->contains('id', $maisRecente->id));
    }

    public function test_amostra_da_coluna_encerrado_inclui_fechados_na_semana(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
            'encerrado_em' => Carbon::parse('2026-07-08 10:00:00'),
        ]);

        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $amostra = app(GestorKanbanService::class)->amostrarConversasColuna($tenant, 'encerrado', $inicio, $fim, 15);

        $this->assertTrue($amostra->contains('id', $ticket->id));
    }

    public function test_formatar_conversa_usa_resumo_ia_para_ticket_encerrado_com_resumo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'humano',
            'status' => 'encerrado', 'aberto_em' => now(),
            'resumo_ia' => 'Cliente pediu frete de SP para RJ, fechou negócio.',
        ]);

        $texto = app(GestorKanbanService::class)->formatarConversa($ticket);

        $this->assertStringContainsString('Cliente pediu frete de SP para RJ, fechou negócio.', $texto);
    }

    public function test_formatar_conversa_monta_thread_quando_nao_tem_resumo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Quanto custa o frete?',
            'enviado_em' => now(),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Me conta o endereço de origem.',
            'enviado_em' => now(),
        ]);

        $texto = app(GestorKanbanService::class)->formatarConversa($ticket->fresh('mensagens'));

        $this->assertStringContainsString('CLIENTE: Quanto custa o frete?', $texto);
        $this->assertStringContainsString('BOT: Me conta o endereço de origem.', $texto);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanServiceAmostraTest`
Expected: FAIL (métodos não existem)

- [ ] **Step 3: Adicionar os métodos ao serviço**

Em `app/Services/GestorKanbanService.php`, adicionar `use Illuminate\Support\Collection;` no topo junto aos outros imports, e adicionar estes métodos públicos na classe (depois de `coletarNumerosColuna`):

```php
    public function amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): Collection
    {
        if ($coluna !== 'encerrado') {
            return TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', $coluna)
                ->with('mensagens')
                ->orderBy('updated_at', 'asc')
                ->limit($limite)
                ->get();
        }

        // Coluna "encerrado": os fechados NESTA semana são o dado que o
        // relatório semanal realmente precisa mostrar, então entram primeiro
        // e sempre cabem (até $limite). Só preenche o que sobrar com os mais
        // travados (fechados há mais tempo) — nunca o contrário, senão um
        // tenant com muito histórico antigo faz o volume velho engolir a
        // amostra e nenhum encerramento da semana aparece na análise.
        $fechados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->whereBetween('encerrado_em', [$inicio, $fim])
            ->with('mensagens')
            ->orderByDesc('encerrado_em')
            ->limit($limite)
            ->get();

        $vagasRestantes = $limite - $fechados->count();

        if ($vagasRestantes <= 0) {
            return $fechados->values();
        }

        $travados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->whereNotIn('id', $fechados->pluck('id'))
            ->with('mensagens')
            ->orderBy('updated_at', 'asc')
            ->limit($vagasRestantes)
            ->get();

        return $fechados->merge($travados)->values();
    }

    public function formatarConversa(TicketAtendimento $ticket): string
    {
        if ($ticket->coluna_kanban === 'encerrado' && $ticket->resumo_ia) {
            return "[Resumo] {$ticket->resumo_ia}";
        }

        return $ticket->mensagens
            ->filter(fn ($m) => $m->conteudo && $m->conteudo !== '')
            ->map(fn ($m) => match ($m->remetente) {
                'lead'   => 'CLIENTE: ' . $m->conteudo,
                'bot'    => 'BOT: ' . $m->conteudo,
                'humano' => 'ATENDENTE: ' . $m->conteudo,
                default  => strtoupper($m->remetente) . ': ' . $m->conteudo,
            })
            ->implode("\n");
    }
```

- [ ] **Step 4: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanServiceAmostraTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GestorKanbanService.php tests/Feature/GestorKanbanServiceAmostraTest.php
git commit -m "Adiciona amostragem e formatação de conversas ao GestorKanbanService"
```

---

### Task 5: `GestorKanbanService::analisarColuna()`

**Files:**
- Modify: `app/Services/GestorKanbanService.php`
- Test: `tests/Feature/GestorKanbanServiceAnaliseTest.php`

**Interfaces:**
- Consumes: `OpenRouterService::chat()` (existente, injetado no construtor), `GestorKanbanConfig` (Task 2), resultado de `coletarNumerosColuna` (Task 3) e `amostrarConversasColuna`/`formatarConversa` (Task 4).
- Produces: `GestorKanbanService::analisarColuna(Tenant $tenant, string $coluna, array $numeros, Collection $amostras, GestorKanbanConfig $config): array` retornando `['analise' => ?string, 'sugestao_prompt' => ?string]`. Task 6 (`gerarRelatorioSemanal`) consome este método.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanServiceAnaliseTest extends TestCase
{
    use RefreshDatabase;

    public function test_analisar_coluna_parseia_analise_e_sugestao_da_resposta_da_ia(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nO gargalo é o preço não ser respondido rápido.\n\nSUGESTÃO_PROMPT:\nSempre responda o valor do frete em até 2 mensagens."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $tenant  = Tenant::factory()->create();
        $config  = GestorKanbanConfig::first();
        $numeros = ['entradas' => 5, 'avancos' => 2, 'travados' => 3, 'tag_desfecho_breakdown' => []];

        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $amostras = collect([$ticket]);

        $resultado = app(GestorKanbanService::class)->analisarColuna($tenant, 'em_atendimento', $numeros, $amostras, $config);

        $this->assertSame('O gargalo é o preço não ser respondido rápido.', $resultado['analise']);
        $this->assertSame('Sempre responda o valor do frete em até 2 mensagens.', $resultado['sugestao_prompt']);
    }

    public function test_analisar_coluna_retorna_nulos_quando_ia_falha(): void
    {
        Http::fake(['*' => Http::response(['error' => 'falha'], 500)]);

        $tenant  = Tenant::factory()->create();
        $config  = GestorKanbanConfig::first();
        $numeros = ['entradas' => 5, 'avancos' => 2, 'travados' => 3, 'tag_desfecho_breakdown' => []];

        $resultado = app(GestorKanbanService::class)->analisarColuna($tenant, 'em_atendimento', $numeros, collect(), $config);

        $this->assertNull($resultado['analise']);
        $this->assertNull($resultado['sugestao_prompt']);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanServiceAnaliseTest`
Expected: FAIL (método não existe)

- [ ] **Step 3: Adicionar `analisarColuna()` e os parsers privados**

Em `app/Services/GestorKanbanService.php`, adicionar `use App\Models\GestorKanbanConfig;` e `use Illuminate\Support\Facades\Log;` no topo, e injetar `OpenRouterService` no construtor:

```php
class GestorKanbanService
{
    public function __construct(private OpenRouterService $openRouter) {}
```

(adicionar `use App\Services\OpenRouterService;` — na verdade já está no mesmo namespace `App\Services`, não precisa de `use`.)

Adicionar o método público e os dois privados de parsing:

```php
    public function analisarColuna(Tenant $tenant, string $coluna, array $numeros, Collection $amostras, GestorKanbanConfig $config): array
    {
        $conversas = $amostras
            ->map(fn (TicketAtendimento $t) => $this->formatarConversa($t))
            ->filter(fn (string $texto) => trim($texto) !== '')
            ->implode("\n\n---\n\n");

        $numerosTexto = "Entradas: {$numeros['entradas']} | Avanços: {$numeros['avancos']} | Travados: {$numeros['travados']}";
        if (! empty($numeros['tag_desfecho_breakdown'])) {
            $breakdown = collect($numeros['tag_desfecho_breakdown'])
                ->map(fn ($total, $tag) => "{$tag}: {$total}")
                ->implode(', ');
            $numerosTexto .= "\nMotivos de encerramento: {$breakdown}";
        }

        $resposta = $this->openRouter->chat([
            ['role' => 'system', 'content' => $config->prompt_coluna],
            ['role' => 'user', 'content' => "Coluna: {$coluna}\n\nNúmeros da semana:\n{$numerosTexto}\n\nAmostra de conversas:\n\n{$conversas}"],
        ], 'complexo', 800, 'gestor_kanban_coluna', $tenant->id);

        if (! $resposta) {
            Log::warning('GestorKanbanService: falha ao analisar coluna', ['tenant_id' => $tenant->id, 'coluna' => $coluna]);
            return ['analise' => null, 'sugestao_prompt' => null];
        }

        return [
            'analise'         => $this->extrairAnalise($resposta),
            'sugestao_prompt' => $this->extrairSugestao($resposta),
        ];
    }

    private function extrairAnalise(string $resposta): ?string
    {
        $semSugestao = preg_split('/SUGEST[AÃ]O_PROMPT:/i', $resposta)[0];
        $analise     = trim(preg_replace('/AN[AÁ]LISE:\s*/i', '', $semSugestao, 1));

        return $analise !== '' ? $analise : null;
    }

    private function extrairSugestao(string $resposta): ?string
    {
        if (! preg_match('/SUGEST[AÃ]O_PROMPT:\s*(.+)/is', $resposta, $m)) {
            return null;
        }

        $sugestao = trim($m[1]);

        return $sugestao !== '' ? $sugestao : null;
    }
```

- [ ] **Step 4: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanServiceAnaliseTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Rodar os testes anteriores do serviço, confirmar que a injeção do construtor não quebrou nada**

Run: `php artisan test --filter=GestorKanbanService`
Expected: PASS (todos os testes de `GestorKanbanServiceNumerosTest`, `GestorKanbanServiceAmostraTest` e `GestorKanbanServiceAnaliseTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Services/GestorKanbanService.php tests/Feature/GestorKanbanServiceAnaliseTest.php
git commit -m "Adiciona GestorKanbanService::analisarColuna() com parsing de análise e sugestão"
```

---

### Task 6: `GestorKanbanService::sintetizarSemana()` e `gerarRelatorioSemanal()`

**Files:**
- Modify: `app/Services/GestorKanbanService.php`
- Test: `tests/Feature/GestorKanbanServiceOrquestracaoTest.php`

**Interfaces:**
- Consumes: todos os métodos anteriores do serviço (Tasks 3-5), `GestorKanbanRelatorio` (Task 2).
- Produces: `GestorKanbanService::gerarRelatorioSemanal(Tenant $tenant, Carbon $inicio, Carbon $fim): ?GestorKanbanRelatorio`. Task 7 (Artisan command) chama este método — é o método de entrada público principal do serviço.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\GestorKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanServiceOrquestracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nTudo bem por aqui.\n\nSUGESTÃO_PROMPT:\nContinue assim."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    public function test_gera_relatorio_com_dados_por_coluna_e_sintese(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertNotNull($relatorio);
        $this->assertSame($tenant->id, $relatorio->tenant_id);
        $this->assertArrayHasKey('lead_novo', $relatorio->dados);
        $this->assertSame(1, $relatorio->dados['lead_novo']['entradas']);
        $this->assertSame('Tudo bem por aqui.', $relatorio->dados['lead_novo']['analise']);
        $this->assertNotNull($relatorio->sintese_geral);
    }

    public function test_coluna_sem_atividade_nao_chama_ia_e_fica_com_mensagem_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $contato = Contato::factory()->create();
        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertSame('Sem atividade nesta coluna na semana.', $relatorio->dados['pagamento']['analise']);
        $this->assertNull($relatorio->dados['pagamento']['sugestao_prompt']);
    }

    public function test_tenant_sem_nenhuma_atividade_nao_gera_relatorio(): void
    {
        $tenant = Tenant::factory()->create();
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertNull($relatorio);
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_rodar_de_novo_pra_mesma_semana_atualiza_em_vez_de_duplicar(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $inicio  = Carbon::parse('2026-07-06 00:00:00');
        $fim     = Carbon::parse('2026-07-12 23:59:59');

        Carbon::setTestNow('2026-07-08 10:00:00');
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);
        app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanServiceOrquestracaoTest`
Expected: FAIL (método não existe)

- [ ] **Step 3: Adicionar `sintetizarSemana()` e `gerarRelatorioSemanal()`**

Em `app/Services/GestorKanbanService.php`, adicionar `use App\Models\GestorKanbanRelatorio;` no topo, e adicionar estes dois métodos públicos:

```php
    private const COLUNAS = [
        'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
        'pagamento', 'servico_agendado', 'encerrado', 'outros',
    ];

    public function gerarRelatorioSemanal(Tenant $tenant, Carbon $inicio, Carbon $fim): ?GestorKanbanRelatorio
    {
        $config = GestorKanbanConfig::first();

        if (! $config) {
            Log::error('GestorKanbanService: config global não encontrada');
            return null;
        }

        $dados        = [];
        $temAtividade = false;

        foreach (self::COLUNAS as $coluna) {
            $numeros = $this->coletarNumerosColuna($tenant, $coluna, $inicio, $fim);

            if ($numeros['entradas'] === 0 && $numeros['avancos'] === 0 && $numeros['travados'] === 0) {
                $dados[$coluna] = array_merge($numeros, [
                    'coluna'          => $coluna,
                    'analise'         => 'Sem atividade nesta coluna na semana.',
                    'sugestao_prompt' => null,
                ]);
                continue;
            }

            $temAtividade = true;
            $amostras      = $this->amostrarConversasColuna($tenant, $coluna, $inicio, $fim);
            $resultado     = $this->analisarColuna($tenant, $coluna, $numeros, $amostras, $config);

            $dados[$coluna] = array_merge($numeros, ['coluna' => $coluna], $resultado);
        }

        if (! $temAtividade) {
            return null;
        }

        $sintese = $this->sintetizarSemana($tenant, $dados, $config);

        // O cast 'date' do model grava semana_inicio como 'Y-m-d H:i:s' (o
        // formato padrão do Grammar do Laravel, mesmo em MySQL/SQLite — só o
        // SqlServerGrammar sobrescreve isso). updateOrCreate() NÃO passa a
        // chave de busca pelo pipeline de cast do model, então buscar com uma
        // string 'Y-m-d' pura (via ->toDateString()) nunca bate com o valor
        // já salvo — o find() falha, tenta inserir de novo, e colide com o
        // índice único. Passar um Carbon (não string) resolve: o binding
        // passa por Connection::prepareBindings(), que formata qualquer
        // DateTimeInterface com o mesmo getDateFormat() usado na escrita.
        return GestorKanbanRelatorio::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'semana_inicio' => $inicio->copy()->startOfDay()],
            [
                'semana_fim'    => $fim->toDateString(),
                'dados'         => $dados,
                'sintese_geral' => $sintese ? trim($sintese) : null,
            ]
        );
    }

    public function sintetizarSemana(Tenant $tenant, array $dadosPorColuna, GestorKanbanConfig $config): ?string
    {
        $resumoColunas = collect($dadosPorColuna)
            ->filter(fn (array $d) => ! empty($d['analise']))
            ->map(fn (array $d, string $coluna) => "### {$coluna}\n{$d['analise']}")
            ->implode("\n\n");

        if (trim($resumoColunas) === '') {
            return null;
        }

        return $this->openRouter->chat([
            ['role' => 'system', 'content' => $config->prompt_sintese],
            ['role' => 'user', 'content' => "Análises da semana por coluna:\n\n{$resumoColunas}"],
        ], 'complexo', 600, 'gestor_kanban_sintese', $tenant->id);
    }
```

- [ ] **Step 4: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanServiceOrquestracaoTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Rodar a suíte inteira do serviço**

Run: `php artisan test --filter=GestorKanbanService`
Expected: PASS (todos os testes de todas as 4 classes de teste do serviço)

- [ ] **Step 6: Commit**

```bash
git add app/Services/GestorKanbanService.php tests/Feature/GestorKanbanServiceOrquestracaoTest.php
git commit -m "Adiciona GestorKanbanService::gerarRelatorioSemanal() (orquestração completa)"
```

---

### Task 7: Comando `kanban:gestor-semanal` + agendamento

**Files:**
- Create: `app/Console/Commands/GestorKanbanSemanalCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/GestorKanbanSemanalCommandTest.php`

**Interfaces:**
- Consumes: `GestorKanbanService::gerarRelatorioSemanal()` (Task 6), `Tenant` (existente, filtra por `status='ativo'`).
- Produces: comando Artisan `kanban:gestor-semanal {--tenant=} {--dry-run}`, agendado toda semana. Nenhuma task futura depende deste — é o ponto de entrada operacional.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GestorKanbanSemanalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' =>
                "ANÁLISE:\nOk.\n\nSUGESTÃO_PROMPT:\nOk."
            ]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    private function criarTenantComAtividade(string $status = 'ativo'): Tenant
    {
        $tenant  = Tenant::factory()->create(['status' => $status]);
        $contato = Contato::factory()->create();

        Carbon::setTestNow(now()->subDays(2));
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Carbon::setTestNow();

        return $tenant;
    }

    public function test_roda_para_todos_os_tenants_ativos(): void
    {
        $tenantAtivo    = $this->criarTenantComAtividade('ativo');
        $tenantSuspenso = $this->criarTenantComAtividade('suspenso');

        $this->artisan('kanban:gestor-semanal')->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantAtivo->id)->count());
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantSuspenso->id)->count());
    }

    public function test_opcao_tenant_roda_so_para_um_tenant(): void
    {
        $tenantA = $this->criarTenantComAtividade('ativo');
        $tenantB = $this->criarTenantComAtividade('ativo');

        $this->artisan('kanban:gestor-semanal', ['--tenant' => $tenantA->id])->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_opcao_tenant_processa_mesmo_tenant_suspenso(): void
    {
        $tenantSuspenso = $this->criarTenantComAtividade('suspenso');

        $this->artisan('kanban:gestor-semanal', ['--tenant' => $tenantSuspenso->id])->assertExitCode(0);

        $this->assertSame(1, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenantSuspenso->id)->count());
    }

    public function test_dry_run_nao_persiste_nada(): void
    {
        $tenant = $this->criarTenantComAtividade('ativo');

        $this->artisan('kanban:gestor-semanal', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, GestorKanbanRelatorio::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanSemanalCommandTest`
Expected: FAIL (comando não existe)

- [ ] **Step 3: Criar o comando**

```php
<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\GestorKanbanService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GestorKanbanSemanalCommand extends Command
{
    protected $signature = 'kanban:gestor-semanal
                            {--tenant= : Roda só para este tenant_id}
                            {--dry-run : Mostra o que faria sem chamar IA nem persistir}';

    protected $description = 'Gera o relatório semanal do Gestor do Kanban para os tenants ativos';

    public function handle(GestorKanbanService $service): int
    {
        $dryRun = $this->option('dry-run');
        // Termina ONTEM (não hoje) — se rodar manualmente via --tenant no meio
        // do sábado, um Carbon::now()->endOfDay() incluiria o próprio sábado
        // (ainda incompleto) na janela "semana anterior", gerando 8 dias em
        // vez de 7 e misturando dado parcial de hoje no relatório.
        $fim    = Carbon::yesterday()->endOfDay();
        $inicio = $fim->copy()->subDays(6)->startOfDay();

        $query = Tenant::query();

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        } else {
            $query->where('status', 'ativo');
        }

        $tenants = $query->get();

        $this->info("Gerando relatório semanal ({$inicio->toDateString()} a {$fim->toDateString()}) para {$tenants->count()} tenant(s).");

        foreach ($tenants as $tenant) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Processaria tenant #{$tenant->id} ({$tenant->nome})");
                continue;
            }

            $relatorio = $service->gerarRelatorioSemanal($tenant, $inicio, $fim);

            if ($relatorio) {
                $this->line("  ✓ Relatório gerado para tenant #{$tenant->id} ({$tenant->nome})");
            } else {
                $this->line("  – Sem atividade para tenant #{$tenant->id} ({$tenant->nome}), pulado");
            }
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanSemanalCommandTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Agendar o comando em `routes/console.php`**

Adicionar ao final do arquivo `routes/console.php` (depois do bloco de `conversas:limpar-antigas`):

```php

// Sábado 00:00 — Relatório semanal do Gestor do Kanban (números + análise + sugestão de prompt por coluna)
Schedule::command('kanban:gestor-semanal')
    ->weeklyOn(6, '00:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/gestor-kanban-semanal.log'));
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/GestorKanbanSemanalCommand.php routes/console.php tests/Feature/GestorKanbanSemanalCommandTest.php
git commit -m "Adiciona comando kanban:gestor-semanal e agendamento (sábado 00:00)"
```

---

### Task 8: Tela de configuração do prompt global (só `admin`)

**Files:**
- Create: `app/Http/Controllers/Admin/GestorKanbanConfigController.php`
- Create: `resources/views/admin/gestor-kanban.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GestorKanbanConfigControllerTest.php`

**Interfaces:**
- Consumes: `GestorKanbanConfig` (Task 2).
- Produces: `GET /admin/gestor-kanban` (view), `GET /api/admin/gestor-kanban/prompt` (JSON), `PUT /api/admin/gestor-kanban/prompt` (JSON). Independente das demais tasks restantes.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\GestorKanbanConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_o_prompt_atual(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin', 'tenant_id' => null, 'ativo' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/gestor-kanban/prompt');

        $response->assertOk();
        $response->assertJsonStructure(['prompt_coluna', 'prompt_sintese']);
    }

    public function test_admin_atualiza_o_prompt(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin', 'tenant_id' => null, 'ativo' => true]);

        $response = $this->actingAs($admin)->putJson('/api/admin/gestor-kanban/prompt', [
            'prompt_coluna'  => 'Novo prompt de coluna',
            'prompt_sintese' => 'Novo prompt de síntese',
        ]);

        $response->assertOk();
        $config = GestorKanbanConfig::first();
        $this->assertSame('Novo prompt de coluna', $config->prompt_coluna);
        $this->assertSame($admin->id, $config->updated_by);
    }

    public function test_dono_nao_acessa_a_config(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $response = $this->actingAs($dono)->getJson('/api/admin/gestor-kanban/prompt');

        $response->assertStatus(403);
    }

    public function test_dono_nao_consegue_editar_a_config(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $response = $this->actingAs($dono)->putJson('/api/admin/gestor-kanban/prompt', [
            'prompt_coluna'  => 'Tentativa de invasão',
            'prompt_sintese' => 'Tentativa de invasão',
        ]);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanConfigControllerTest`
Expected: FAIL (rotas/controller não existem)

- [ ] **Step 3: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GestorKanbanConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GestorKanbanConfigController extends Controller
{
    public function view(): View
    {
        return view('admin.gestor-kanban', [
            'config' => GestorKanbanConfig::first(),
        ]);
    }

    public function show(): JsonResponse
    {
        $config = GestorKanbanConfig::first();

        return response()->json([
            'prompt_coluna'  => $config->prompt_coluna,
            'prompt_sintese' => $config->prompt_sintese,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'prompt_coluna'  => 'required|string',
            'prompt_sintese' => 'required|string',
        ]);

        $config = GestorKanbanConfig::first();
        $config->update([
            'prompt_coluna'  => $request->prompt_coluna,
            'prompt_sintese' => $request->prompt_sintese,
            'updated_by'     => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Criar a view**

```blade
@extends('layouts.app')

@section('title', 'Gestor do Kanban — Configuração')

@section('content')
<div x-data="gestorKanbanConfig()" class="max-w-3xl">
    <h1 class="text-xl font-bold text-gray-800 mb-1">Gestor do Kanban — Prompt Global</h1>
    <p class="text-sm text-gray-500 mb-5">
        Este prompt é usado pelo Gestor toda semana, para todos os franqueados. Só o perfil admin edita.
    </p>

    <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-5">
        <div>
            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">
                Prompt de análise por coluna
            </label>
            <textarea x-model="promptColuna" rows="14"
                      class="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
        </div>

        <div>
            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide block mb-1">
                Prompt de síntese geral da semana
            </label>
            <textarea x-model="promptSintese" rows="8"
                      class="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
        </div>

        <div class="flex items-center gap-3">
            <button @click="salvar()" :disabled="salvando"
                    class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium disabled:opacity-50">
                <span x-text="salvando ? 'Salvando...' : 'Salvar'"></span>
            </button>
            <span x-show="salvo" class="text-xs text-green-600">Salvo!</span>
        </div>
    </div>
</div>

<script>
function gestorKanbanConfig() {
    return {
        promptColuna: {{ Js::from($config->prompt_coluna) }},
        promptSintese: {{ Js::from($config->prompt_sintese) }},
        salvando: false,
        salvo: false,
        async salvar() {
            this.salvando = true;
            this.salvo = false;
            const res = await fetch('/api/admin/gestor-kanban/prompt', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ prompt_coluna: this.promptColuna, prompt_sintese: this.promptSintese }),
            });
            this.salvando = false;
            if (res.ok) {
                this.salvo = true;
                setTimeout(() => this.salvo = false, 2000);
            } else {
                alert('Erro ao salvar.');
            }
        },
    };
}
</script>
@endsection
```

- [ ] **Step 5: Adicionar as rotas**

Em `routes/web.php`, adicionar o import no topo:

```php
use App\Http\Controllers\Admin\GestorKanbanConfigController;
```

Dentro do bloco `Route::middleware(['auth', 'tenant'])->group(function () { ... })` (o mesmo grupo onde está `/admin/especificacoes`), adicionar logo depois das rotas de especificações (depois da linha `->middleware('role:admin,dono');` da rota `admin.especificacoes.show`):

```php

    // Gestor do Kanban — configuração do prompt global — só admin (nunca dono)
    Route::get('/admin/gestor-kanban', [GestorKanbanConfigController::class, 'view'])
        ->name('admin.gestor-kanban')
        ->middleware('role:admin');
```

Dentro do bloco `Route::prefix('api/painel')->middleware(['auth', 'tenant'])->group(function () { ... })`, ao final (antes do fechamento `});` do grupo), adicionar um novo grupo de rotas de API admin (fora do `api/painel`, criar um novo bloco separado depois do fechamento do grupo `api/painel`):

```php
// ── API — Admin (Lead Certo, nunca franqueado) ────────────────────────────
Route::prefix('api/admin')->middleware(['auth', 'tenant', 'role:admin'])->group(function () {
    Route::get('/gestor-kanban/prompt', [GestorKanbanConfigController::class, 'show']);
    Route::put('/gestor-kanban/prompt', [GestorKanbanConfigController::class, 'update']);
});
```

- [ ] **Step 6: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanConfigControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/GestorKanbanConfigController.php resources/views/admin/gestor-kanban.blade.php routes/web.php tests/Feature/GestorKanbanConfigControllerTest.php
git commit -m "Adiciona tela de configuração do prompt global do Gestor do Kanban (admin-only)"
```

---

### Task 9: Tela de relatórios semanais (dono + admin)

**Files:**
- Create: `app/Http/Controllers/Painel/GestorKanbanRelatorioController.php`
- Create: `resources/views/kanban/relatorios.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GestorKanbanRelatorioControllerTest.php`

**Interfaces:**
- Consumes: `GestorKanbanRelatorio` (Task 2).
- Produces: `GET /kanban/relatorios` (view), `GET /api/painel/kanban/relatorios` (lista JSON), `GET /api/painel/kanban/relatorios/{id}` (detalhe JSON). Última task do plano — sem dependentes.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\GestorKanbanRelatorio;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestorKanbanRelatorioControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_ve_lista_de_relatorios_do_proprio_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $dono    = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenantA->id, 'ativo' => true]);

        GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 3]], 'sintese_geral' => 'Semana A',
        ]);
        GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 9]], 'sintese_geral' => 'Semana B',
        ]);

        $response = $this->actingAs($dono)->getJson('/api/painel/kanban/relatorios');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['sintese_geral' => 'Semana A']);
    }

    public function test_dono_ve_detalhe_de_relatorio_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenant->id, 'ativo' => true]);

        $relatorio = GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => ['lead_novo' => ['entradas' => 3]], 'sintese_geral' => 'Semana A',
        ]);

        $response = $this->actingAs($dono)->getJson("/api/painel/kanban/relatorios/{$relatorio->id}");

        $response->assertOk();
        $response->assertJsonFragment(['sintese_geral' => 'Semana A']);
    }

    public function test_dono_nao_acessa_relatorio_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $dono    = User::factory()->create(['perfil' => 'dono', 'tenant_id' => $tenantA->id, 'ativo' => true]);

        $relatorioB = GestorKanbanRelatorio::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'semana_inicio' => '2026-07-06', 'semana_fim' => '2026-07-12',
            'dados' => [], 'sintese_geral' => 'Semana B',
        ]);

        $response = $this->actingAs($dono)->getJson("/api/painel/kanban/relatorios/{$relatorioB->id}");

        $response->assertStatus(404);
    }
}
```

- [ ] **Step 2: Rodar o teste, confirmar que falha**

Run: `php artisan test --filter=GestorKanbanRelatorioControllerTest`
Expected: FAIL (rotas/controller não existem)

- [ ] **Step 3: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\GestorKanbanRelatorio;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class GestorKanbanRelatorioController extends Controller
{
    public function view(): View
    {
        return view('kanban.relatorios');
    }

    public function index(): JsonResponse
    {
        $relatorios = GestorKanbanRelatorio::orderByDesc('semana_inicio')->get();

        return response()->json(['data' => $relatorios]);
    }

    public function show(int $id): JsonResponse
    {
        $relatorio = GestorKanbanRelatorio::findOrFail($id);

        return response()->json($relatorio);
    }
}
```

- [ ] **Step 4: Criar a view**

```blade
@extends('layouts.app')

@section('title', 'Relatórios do Gestor do Kanban')

@section('content')
<div x-data="gestorKanbanRelatorios()" x-init="carregar()">
    <h1 class="text-xl font-bold text-gray-800 mb-1">Relatórios Semanais — Gestor do Kanban</h1>
    <p class="text-sm text-gray-500 mb-5">Gerado todo sábado à meia-noite, analisando os últimos 7 dias.</p>

    <template x-if="relatorios.length === 0">
        <div class="py-16 text-center text-gray-400">
            <p class="text-sm">Nenhum relatório ainda. O primeiro sai no próximo sábado à meia-noite.</p>
        </div>
    </template>

    <div class="space-y-3">
        <template x-for="r in relatorios" :key="r.id">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <button @click="toggle(r.id)" class="w-full flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition-colors">
                    <span class="text-sm font-medium text-gray-800"
                          x-text="'Semana de ' + formatarData(r.semana_inicio) + ' a ' + formatarData(r.semana_fim)"></span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform duration-200" :class="aberto === r.id ? 'rotate-180' : ''"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <template x-if="aberto === r.id">
                    <div class="px-5 pb-5 border-t border-gray-100 pt-4 space-y-4">
                        <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">
                            <p class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-1">Síntese da semana</p>
                            <p class="text-sm text-gray-700 whitespace-pre-wrap" x-text="r.sintese_geral || '—'"></p>
                        </div>

                        <template x-for="(dadosColuna, coluna) in r.dados" :key="coluna">
                            <div class="border border-gray-100 rounded-lg p-4">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="text-sm font-semibold text-gray-800" x-text="coluna"></span>
                                    <span class="text-xs text-gray-400"
                                          x-text="'Entradas: ' + dadosColuna.entradas + ' · Avanços: ' + dadosColuna.avancos + ' · Travados: ' + dadosColuna.travados"></span>
                                </div>
                                <p class="text-sm text-gray-600 whitespace-pre-wrap mb-2" x-text="dadosColuna.analise"></p>
                                <template x-if="dadosColuna.sugestao_prompt">
                                    <div class="bg-gray-50 rounded-lg p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Sugestão de ajuste de prompt</p>
                                            <button @click="copiar(dadosColuna.sugestao_prompt, coluna)"
                                                    class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                                <span x-text="copiado === coluna ? 'Copiado!' : 'Copiar'"></span>
                                            </button>
                                        </div>
                                        <p class="text-xs font-mono text-gray-700 whitespace-pre-wrap" x-text="dadosColuna.sugestao_prompt"></p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

<script>
function gestorKanbanRelatorios() {
    return {
        relatorios: [],
        aberto: null,
        copiado: null,
        async carregar() {
            const res = await fetch('/api/painel/kanban/relatorios');
            const json = await res.json();
            this.relatorios = json.data;
        },
        toggle(id) {
            this.aberto = this.aberto === id ? null : id;
        },
        formatarData(data) {
            return new Date(data + 'T00:00:00').toLocaleDateString('pt-BR');
        },
        async copiar(texto, coluna) {
            await navigator.clipboard.writeText(texto);
            this.copiado = coluna;
            setTimeout(() => this.copiado = null, 1500);
        },
    };
}
</script>
@endsection
```

- [ ] **Step 5: Adicionar as rotas**

Em `routes/web.php`, adicionar o import no topo:

```php
use App\Http\Controllers\Painel\GestorKanbanRelatorioController;
```

Dentro do bloco `Route::middleware(['auth', 'tenant'])->group(function () { ... })`, logo depois da rota `kanban.variaveis`:

```php

    // Relatórios semanais do Gestor do Kanban — dono e admin
    Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'view'])
        ->name('kanban.relatorios')
        ->middleware('role:admin,dono');
```

Dentro do bloco `Route::prefix('api/painel')->middleware(['auth', 'tenant'])->group(function () { ... })` existe um sub-grupo do Kanban: `Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () { ... });` (contém as rotas `/kanban/tickets`, `/kanban/ticket/{ticket}/...` etc.). Como o relatório precisa ser **admin,dono apenas** — mais restrito que esse sub-grupo — adicionar um novo sub-grupo logo **depois do `});` que fecha esse sub-grupo do Kanban**, ainda dentro do `api/painel` (mesmo nível de indentação):

```php

    Route::middleware('role:admin,dono')->group(function () {
        Route::get('/kanban/relatorios', [GestorKanbanRelatorioController::class, 'index']);
        Route::get('/kanban/relatorios/{id}', [GestorKanbanRelatorioController::class, 'show']);
    });
```

- [ ] **Step 6: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=GestorKanbanRelatorioControllerTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Rodar a suíte inteira**

Run: `php artisan test`
Expected: mesma contagem de falhas de antes de começar este plano (só a falha pré-existente do `ExampleTest`, se ainda existir)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Painel/GestorKanbanRelatorioController.php resources/views/kanban/relatorios.blade.php routes/web.php tests/Feature/GestorKanbanRelatorioControllerTest.php
git commit -m "Adiciona tela de relatórios semanais do Gestor do Kanban (dono + admin)"
```

---

## Depois de todas as tasks

- [ ] Rodar `php artisan test` uma última vez, suíte completa.
- [ ] Deploy via `bash deploy.sh` (após `git commit` de tudo, se ainda não commitado task a task).
- [ ] Adicionar o link "Relatórios" no menu do Kanban (`resources/views/layouts/app.blade.php`, ao lado de "Atendimentos"/"Variáveis"/"Configurações") — **não incluído como task formal porque é uma linha de HTML sem lógica; fazer manualmente ao final, seguindo o padrão dos links irmãos já existentes em `kanban.variaveis`/`kanban.config`.**
- [ ] Adicionar o link "Gestor do Kanban" no menu, visível só para `auth()->user()->isAdmin()` — mesmo padrão do link de Configurações do Kanban que já usa `@if(auth()->user()->isDono())`.
- [ ] Confirmar em produção, depois do primeiro sábado, que o log em `storage/logs/gestor-kanban-semanal.log` mostra o comando rodando sem erro.
