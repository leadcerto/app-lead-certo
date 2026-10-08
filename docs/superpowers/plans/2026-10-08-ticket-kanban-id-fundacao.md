# Ticket ganha `kanban_id` direto — fundação pra múltiplos Kanbans por empresa

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a cada `TicketAtendimento` uma referência direta e inequívoca a qual `Kanban`
ele pertence, e eliminar a ambiguidade de `KanbanColuna::chaveDeEntrada()`/helpers quando um
tenant passa a ter mais de um Kanban — sem quebrar nenhum tenant que hoje só tem um.

**Architecture:** Migration aditiva (`kanban_id` nullable em `tickets_atendimento`, backfill
pro Kanban `tipo='vendas'` de cada tenant — hoje todo tenant só tem esse, então o backfill é
sempre correto e sem ambiguidade). Os métodos estáticos de `KanbanColuna` ganham um parâmetro
`?int $kanbanId = null` opcional, 100% retrocompatível (comportamento idêntico ao de hoje
quando omitido). Os 8 pontos do código que criam `TicketAtendimento` passam a resolver e
gravar `kanban_id` explicitamente, usando-o no `chaveDeEntrada()` quando disponível.

**Tech Stack:** Laravel 11 (PHP), PHPUnit, MySQL.

**Spec:** `docs/superpowers/specs/2026-10-07-funil-qualificacao-leads-imoveis-caixa-design.md`
(seção 3, "Risco crítico") — este plano implementa a correção ali descrita, e é pré-requisito
tanto pra Fase 2 do funil quanto pro pedido do Leonardo (08/10) de tornar Motivos de
Encerramento/Variáveis/Relatórios independentes por Kanban (trabalho futuro, plano separado,
que vai depender deste aqui estar pronto).

## Global Constraints

- Suíte completa sem regressão além da baseline de 17 falhas pré-existentes (rodar
  `php artisan test` antes de começar pra confirmar o número atual, e depois de cada task).
- Toda mudança de assinatura de método estático deve manter 100% de compatibilidade com
  chamadas existentes sem o novo parâmetro (parâmetro sempre opcional, com default `null`).
- Nenhuma migration pode falhar ou corromper dado em tenants já existentes — toda alteração é
  aditiva (coluna nova nullable) + backfill, nunca destrutiva.
- Commits em português, seguindo o padrão do repositório, terminando com
  `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- Deploy em produção (`deploy.sh`) só acontece depois de TODAS as tasks deste plano passarem,
  numa única leva, com confirmação explícita do Leonardo antes — não fazer deploy parcial.

## Review Focus

- Tenant que já tem 2 Kanbans (caso real: depois que o Kanban da Imersão existir) —
  `chaveDeEntrada($tenantId)` SEM o novo parâmetro continua retornando algum valor válido
  (ambíguo, mas não lança exceção) — comportamento documentado, não deve virar erro 500.
- `MetaWebhookController`/`ProcessarCommentToDmJob` hoje gravam `coluna_kanban` fixo como
  `'novo_lead'` — pra um tenant cuja coluna de entrada tem outra chave (ex.: Imóveis Caixa,
  que usa `novo`), isso já é um bug latente hoje. Depois da correção, o ticket deve cair na
  coluna de entrada REAL do tenant, não mais no literal `'novo_lead'`.
- Migration de backfill rodada numa base com `migrate:fresh` (do zero) não pode falhar nem
  deixar `kanban_id` nulo em nenhum ticket de um tenant que tem Kanban `tipo='vendas'`.
- Tenant sem NENHUM Kanban `tipo='vendas'` (estado inconsistente, não deveria existir, mas é
  defensivo) — backfill não deve quebrar a migration inteira, só deixar aquele ticket com
  `kanban_id` null.
- `chaveDeEntrada($tenantId, $kanbanId)` chamado com um `$kanbanId` que pertence a OUTRO
  tenant — o filtro por `tenant_id` continua valendo, não pode vazar coluna de tenant errado.

---

## Task 1: Migration — `kanban_id` em `tickets_atendimento` + backfill

**Files:**
- Create: `database/migrations/2026_10_08_000001_add_kanban_id_to_tickets_atendimento_table.php`
- Modify: `app/Models/TicketAtendimento.php:220` (fillable), `app/Models/TicketAtendimento.php:286` (relacionamentos)
- Test: `tests/Feature/TicketAtendimentoKanbanIdTest.php`

**Interfaces:**
- Produces: `TicketAtendimento.kanban_id` (nullable int, FK pra `kanbans.id`), fillable;
  `TicketAtendimento::kanban(): BelongsTo` — usado pelas tasks seguintes e por qualquer
  código futuro que precise saber de qual Kanban um ticket é.

- [ ] **Step 1: Escrever o teste que ainda vai falhar**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_tem_kanban_id_fillable_e_relacionamento(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $ticket = TicketAtendimento::create([
            'tenant_id'     => $tenant->id,
            'contato_id'    => \App\Models\Contato::factory()->create(['tenant_id' => $tenant->id])->id,
            'kanban_id'     => $kanban->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
        ]);

        $this->assertSame($kanban->id, $ticket->fresh()->kanban_id);
        $this->assertTrue($ticket->kanban->is($kanban));
    }

    public function test_backfill_preenche_kanban_id_dos_tickets_ja_existentes(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        // Simula um ticket criado ANTES da migration existir, inserindo direto sem kanban_id.
        $id = \Illuminate\Support\Facades\DB::table('tickets_atendimento')->insertGetId([
            'tenant_id'     => $tenant->id,
            'contato_id'    => \App\Models\Contato::factory()->create(['tenant_id' => $tenant->id])->id,
            'coluna_kanban' => 'lead_novo',
            'status'        => 'aberto',
            'aberto_em'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Roda o backfill da migration manualmente (mesma query que ela usa).
        \Illuminate\Support\Facades\DB::statement('
            UPDATE tickets_atendimento t
            JOIN kanbans k ON k.tenant_id = t.tenant_id AND k.tipo = "vendas"
            SET t.kanban_id = k.id
            WHERE t.kanban_id IS NULL
        ');

        $this->assertSame($kanban->id, TicketAtendimento::find($id)->kanban_id);
    }
}
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=TicketAtendimentoKanbanIdTest`
Expected: FAIL — `kanban_id` não existe na tabela (erro de SQL "Unknown column").

- [ ] **Step 3: Criar a migration**

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
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreignId('kanban_id')->nullable()->after('tenant_id')
                ->constrained('kanbans')->nullOnDelete();
        });

        // Backfill: hoje todo tenant só tem o Kanban tipo='vendas', então isso
        // é sempre inequívoco — nenhum tenant tem mais de um Kanban ainda.
        DB::statement('
            UPDATE tickets_atendimento t
            JOIN kanbans k ON k.tenant_id = t.tenant_id AND k.tipo = "vendas"
            SET t.kanban_id = k.id
            WHERE t.kanban_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_id');
        });
    }
};
```

- [ ] **Step 4: Adicionar `kanban_id` ao fillable e o relacionamento `kanban()`**

Em `app/Models/TicketAtendimento.php`, adicionar `'kanban_id',` logo depois de `'tenant_id',`
na linha 220 do array `$fillable`, e adicionar o método depois de `tenant()` (linha ~289):

```php
    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }
```

(Confirmar que `use App\Models\Kanban;` ou o namespace completo já está disponível — o model
já usa `BelongsTo` nos outros relacionamentos, só repetir o padrão.)

- [ ] **Step 5: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=TicketAtendimentoKanbanIdTest`
Expected: PASS (2 testes).

- [ ] **Step 6: Rodar a suíte completa pra confirmar zero regressão**

Run: `php artisan test`
Expected: mesma contagem de falhas da baseline (17), nenhuma nova.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_08_000001_add_kanban_id_to_tickets_atendimento_table.php app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoKanbanIdTest.php
git commit -m "feat: adiciona kanban_id ao ticket, com backfill

Fundação pra suportar múltiplos Kanbans por tenant — hoje o ticket só
guardava o nome da coluna em texto, sem saber de qual Kanban era.
Backfill preenche com o Kanban tipo=vendas de cada tenant (hoje é
sempre o único, então é inequívoco).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 2: `KanbanColuna` — parâmetro `$kanbanId` opcional nos helpers estáticos

**Files:**
- Modify: `app/Models/KanbanColuna.php:63-128`
- Test: `tests/Feature/KanbanColunaHelpersTest.php` (adicionar casos novos, sem tocar nos existentes)

**Interfaces:**
- Consumes: nada de tasks anteriores (mudança isolada no model `KanbanColuna`).
- Produces: `KanbanColuna::doTenant(int $tenantId, ?int $kanbanId = null)`,
  `chaveDeEntrada(int $tenantId, ?int $kanbanId = null): string`,
  `chavesComPapel(int $tenantId, PapelColunaKanban $papel, ?int $kanbanId = null): array`,
  `primeiraChaveComPapel(int $tenantId, PapelColunaKanban $papel, ?int $kanbanId = null): ?string`,
  `proximaChave(int $tenantId, string $chaveAtual, ?int $kanbanId = null): ?string`,
  `papelDe(int $tenantId, string $chave, ?int $kanbanId = null): ?PapelColunaKanban`,
  `descricaoParaIa(int $tenantId, string $chave, ?int $kanbanId = null): string` — usados
  pela Task 3 em diante.

- [ ] **Step 1: Escrever os testes que ainda vão falhar**

Adicionar ao final de `tests/Feature/KanbanColunaHelpersTest.php` (antes do `}` final da
classe):

```php
    public function test_chave_de_entrada_com_kanban_id_resolve_corretamente_entre_2_kanbans(): void
    {
        $tenant = Tenant::factory()->create();
        $kanbanGeral = $this->criarColunasPadrao($tenant); // chave 'lead_novo', papel Entrada

        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste', 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanbanFunil->id,
            'chave' => 'funil_novo_lead', 'label' => 'Novo Lead', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $this->assertSame('lead_novo', KanbanColuna::chaveDeEntrada($tenant->id, $kanbanGeral->id));
        $this->assertSame('funil_novo_lead', KanbanColuna::chaveDeEntrada($tenant->id, $kanbanFunil->id));
    }

    public function test_chave_de_entrada_sem_kanban_id_continua_funcionando_com_1_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        // Comportamento de hoje, sem o parâmetro novo — não pode quebrar.
        $this->assertSame('lead_novo', KanbanColuna::chaveDeEntrada($tenant->id));
    }

    public function test_chave_de_entrada_com_kanban_id_de_outro_tenant_nao_vaza_coluna(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->criarColunasPadrao($tenantA);
        $kanbanDoB = $this->criarColunasPadrao($tenantB);

        // Pede a coluna de entrada do tenant A, mas passa o kanban_id do tenant B —
        // o filtro por tenant_id tem que prevalecer, não pode achar a coluna do B.
        $this->expectException(\RuntimeException::class);
        KanbanColuna::chaveDeEntrada($tenantA->id, $kanbanDoB->id);
    }
```

- [ ] **Step 2: Rodar os testes novos pra confirmar que falham**

Run: `php artisan test --filter=KanbanColunaHelpersTest`
Expected: os 3 testes novos FALHAM com `ArgumentCountError` (método ainda não aceita o 2º/3º
parâmetro) — os testes já existentes continuam passando.

- [ ] **Step 3: Implementar o parâmetro opcional em todos os helpers**

Substituir inteiramente o corpo de `app/Models/KanbanColuna.php` entre as linhas 63 e 128
(os métodos estáticos, sem tocar no resto do arquivo) por:

```php
    protected static function doTenant(int $tenantId, ?int $kanbanId = null): Collection
    {
        $query = static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId);

        if ($kanbanId !== null) {
            $query->where('kanban_id', $kanbanId);
        }

        return $query->orderBy('ordem')->get();
    }

    public static function chavesDoTenant(int $tenantId, ?int $kanbanId = null): array
    {
        return static::doTenant($tenantId, $kanbanId)->pluck('chave')->all();
    }

    public static function papelDe(int $tenantId, string $chave, ?int $kanbanId = null): ?PapelColunaKanban
    {
        return static::doTenant($tenantId, $kanbanId)->firstWhere('chave', $chave)?->papel;
    }

    public static function ordemDe(int $tenantId, string $chave, ?int $kanbanId = null): ?int
    {
        return static::doTenant($tenantId, $kanbanId)->firstWhere('chave', $chave)?->ordem;
    }

    public static function chaveDeEntrada(int $tenantId, ?int $kanbanId = null): string
    {
        $coluna = static::doTenant($tenantId, $kanbanId)->first(fn (self $c) => $c->papel === PapelColunaKanban::Entrada);

        if (! $coluna) {
            throw new \RuntimeException("Tenant {$tenantId} não tem nenhuma coluna de papel Entrada configurada.");
        }

        return $coluna->chave;
    }

    public static function chavesComPapel(int $tenantId, PapelColunaKanban $papel, ?int $kanbanId = null): array
    {
        return static::doTenant($tenantId, $kanbanId)
            ->filter(fn (self $c) => $c->papel === $papel)
            ->pluck('chave')
            ->values()
            ->all();
    }

    public static function primeiraChaveComPapel(int $tenantId, PapelColunaKanban $papel, ?int $kanbanId = null): ?string
    {
        return static::doTenant($tenantId, $kanbanId)->first(fn (self $c) => $c->papel === $papel)?->chave;
    }

    public static function proximaChave(int $tenantId, string $chaveAtual, ?int $kanbanId = null): ?string
    {
        $colunas = static::doTenant($tenantId, $kanbanId)->values();
        $indice = $colunas->search(fn (self $c) => $c->chave === $chaveAtual);

        if ($indice === false) {
            return null;
        }

        return $colunas->get($indice + 1)?->chave;
    }

    public static function descricaoParaIa(int $tenantId, string $chave, ?int $kanbanId = null): string
    {
        $coluna = static::doTenant($tenantId, $kanbanId)->firstWhere('chave', $chave);

        return $coluna ? "{$coluna->label} — {$coluna->papel->descricao()}" : $chave;
    }
```

- [ ] **Step 4: Rodar os testes pra confirmar que passam**

Run: `php artisan test --filter=KanbanColunaHelpersTest`
Expected: PASS — todos os testes, antigos e novos.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline de 17 falhas, nenhuma nova.

- [ ] **Step 6: Commit**

```bash
git add app/Models/KanbanColuna.php tests/Feature/KanbanColunaHelpersTest.php
git commit -m "feat: KanbanColuna aceita kanban_id opcional pra desambiguar entre múltiplos Kanbans

Parâmetro opcional em todos os helpers estáticos (doTenant,
chaveDeEntrada, chavesComPapel, etc.) — retrocompatível com as 10+
chamadas existentes que não passam esse parâmetro.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 3: `FormularioService` — grava `kanban_id` no ticket

**Files:**
- Modify: `app/Services/FormularioService.php:147-157`
- Test: `tests/Feature/FormularioServiceTest.php` (adicionar caso novo — confirmar que o
  arquivo existe; se o nome for outro, criar `tests/Feature/FormularioServiceKanbanIdTest.php`)

**Interfaces:**
- Consumes: `TicketAtendimento.kanban_id` (Task 1), `KanbanColuna::chaveDeEntrada($tenantId, $kanbanId)` (Task 2).

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\FormularioLead;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormularioServiceKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_criado_pelo_formulario_grava_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $formulario = \App\Models\Formulario::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        $resultado = app(\App\Services\FormularioService::class)->processar($formulario, [
            'nome' => 'Lead Teste',
            'telefone' => '21999999999',
        ]);

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
```

> Se o método público de `FormularioService` tiver outro nome/assinatura (ex.: `processarEnvio`,
> ou recebe `Request` em vez de array), ajustar a chamada no teste pro método real — conferir
> `app/Services/FormularioService.php` antes de rodar. O importante é testar o caminho que
> chega até a criação do ticket (linha 150).

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=FormularioServiceKanbanIdTest`
Expected: FAIL — `$ticket->kanban_id` é `null`.

- [ ] **Step 3: Implementar**

Em `app/Services/FormularioService.php`, linha 150, dentro do array passado pra
`TicketAtendimento::create([...])`, adicionar `'kanban_id' => $kanban?->id,` logo depois de
`'tenant_id' => $tenant->id,`, e trocar a linha do `coluna_kanban` (atual linha 154) de:

```php
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```

para:

```php
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id, $kanban?->id),
```

(`$kanban` já é resolvido 2 linhas antes do `create()`, linha 147 — não precisa buscar de novo.)

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=FormularioServiceKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline, zero regressão.

- [ ] **Step 6: Commit**

```bash
git add app/Services/FormularioService.php tests/Feature/FormularioServiceKanbanIdTest.php
git commit -m "feat: FormularioService grava kanban_id no ticket criado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 4: `Internal/TicketController` — grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Internal/TicketController.php:41-50`
- Test: `tests/Feature/InternalTicketControllerKanbanIdTest.php`

**Interfaces:**
- Consumes: mesmo de Task 3.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\Contato;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalTicketControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_interno_cria_ticket_com_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $contato = Contato::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/internal/tickets', [
            'tenant_id'  => $tenant->id,
            'contato_id' => $contato->id,
        ], ['Authorization' => 'Bearer ' . config('services.internal_api.token')]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->where('contato_id', $contato->id)->first();
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
```

> Conferir em `routes/` qual é a rota real e o mecanismo de autenticação deste controller
> interno (nome do header/token pode ser diferente — procurar por
> `Internal/TicketController` nas rotas antes de rodar).

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=InternalTicketControllerKanbanIdTest`
Expected: FAIL — `kanban_id` null.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Internal/TicketController.php`, linha 44-50, adicionar
`'kanban_id' => $kanban?->id,` logo depois de `'tenant_id' => $request->tenant_id,`, e trocar
a linha do `coluna_kanban` (linha 48) de:

```php
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id),
```

para:

```php
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id, $kanban?->id),
```

(`$kanban` já resolvido na linha 41, mesma lógica de FormularioService.)

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=InternalTicketControllerKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Internal/TicketController.php tests/Feature/InternalTicketControllerKanbanIdTest.php
git commit -m "feat: endpoint interno de criação de ticket grava kanban_id

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 5: `SecretariaEletronicaController` — grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Api/SecretariaEletronicaController.php:142-154`
- Test: `tests/Feature/SecretariaEletronicaKanbanIdTest.php`

**Interfaces:**
- Consumes: mesmo de Task 3.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecretariaEletronicaKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_chamada_perdida_cria_ticket_com_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->postJson('/api/secretaria-eletronica/chamada', [
            'tenant_id' => $tenant->id,
            'numero'    => '5521999999999',
        ]);

        $response->assertOk();
        $ticket = \App\Models\TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
```

> Conferir o payload/rota exatos olhando `tests/Feature/SecretariaEletronicaNumeroInvalidoTest.php`
> (já existe, cobre o mesmo controller) e espelhar o formato de request usado lá.

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=SecretariaEletronicaKanbanIdTest`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Api/SecretariaEletronicaController.php`, dentro do array do
`TicketAtendimento::create([...])` (linha ~146), adicionar `'kanban_id' => $kanban?->id,`
logo depois de `'tenant_id' => $tenant->id,`, e trocar a linha do `coluna_kanban` (linha 150) de:

```php
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```

para:

```php
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id, $kanban?->id),
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=SecretariaEletronicaKanbanIdTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/SecretariaEletronicaController.php tests/Feature/SecretariaEletronicaKanbanIdTest.php
git commit -m "feat: Secretária Eletrônica grava kanban_id no ticket criado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 6: `CovercutWebhookController` — resolve e grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php:278-294`
- Test: `tests/Feature/CovercutWebhookControllerTest.php` (adicionar caso novo ao arquivo existente)

**Interfaces:**
- Consumes: mesmo de Task 3.

- [ ] **Step 1: Escrever o teste**

Adicionar ao final da classe em `tests/Feature/CovercutWebhookControllerTest.php` (ler o
início do arquivo primeiro pra copiar o padrão exato de payload/fake usado nos testes
vizinhos, ex.: `test_mensagem_nova_cria_ticket` ou similar já existente):

```php
    public function test_mensagem_nova_cria_ticket_com_kanban_id(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = \App\Models\WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
        ]);

        $payload = $this->payloadMensagemTexto($canal, '5521988887777', 'Oi, tudo bem?');
        // ^ usar o helper/payload que os outros testes deste arquivo já usam pra montar o
        // corpo do webhook — procurar por "function payload" ou o array literal usado em
        // outro teste de "mensagem nova" neste mesmo arquivo e reaproveitar o formato exato.

        $this->postJson(route('webhook.covercut'), $payload)->assertOk();

        $ticket = \App\Models\TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
```

> Este arquivo de teste já é grande e tem helpers próprios de payload — abrir e copiar o
> formato de um teste existente de "mensagem nova cria ticket" em vez de inventar o payload
> do zero, pra não divergir do formato real esperado pelo controller.

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Webhook/CovercutWebhookController.php`, antes do bloco
`$ticket = TicketAtendimento::create([...])` (linha 281), adicionar a resolução do Kanban:

```php
                        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();

```

E no array de `create()` (linha 281-293), adicionar `'kanban_id' => $kanban?->id,` logo após
`'tenant_id' => $tenant->id,`, e trocar a linha 285 de:

```php
                            'coluna_kanban'         => KanbanColuna::chaveDeEntrada($tenant->id),
```

para:

```php
                            'coluna_kanban'         => KanbanColuna::chaveDeEntrada($tenant->id, $kanban?->id),
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline — importante aqui porque este é o controller mais testado do
repositório (vários arquivos de teste cobrem ele), conferir atentamente.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/CovercutWebhookController.php tests/Feature/CovercutWebhookControllerTest.php
git commit -m "feat: webhook Covercut grava kanban_id no ticket criado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 7: `UazapiWebhookController` — resolve e grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php:270-285`
- Test: `tests/Feature/UazapiWebhookControllerTest.php` (ou nome equivalente já existente —
  localizar com `find tests -iname "*Uazapi*"`)

**Interfaces:**
- Consumes: mesmo de Task 3.

- [ ] **Step 1: Escrever o teste**

Mesmo padrão da Task 6, adaptado pro formato de payload do Uazapi (conferir um teste
existente deste arquivo pra copiar o formato exato):

```php
    public function test_mensagem_nova_cria_ticket_com_kanban_id(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        // Montar o payload/canal seguindo o mesmo formato de um teste existente de
        // "mensagem nova" neste arquivo (canal Uazapi, provider='uazapi').

        $ticket = \App\Models\TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Webhook/UazapiWebhookController.php`, antes do
`TicketAtendimento::create([...])` (linha 275), adicionar:

```php
                        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();

```

No array de `create()`, adicionar `'kanban_id' => $kanban?->id,` após `'tenant_id'`, e trocar
a linha 279 de:

```php
                            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```

para:

```php
                            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id, $kanban?->id),
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php tests/Feature/UazapiWebhookControllerTest.php
git commit -m "feat: webhook Uazapi grava kanban_id no ticket criado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 8: `MessengerProprioWebhookController` — resolve e grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Webhook/MessengerProprioWebhookController.php:172-187`
- Test: arquivo de teste existente deste controller (localizar com
  `find tests -iname "*MessengerProprio*Webhook*"`)

**Interfaces:**
- Consumes: mesmo de Task 3.

- [ ] **Step 1: Escrever o teste**

Mesmo padrão das Tasks 6/7, adaptado pro formato deste webhook (copiar de um teste "mensagem
nova" já existente no arquivo).

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Webhook/MessengerProprioWebhookController.php`, antes do
`TicketAtendimento::create([...])` (linha 175), adicionar:

```php
                        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();

```

No array de `create()`, adicionar `'kanban_id' => $kanban?->id,` após `'tenant_id'`, e trocar
a linha 179 de:

```php
                            'coluna_kanban'      => KanbanColuna::chaveDeEntrada($tenant->id),
```

para:

```php
                            'coluna_kanban'      => KanbanColuna::chaveDeEntrada($tenant->id, $kanban?->id),
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=test_mensagem_nova_cria_ticket_com_kanban_id`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/MessengerProprioWebhookController.php
git commit -m "feat: webhook Messenger próprio grava kanban_id no ticket criado

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 9: `MetaWebhookController` — resolve coluna dinamicamente e grava `kanban_id`

**Files:**
- Modify: `app/Http/Controllers/Webhooks/MetaWebhookController.php:180-188`
- Test: arquivo de teste existente (localizar com `find tests -iname "*MetaWebhook*"`)

**Interfaces:**
- Consumes: mesmo de Task 3.

**Nota importante**: este controller hoje grava `'coluna_kanban' => 'novo_lead'` FIXO, sem
usar `chaveDeEntrada()` — isso já é um bug latente pra qualquer tenant cuja coluna de entrada
real tenha outra chave (ex.: Imóveis Caixa, que usa `'novo'`). Esta task corrige isso também,
não só adiciona `kanban_id`.

- [ ] **Step 1: Escrever o teste**

```php
    public function test_lead_meta_ads_cria_ticket_na_coluna_de_entrada_real_com_kanban_id(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        // Muda a coluna de entrada do tenant pra uma chave diferente de 'novo_lead',
        // exatamente o cenário que hoje quebra silenciosamente.
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)
            ->where('papel', \App\Enums\PapelColunaKanban::Entrada)
            ->update(['chave' => 'entrada_customizada']);

        // Montar o payload de lead ads seguindo o formato de um teste existente deste
        // arquivo (procurar por "function test_" que já cubra criação de ticket).

        $ticket = \App\Models\TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('entrada_customizada', $ticket->coluna_kanban);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=test_lead_meta_ads_cria_ticket_na_coluna_de_entrada_real_com_kanban_id`
Expected: FAIL — hoje grava `'novo_lead'` fixo, não `'entrada_customizada'`.

- [ ] **Step 3: Implementar**

Em `app/Http/Controllers/Webhooks/MetaWebhookController.php`, antes do bloco
`if (! $ticket) { $ticket = TicketAtendimento::create([...]); }` (linha 180), adicionar:

```php
            $kanban = \App\Models\Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->first();

```

E trocar o array de `create()` (linhas 181-187) de:

```php
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'contato_id'    => $contato->id,
                'coluna_kanban' => 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
```

para:

```php
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'kanban_id'     => $kanban?->id,
                'contato_id'    => $contato->id,
                'coluna_kanban' => $kanban ? \App\Models\KanbanColuna::chaveDeEntrada($tenantId, $kanban->id) : 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
```

(O fallback pro literal `'novo_lead'` só entra em jogo se o tenant não tiver Kanban
`tipo='vendas'` nenhum — caso defensivo que não deveria acontecer na prática.)

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=test_lead_meta_ads_cria_ticket_na_coluna_de_entrada_real_com_kanban_id`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline — atenção especial aqui porque mudou um comportamento (não é só
adição), conferir que nenhum teste existente dependia do literal `'novo_lead'` fixo.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhooks/MetaWebhookController.php
git commit -m "fix: MetaWebhookController usa a coluna de entrada real do tenant, não 'novo_lead' fixo

Bug latente: pra qualquer tenant cuja coluna de entrada não se chame
literalmente 'novo_lead' (ex.: Imóveis Caixa, que usa 'novo'), o ticket
criado por lead de anúncio Meta caía numa chave que não corresponde a
nenhuma coluna real. Agora resolve dinamicamente, igual todo o resto
do sistema, e já grava kanban_id também.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 10: `ProcessarCommentToDmJob` — resolve coluna dinamicamente e grava `kanban_id`

**Files:**
- Modify: `app/Jobs/ProcessarCommentToDmJob.php:165-173`
- Test: arquivo de teste existente (localizar com `find tests -iname "*CommentToDm*"`)

**Interfaces:**
- Consumes: mesmo de Task 3. Mesmo bug latente da Task 9 (literal `'novo_lead'` fixo).

- [ ] **Step 1: Escrever o teste**

```php
    public function test_comentario_vira_dm_cria_ticket_na_coluna_de_entrada_real_com_kanban_id(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)
            ->where('papel', \App\Enums\PapelColunaKanban::Entrada)
            ->update(['chave' => 'entrada_customizada']);

        // Disparar o job seguindo o formato de um teste existente deste arquivo.

        $ticket = \App\Models\TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('entrada_customizada', $ticket->coluna_kanban);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
```

- [ ] **Step 2: Rodar o teste pra confirmar que falha**

Run: `php artisan test --filter=test_comentario_vira_dm_cria_ticket_na_coluna_de_entrada_real_com_kanban_id`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `app/Jobs/ProcessarCommentToDmJob.php`, antes do bloco `if (! $ticket) { ... }` (linha
165), adicionar:

```php
            $kanban = \App\Models\Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->first();

```

E trocar o array de `create()` (linhas 167-173) de:

```php
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'contato_id'    => $contato->id,
                'coluna_kanban' => 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
```

para:

```php
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'kanban_id'     => $kanban?->id,
                'contato_id'    => $contato->id,
                'coluna_kanban' => $kanban ? \App\Models\KanbanColuna::chaveDeEntrada($tenantId, $kanban->id) : 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
```

- [ ] **Step 4: Rodar o teste pra confirmar que passa**

Run: `php artisan test --filter=test_comentario_vira_dm_cria_ticket_na_coluna_de_entrada_real_com_kanban_id`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma baseline.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessarCommentToDmJob.php
git commit -m "fix: ProcessarCommentToDmJob usa a coluna de entrada real do tenant, não 'novo_lead' fixo

Mesma correção da Task 9, aplicada ao job de comment-to-DM.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Task 11: Verificação final e deploy

**Files:** nenhum arquivo novo — só verificação.

- [ ] **Step 1: Rodar a suíte completa do zero**

Run: `php artisan test`
Expected: mesma baseline de 17 falhas pré-existentes, nenhuma nova, todos os testes das Tasks
1-10 passando.

- [ ] **Step 2: Conferir manualmente que os 8 pontos de criação de ticket foram cobertos**

```bash
grep -rn "TicketAtendimento::create(" app/ | wc -l
grep -rln "kanban_id" app/Http/Controllers/Webhook/ app/Http/Controllers/Webhooks/ app/Http/Controllers/Api/SecretariaEletronicaController.php app/Http/Controllers/Internal/TicketController.php app/Jobs/ProcessarCommentToDmJob.php app/Services/FormularioService.php
```

Expected: todos os 8 arquivos aparecem na segunda busca.

- [ ] **Step 3: Pedir confirmação explícita ao Leonardo antes do deploy**

Este plano mexe no fluxo de criação de ticket de TODOS os canais (WhatsApp oficial,
não-oficial, Messenger próprio, Meta Ads, comment-to-DM, formulário, secretária eletrônica,
endpoint interno) — pedir "pode fazer o deploy?" explicitamente antes do Step 4, mesmo que a
suíte esteja 100% verde, por ser mudança que toca tantos pontos de entrada de lead ao mesmo
tempo.

- [ ] **Step 4: Deploy**

```bash
git checkout main
git merge --no-ff <branch-usada-se-houver> -m "Merge: kanban_id no ticket + helpers KanbanColuna"
git push origin main
bash deploy.sh
```

- [ ] **Step 5: Verificar em produção**

```bash
ssh -i ~/.ssh/leadcerto_vps_nova root@31.97.172.203 "supervisorctl status"
```

Expected: workers `RUNNING`, sem erro. Depois, mandar uma mensagem de teste real pro WhatsApp
de um tenant de teste e confirmar no banco que o ticket criado tem `kanban_id` preenchido.
