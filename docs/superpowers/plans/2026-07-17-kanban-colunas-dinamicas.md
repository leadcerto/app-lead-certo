# Kanban — Colunas Dinâmicas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar o Kanban de Vendas do ENUM fixo do MySQL e permitir que cada franqueado crie, nomeie, reordene e exclua suas próprias colunas, preservando 100% do comportamento especial de hoje (auto-avanço, encerramento com reabertura por IA, transferência humana) através de um "papel" universal por coluna.

**Architecture:** Duas tabelas novas (`kanbans`, `kanban_colunas`) substituem o ENUM. `tickets_atendimento.coluna_kanban` continua string (chave da coluna), sem migração de dado histórico. Toda lógica que hoje compara a chave literal (`=== 'encerrado'`) passa a perguntar o **papel** da coluna (`entrada` / `em_andamento` / `encerramento` / `transferencia_humana`) através de um helper central cacheado por tenant (`App\Models\KanbanColuna`). Backfill não-destrutivo recria as 8 colunas de hoje para o tenant Frete.Rio; `TenantFactory` e `TenantSetupService` passam a semear a mesma estrutura padrão para tenants novos (produção e testes).

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8, PHPUnit (`RefreshDatabase`), Alpine.js v3 (frontend do Kanban).

## Global Constraints

- Nunca editar arquivos direto na VPS — fluxo é sempre `local → commit → ./deploy.sh` (ver CLAUDE.md do projeto). Este plano só cobre trabalho local; deploy é decisão separada do usuário ao final.
- Todos os models de tenant usam `TenantScope` como global scope (exceto `Contato`, que é global).
- Telefone e outras convenções do projeto não são tocados por este plano.
- Toda migration nova segue o padrão `YYYY_MM_DD_HHMMSS_descricao.php` já usado no projeto.
- Todo model com nome em snake_case que não bate com o padrão do Eloquent declara `$table` explicitamente.
- Spec de referência: `docs/superpowers/specs/2026-07-17-kanban-colunas-dinamicas-design.md` — qualquer dúvida de comportamento remete a esse documento.

---

## Mapa de arquivos (visão geral)

**Criados:**
- `database/migrations/2026_07_17_000001_create_kanbans_table.php`
- `database/migrations/2026_07_17_000002_create_kanban_colunas_table.php`
- `database/migrations/2026_07_17_000003_add_kanban_coluna_id_e_etapa_to_kanban_coluna_configs.php`
- `database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php`
- `database/migrations/2026_07_17_000005_widen_coluna_kanban_to_string_tickets_atendimento.php`
- `app/Enums/PapelColunaKanban.php`
- `app/Models/Kanban.php`
- `app/Models/KanbanColuna.php`
- `app/Http/Controllers/Painel/KanbanColunaController.php`
- `tests/Unit/PapelColunaKanbanTest.php`
- `tests/Feature/KanbanColunaModelsTest.php`
- `tests/Feature/KanbanColunaHelpersTest.php`
- `tests/Feature/KanbanColunasBackfillTest.php`
- `tests/Feature/TenantFactorySeedKanbanTest.php`
- `tests/Feature/TenantSetupServiceKanbanColunasTest.php`
- `tests/Feature/KanbanColunaControllerTest.php`
- `tests/Feature/SdrResponderServiceTokenDinamicoTest.php`

**Modificados:**
- `database/factories/TenantFactory.php`
- `app/Services/TenantSetupService.php`
- `app/Http/Controllers/Painel/KanbanController.php`
- `app/Http/Controllers/Painel/KanbanColunaConfigController.php`
- `app/Models/TicketAtendimento.php`
- `app/Http/Controllers/Webhook/UazapiWebhookController.php`
- `app/Console/Commands/FollowupConversas.php`
- `app/Services/GestorKanbanService.php`
- `app/Services/FormularioService.php`
- `app/Http/Controllers/Api/SecretariaEletronicaController.php`
- `app/Http/Controllers/Internal/TicketController.php`
- `app/Console/Commands/SincronizarContatosWhatsApp.php`
- `app/Jobs/SincronizarAgendaWhatsAppJob.php`
- `app/Console/Commands/ImportarParticipantesGrupos.php`
- `app/Services/AgendaImediataService.php`
- `app/Services/SdrResponderService.php`
- `app/Http/Controllers/Painel/SequenciaController.php`
- `app/Jobs/SequenciaMensagemJob.php`
- `app/Http/Controllers/Painel/ContatosController.php`
- `resources/views/kanban/index.blade.php`
- `resources/views/kanban/config.blade.php`
- `routes/web.php`

---

### Task 1: Enum `PapelColunaKanban`

**Files:**
- Create: `app/Enums/PapelColunaKanban.php`
- Test: `tests/Unit/PapelColunaKanbanTest.php`

**Interfaces:**
- Produces: `App\Enums\PapelColunaKanban` — backed string enum com casos `Entrada`, `EmAndamento`, `Encerramento`, `TransferenciaHumana`; métodos `label(): string`, `descricao(): string`, `objetivoExemplo(): string`, `promptExemplo(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Enums\PapelColunaKanban;
use PHPUnit\Framework\TestCase;

class PapelColunaKanbanTest extends TestCase
{
    public function test_valores_do_enum(): void
    {
        $this->assertSame('entrada', PapelColunaKanban::Entrada->value);
        $this->assertSame('em_andamento', PapelColunaKanban::EmAndamento->value);
        $this->assertSame('encerramento', PapelColunaKanban::Encerramento->value);
        $this->assertSame('transferencia_humana', PapelColunaKanban::TransferenciaHumana->value);
    }

    public function test_todo_papel_tem_label_descricao_e_objetivo_exemplo_nao_vazios(): void
    {
        foreach (PapelColunaKanban::cases() as $papel) {
            $this->assertNotSame('', $papel->label());
            $this->assertNotSame('', $papel->descricao());
            $this->assertNotSame('', $papel->objetivoExemplo());
        }
    }

    public function test_prompt_exemplo_de_transferencia_humana_e_vazio_pois_nao_usa_ia(): void
    {
        $this->assertSame('', PapelColunaKanban::TransferenciaHumana->promptExemplo());
    }

    public function test_prompt_exemplo_dos_demais_papeis_nao_e_vazio(): void
    {
        $this->assertNotSame('', PapelColunaKanban::Entrada->promptExemplo());
        $this->assertNotSame('', PapelColunaKanban::EmAndamento->promptExemplo());
        $this->assertNotSame('', PapelColunaKanban::Encerramento->promptExemplo());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/PapelColunaKanbanTest.php`
Expected: FAIL — `Class "App\Enums\PapelColunaKanban" not found`

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

enum PapelColunaKanban: string
{
    case Entrada = 'entrada';
    case EmAndamento = 'em_andamento';
    case Encerramento = 'encerramento';
    case TransferenciaHumana = 'transferencia_humana';

    public function label(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::EmAndamento => 'Em Andamento',
            self::Encerramento => 'Encerramento',
            self::TransferenciaHumana => 'Transferência Humana',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Entrada => 'Onde tickets novos chegam. Quando o lead responde pela primeira vez, o ticket avança sozinho pra próxima coluna. Só pode haver 1 por Kanban.',
            self::EmAndamento => 'Coluna neutra — nenhuma automação especial além do que você configurar (IA, sequência, botão rápido).',
            self::Encerramento => 'Marca o ticket como encerrado. Se o lead voltar a falar, a IA decide se reabre ou mantém encerrado.',
            self::TransferenciaHumana => 'Tira o ticket da automação e passa pro atendimento humano.',
        };
    }

    public function objetivoExemplo(): string
    {
        return match ($this) {
            self::Entrada => 'Ex: Capturar o interesse inicial, coletar nome e o que o lead precisa, e iniciar o relacionamento com simpatia.',
            self::EmAndamento => 'Ex: Aprofundar as informações necessárias e conduzir o lead para a próxima etapa do atendimento.',
            self::Encerramento => 'Ex: Registrar o motivo do encerramento, agradecer o contato e deixar a porta aberta para o futuro.',
            self::TransferenciaHumana => 'Ex: Conversa que precisa de atenção humana direta — sem automação de IA nesta coluna.',
        };
    }

    public function promptExemplo(): string
    {
        return match ($this) {
            self::Entrada => 'Ex: Você é o atendente da empresa. O lead acabou de entrar em contato. Colete as informações iniciais com simpatia, sem prometer preço ainda.',
            self::EmAndamento => 'Ex: O lead já demonstrou interesse. Aprofunde as informações necessárias e seja consultivo.',
            self::Encerramento => 'Ex: O atendimento foi encerrado. Agradeça o contato e, se o lead voltar a falar, avalie se é uma despedida ou um interesse real de retomar.',
            self::TransferenciaHumana => '',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/PapelColunaKanbanTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Enums/PapelColunaKanban.php tests/Unit/PapelColunaKanbanTest.php
git commit -m "feat(kanban): adiciona enum PapelColunaKanban"
```

---

### Task 2: Migrations `kanbans` e `kanban_colunas`

**Files:**
- Create: `database/migrations/2026_07_17_000001_create_kanbans_table.php`
- Create: `database/migrations/2026_07_17_000002_create_kanban_colunas_table.php`
- Test: `tests/Feature/KanbanColunaModelsTest.php` (parte 1 — schema)

**Interfaces:**
- Produces: tabelas `kanbans` (`id, tenant_id, tipo, nome, ordem, timestamps`) e `kanban_colunas` (`id, tenant_id, kanban_id, chave, label, emoji, papel, ordem, timestamps`, unique `[kanban_id, chave]`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KanbanColunaModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabelas_kanbans_e_kanban_colunas_existem_com_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('kanbans'));
        $this->assertTrue(Schema::hasColumns('kanbans', ['tenant_id', 'tipo', 'nome', 'ordem']));

        $this->assertTrue(Schema::hasTable('kanban_colunas'));
        $this->assertTrue(Schema::hasColumns('kanban_colunas', [
            'tenant_id', 'kanban_id', 'chave', 'label', 'emoji', 'papel', 'ordem',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaModelsTest.php`
Expected: FAIL — `Base table or view not found: kanbans`

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_07_17_000001_create_kanbans_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanbans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('tipo', 30)->default('vendas');
            $table->string('nome', 60);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanbans');
    }
};
```

`database/migrations/2026_07_17_000002_create_kanban_colunas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_colunas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('kanban_id')->constrained('kanbans')->cascadeOnDelete();
            $table->string('chave', 50);
            $table->string('label', 60);
            $table->string('emoji', 10)->nullable();
            $table->string('papel', 30);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['kanban_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_colunas');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaModelsTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_17_000001_create_kanbans_table.php \
        database/migrations/2026_07_17_000002_create_kanban_colunas_table.php \
        tests/Feature/KanbanColunaModelsTest.php
git commit -m "feat(kanban): cria tabelas kanbans e kanban_colunas"
```

---

### Task 3: Models `Kanban` e `KanbanColuna`

**Files:**
- Create: `app/Models/Kanban.php`
- Create: `app/Models/KanbanColuna.php`
- Modify: `tests/Feature/KanbanColunaModelsTest.php` (adiciona parte 2 — relacionamentos)

**Interfaces:**
- Consumes: `App\Enums\PapelColunaKanban` (Task 1), tabelas `kanbans`/`kanban_colunas` (Task 2).
- Produces: `App\Models\Kanban` (relação `colunas(): HasMany`), `App\Models\KanbanColuna` (relação `kanban(): BelongsTo`, cast `papel` → `PapelColunaKanban`).

- [ ] **Step 1: Write the failing test (adicionar ao arquivo existente)**

```php
    public function test_kanban_tem_colunas_ordenadas_e_coluna_pertence_ao_kanban(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $kanban = \App\Models\Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
        ]);

        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'b', 'label' => 'B', 'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 2,
        ]);
        $primeira = \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'a', 'label' => 'A', 'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $this->assertSame(['a', 'b'], $kanban->colunas->pluck('chave')->all());
        $this->assertTrue($primeira->kanban->is($kanban));
        $this->assertSame(\App\Enums\PapelColunaKanban::Entrada, $primeira->papel);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaModelsTest.php`
Expected: FAIL — `Class "App\Models\Kanban" not found`

- [ ] **Step 3: Write the models**

`app/Models/Kanban.php`:
```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kanban extends Model
{
    protected $table = 'kanbans';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'tipo',
        'nome',
        'ordem',
    ];

    protected $casts = [
        'ordem' => 'integer',
    ];

    public function colunas(): HasMany
    {
        return $this->hasMany(KanbanColuna::class)->orderBy('ordem');
    }
}
```

`app/Models/KanbanColuna.php`:
```php
<?php

namespace App\Models;

use App\Enums\PapelColunaKanban;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColuna extends Model
{
    protected $table = 'kanban_colunas';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'kanban_id',
        'chave',
        'label',
        'emoji',
        'papel',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'papel' => PapelColunaKanban::class,
            'ordem' => 'integer',
        ];
    }

    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaModelsTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Kanban.php app/Models/KanbanColuna.php tests/Feature/KanbanColunaModelsTest.php
git commit -m "feat(kanban): adiciona models Kanban e KanbanColuna"
```

---

### Task 4: Helpers estáticos cacheados em `KanbanColuna`

**Files:**
- Modify: `app/Models/KanbanColuna.php`
- Test: `tests/Feature/KanbanColunaHelpersTest.php`

**Interfaces:**
- Consumes: `App\Models\Kanban`, `App\Models\KanbanColuna` (Task 3), `App\Enums\PapelColunaKanban` (Task 1).
- Produces:
  - `KanbanColuna::chavesDoTenant(int $tenantId): array`
  - `KanbanColuna::papelDe(int $tenantId, string $chave): ?PapelColunaKanban`
  - `KanbanColuna::chaveDeEntrada(int $tenantId): string` (lança `\RuntimeException` se não houver)
  - `KanbanColuna::chavesComPapel(int $tenantId, PapelColunaKanban $papel): array`
  - `KanbanColuna::primeiraChaveComPapel(int $tenantId, PapelColunaKanban $papel): ?string`
  - `KanbanColuna::proximaChave(int $tenantId, string $chaveAtual): ?string`
  - `KanbanColuna::limparCache(int $tenantId): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KanbanColunaHelpersTest extends TestCase
{
    use RefreshDatabase;

    private function criarColunasPadrao(Tenant $tenant): Kanban
    {
        $kanban = Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);

        $defs = [
            ['chave' => 'lead_novo', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1],
            ['chave' => 'em_atendimento', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 2],
            ['chave' => 'encerrado', 'papel' => PapelColunaKanban::Encerramento, 'ordem' => 3],
            ['chave' => 'outros', 'papel' => PapelColunaKanban::TransferenciaHumana, 'ordem' => 4],
        ];

        foreach ($defs as $def) {
            KanbanColuna::create([
                'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
                'chave' => $def['chave'], 'label' => $def['chave'], 'papel' => $def['papel'], 'ordem' => $def['ordem'],
            ]);
        }

        return $kanban;
    }

    public function test_chaves_do_tenant_retorna_todas_ordenadas(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'encerrado', 'outros'],
            KanbanColuna::chavesDoTenant($tenant->id)
        );
    }

    public function test_papel_de_retorna_o_papel_correto_e_null_se_nao_existir(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(PapelColunaKanban::Encerramento, KanbanColuna::papelDe($tenant->id, 'encerrado'));
        $this->assertNull(KanbanColuna::papelDe($tenant->id, 'nao_existe'));
    }

    public function test_chave_de_entrada_retorna_a_unica_coluna_de_entrada(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame('lead_novo', KanbanColuna::chaveDeEntrada($tenant->id));
    }

    public function test_chave_de_entrada_lanca_excecao_se_nao_houver_coluna_de_entrada(): void
    {
        $tenant = Tenant::factory()->create();
        Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);

        $this->expectException(\RuntimeException::class);
        KanbanColuna::chaveDeEntrada($tenant->id);
    }

    public function test_chaves_com_papel_e_primeira_chave_com_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame(['outros'], KanbanColuna::chavesComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
        $this->assertSame('outros', KanbanColuna::primeiraChaveComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
    }

    public function test_primeira_chave_com_papel_retorna_null_quando_nenhuma_coluna_tem_esse_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'lead_novo', 'label' => 'lead_novo', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $this->assertNull(KanbanColuna::primeiraChaveComPapel($tenant->id, PapelColunaKanban::TransferenciaHumana));
    }

    public function test_proxima_chave_retorna_a_coluna_seguinte_por_ordem_ou_null_na_ultima(): void
    {
        $tenant = Tenant::factory()->create();
        $this->criarColunasPadrao($tenant);

        $this->assertSame('em_atendimento', KanbanColuna::proximaChave($tenant->id, 'lead_novo'));
        $this->assertNull(KanbanColuna::proximaChave($tenant->id, 'outros'));
        $this->assertNull(KanbanColuna::proximaChave($tenant->id, 'chave_que_nao_existe'));
    }

    public function test_cache_e_invalidado_ao_criar_editar_e_excluir_coluna(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = $this->criarColunasPadrao($tenant);

        $this->assertCount(4, KanbanColuna::chavesDoTenant($tenant->id));

        $nova = KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'nova', 'label' => 'Nova', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 5,
        ]);
        $this->assertCount(5, KanbanColuna::chavesDoTenant($tenant->id));

        $nova->update(['label' => 'Renomeada']);
        $this->assertCount(5, KanbanColuna::chavesDoTenant($tenant->id));

        $nova->delete();
        $this->assertCount(4, KanbanColuna::chavesDoTenant($tenant->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaHelpersTest.php`
Expected: FAIL — `Call to undefined method App\Models\KanbanColuna::chavesDoTenant()`

- [ ] **Step 3: Implement the helpers**

Adicionar em `app/Models/KanbanColuna.php` (mantendo o que já existe da Task 3):

```php
<?php

namespace App\Models;

use App\Enums\PapelColunaKanban;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class KanbanColuna extends Model
{
    protected $table = 'kanban_colunas';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::saved(fn (self $coluna) => static::limparCache($coluna->tenant_id));
        static::deleted(fn (self $coluna) => static::limparCache($coluna->tenant_id));
    }

    protected $fillable = [
        'tenant_id',
        'kanban_id',
        'chave',
        'label',
        'emoji',
        'papel',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'papel' => PapelColunaKanban::class,
            'ordem' => 'integer',
        ];
    }

    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }

    public static function limparCache(int $tenantId): void
    {
        Cache::forget("kanban_colunas:{$tenantId}");
    }

    /** @return Collection<int, self> */
    protected static function doTenant(int $tenantId): Collection
    {
        return Cache::remember("kanban_colunas:{$tenantId}", 3600, function () use ($tenantId) {
            return static::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->orderBy('ordem')
                ->get();
        });
    }

    public static function chavesDoTenant(int $tenantId): array
    {
        return static::doTenant($tenantId)->pluck('chave')->all();
    }

    public static function papelDe(int $tenantId, string $chave): ?PapelColunaKanban
    {
        return static::doTenant($tenantId)->firstWhere('chave', $chave)?->papel;
    }

    public static function chaveDeEntrada(int $tenantId): string
    {
        $coluna = static::doTenant($tenantId)->first(fn (self $c) => $c->papel === PapelColunaKanban::Entrada);

        if (! $coluna) {
            throw new \RuntimeException("Tenant {$tenantId} não tem nenhuma coluna de papel Entrada configurada.");
        }

        return $coluna->chave;
    }

    public static function chavesComPapel(int $tenantId, PapelColunaKanban $papel): array
    {
        return static::doTenant($tenantId)
            ->filter(fn (self $c) => $c->papel === $papel)
            ->pluck('chave')
            ->values()
            ->all();
    }

    public static function primeiraChaveComPapel(int $tenantId, PapelColunaKanban $papel): ?string
    {
        return static::doTenant($tenantId)->first(fn (self $c) => $c->papel === $papel)?->chave;
    }

    public static function proximaChave(int $tenantId, string $chaveAtual): ?string
    {
        $colunas = static::doTenant($tenantId)->values();
        $indice = $colunas->search(fn (self $c) => $c->chave === $chaveAtual);

        if ($indice === false) {
            return null;
        }

        return $colunas->get($indice + 1)?->chave;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaHelpersTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/KanbanColuna.php tests/Feature/KanbanColunaHelpersTest.php
git commit -m "feat(kanban): helpers cacheados de papel/chave em KanbanColuna"
```

---

### Task 5: Migration — `kanban_coluna_id` e `etapa_ia_ao_mover` em `kanban_coluna_configs`

**Files:**
- Create: `database/migrations/2026_07_17_000003_add_kanban_coluna_id_e_etapa_to_kanban_coluna_configs.php`
- Modify: `app/Models/KanbanColunaConfig.php`
- Test: `tests/Feature/KanbanColunaConfigFillableTest.php` (arquivo já existe — adicionar caso)

**Interfaces:**
- Produces: colunas `kanban_coluna_configs.kanban_coluna_id` (FK nullable) e `kanban_coluna_configs.etapa_ia_ao_mover` (string, default `'etapa_1'`).

- [ ] **Step 1: Write the failing test (adicionar ao arquivo existente)**

```php
    public function test_kanban_coluna_id_e_etapa_ia_ao_mover_sao_preenchiveis(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas']);
        $coluna = \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'lead_novo', 'label' => 'Novo',
            'papel' => \App\Enums\PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'kanban_coluna_id' => $coluna->id, 'etapa_ia_ao_mover' => 'handoff',
        ]);

        $this->assertSame($coluna->id, $config->fresh()->kanban_coluna_id);
        $this->assertSame('handoff', $config->fresh()->etapa_ia_ao_mover);
    }

    public function test_etapa_ia_ao_mover_tem_default_etapa_1(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
        ]);

        $this->assertSame('etapa_1', $config->fresh()->etapa_ia_ao_mover);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaConfigFillableTest.php`
Expected: FAIL — `Unknown column 'kanban_coluna_id'` (mass assignment silenciosamente ignorado, teste falha no assert)

- [ ] **Step 3: Write the migration and update the model**

`database/migrations/2026_07_17_000003_add_kanban_coluna_id_e_etapa_to_kanban_coluna_configs.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->foreignId('kanban_coluna_id')->nullable()->after('coluna_kanban')
                ->constrained('kanban_colunas')->nullOnDelete();
            $table->string('etapa_ia_ao_mover', 20)->default('etapa_1')->after('ia_contexto');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_coluna_id');
            $table->dropColumn('etapa_ia_ao_mover');
        });
    }
};
```

Em `app/Models/KanbanColunaConfig.php`, adicionar aos `$fillable`:
```php
    protected $fillable = [
        'tenant_id',
        'coluna_kanban',
        'kanban_coluna_id',
        'objetivo',
        'seq_objetivo',
        'ia_objetivo',
        'ia_contexto',
        'etapa_ia_ao_mover',
        'foco_analise_imagem',
        'ia_ativo',
        'sdr_delay_segundos',
        'followup_estagio1_segundos',
        'followup_estagio2_segundos',
        'followup_estagio3_segundos',
        'auto_mover_ativo',
        'auto_mover_coluna_destino',
        'auto_mover_segundos',
        'auto_mover_mensagem',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaConfigFillableTest.php`
Expected: PASS (todos os testes do arquivo, incluindo os 2 novos)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_17_000003_add_kanban_coluna_id_e_etapa_to_kanban_coluna_configs.php \
        app/Models/KanbanColunaConfig.php tests/Feature/KanbanColunaConfigFillableTest.php
git commit -m "feat(kanban): liga kanban_coluna_configs a kanban_colunas e adiciona etapa_ia_ao_mover"
```

---

### Task 6: `TenantFactory` semeia Kanban de Vendas padrão (não quebra testes existentes)

**Files:**
- Modify: `database/factories/TenantFactory.php`
- Test: `tests/Feature/TenantFactorySeedKanbanTest.php`

**Interfaces:**
- Consumes: `App\Models\Kanban`, `App\Models\KanbanColuna`, `App\Enums\PapelColunaKanban` (Tasks 1, 3).
- Produces: qualquer `Tenant::factory()->create()` já nasce com 1 Kanban `tipo=vendas` e as 8 colunas padrão (mesmas chaves de hoje: `lead_novo`, `em_atendimento`, `aguardando_orcamento`, `aguardando_lead`, `pagamento`, `servico_agendado`, `encerrado`, `outros`).

> **Por quê isso é necessário:** a partir da Task 11, `KanbanController::mover()` valida a coluna contra `KanbanColuna::chavesDoTenant()`. Sem essa seed automática no factory, todos os testes existentes que fazem `Tenant::factory()->create()` e depois usam `'coluna_kanban' => 'lead_novo'` quebrariam.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantFactorySeedKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_criado_via_factory_ja_tem_kanban_de_vendas_com_8_colunas_padrao(): void
    {
        $tenant = Tenant::factory()->create();

        $chaves = KanbanColuna::chavesDoTenant($tenant->id);

        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'],
            $chaves
        );
        $this->assertSame(PapelColunaKanban::Entrada, KanbanColuna::papelDe($tenant->id, 'lead_novo'));
        $this->assertSame(PapelColunaKanban::Encerramento, KanbanColuna::papelDe($tenant->id, 'encerrado'));
        $this->assertSame(PapelColunaKanban::TransferenciaHumana, KanbanColuna::papelDe($tenant->id, 'outros'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TenantFactorySeedKanbanTest.php`
Expected: FAIL — `chavesDoTenant()` retorna array vazio

- [ ] **Step 3: Update the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'nome'  => $this->faker->company(),
            'nicho' => 'frete',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            $kanban = Kanban::create([
                'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
            ]);

            foreach (self::colunasPadrao() as $def) {
                KanbanColuna::create([
                    'tenant_id' => $tenant->id,
                    'kanban_id' => $kanban->id,
                    'chave'     => $def['chave'],
                    'label'     => $def['label'],
                    'emoji'     => $def['emoji'],
                    'papel'     => $def['papel'],
                    'ordem'     => $def['ordem'],
                ]);
            }
        });
    }

    /** @return array<int, array{chave: string, label: string, emoji: string, papel: PapelColunaKanban, ordem: int}> */
    public static function colunasPadrao(): array
    {
        return [
            ['chave' => 'lead_novo',            'label' => 'Novo',                 'emoji' => '🟢', 'papel' => PapelColunaKanban::Entrada,            'ordem' => 1],
            ['chave' => 'em_atendimento',       'label' => 'Em Atendimento',       'emoji' => '🔵', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 2],
            ['chave' => 'aguardando_orcamento', 'label' => 'Aguardando Orçamento', 'emoji' => '🟡', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 3],
            ['chave' => 'aguardando_lead',      'label' => 'Aguardando Lead',      'emoji' => '🟠', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 4],
            ['chave' => 'pagamento',            'label' => 'Pagamento',            'emoji' => '💳', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 5],
            ['chave' => 'servico_agendado',     'label' => 'Serviço Agendado',     'emoji' => '📅', 'papel' => PapelColunaKanban::EmAndamento,         'ordem' => 6],
            ['chave' => 'encerrado',            'label' => 'Encerrado',            'emoji' => '⚫', 'papel' => PapelColunaKanban::Encerramento,        'ordem' => 7],
            ['chave' => 'outros',               'label' => 'Outros / Internos',    'emoji' => '👤', 'papel' => PapelColunaKanban::TransferenciaHumana, 'ordem' => 8],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/TenantFactorySeedKanbanTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run the FULL suite to confirm nothing broke**

Run: `php artisan test`
Expected: mesma taxa de sucesso de antes (nenhuma regressão introduzida pela seed automática) — qualquer falha nova aqui indica um teste que criava tenant e coluna com `firstOrCreate`/nomes conflitantes; investigar antes de prosseguir.

- [ ] **Step 6: Commit**

```bash
git add database/factories/TenantFactory.php tests/Feature/TenantFactorySeedKanbanTest.php
git commit -m "feat(kanban): TenantFactory semeia Kanban de Vendas padrão"
```

---

### Task 7: `TenantSetupService` cria as colunas via `KanbanColuna` (produção)

**Files:**
- Modify: `app/Services/TenantSetupService.php`
- Test: `tests/Feature/TenantSetupServiceKanbanColunasTest.php`

**Interfaces:**
- Consumes: `Database\Factories\TenantFactory::colunasPadrao()` (Task 6) — reaproveitado para não duplicar a definição das 8 colunas entre factory e serviço de produção.
- Produces: `TenantSetupService::configurar()` cria o Kanban de Vendas + colunas (se ainda não existirem, idempotente) e liga cada `KanbanColunaConfig` ao `kanban_coluna_id` correspondente, preenchendo `etapa_ia_ao_mover` com os valores de hoje.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Services\TenantSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSetupServiceKanbanColunasTest extends TestCase
{
    use RefreshDatabase;

    public function test_configurar_liga_cada_config_ao_kanban_coluna_id_e_preenche_etapa_ia_ao_mover(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantSetupService::class)->configurar($tenant);

        $colunaAguardandoOrcamento = KanbanColuna::where('tenant_id', $tenant->id)
            ->where('chave', 'aguardando_orcamento')->firstOrFail();
        $configAguardandoOrcamento = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'aguardando_orcamento')->firstOrFail();

        $this->assertSame($colunaAguardandoOrcamento->id, $configAguardandoOrcamento->kanban_coluna_id);
        $this->assertSame('handoff', $configAguardandoOrcamento->etapa_ia_ao_mover);

        $configLeadNovo = KanbanColunaConfig::where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'lead_novo')->firstOrFail();
        $this->assertSame('etapa_1', $configLeadNovo->etapa_ia_ao_mover);
    }

    public function test_configurar_e_idempotente_rodando_duas_vezes(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantSetupService::class)->configurar($tenant);
        app(TenantSetupService::class)->configurar($tenant);

        $this->assertCount(8, KanbanColuna::where('tenant_id', $tenant->id)->get());
        $this->assertCount(7, KanbanColunaConfig::where('tenant_id', $tenant->id)->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TenantSetupServiceKanbanColunasTest.php`
Expected: FAIL — `kanban_coluna_id` fica `null` (serviço ainda não faz o link)

- [ ] **Step 3: Update `TenantSetupService::criarColunasKanban()`**

Substituir o método `criarColunasKanban` em `app/Services/TenantSetupService.php` (mantendo todos os métodos de prompt já existentes, sem alterá-los):

```php
    private function criarColunasKanban(Tenant $tenant): void
    {
        $empresa = $tenant->nome ?? 'a empresa';

        $kanban = \App\Models\Kanban::firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo' => 'vendas'],
            ['nome' => 'Vendas', 'ordem' => 0]
        );

        $colunasCriadas = [];
        foreach (\Database\Factories\TenantFactory::colunasPadrao() as $def) {
            $colunasCriadas[$def['chave']] = \App\Models\KanbanColuna::firstOrCreate(
                ['kanban_id' => $kanban->id, 'chave' => $def['chave']],
                [
                    'tenant_id' => $tenant->id,
                    'label'     => $def['label'],
                    'emoji'     => $def['emoji'],
                    'papel'     => $def['papel'],
                    'ordem'     => $def['ordem'],
                ]
            );
        }

        $configs = [
            'lead_novo'            => ['ativo' => true,  'prompt' => $this->promptLeadNovo(),                      'etapa' => 'etapa_1'],
            'em_atendimento'       => ['ativo' => true,  'prompt' => $this->promptEmAtendimento($empresa),          'etapa' => 'etapa_1'],
            'aguardando_orcamento' => ['ativo' => false, 'prompt' => $this->promptAguardandoOrcamento($empresa),    'etapa' => 'handoff'],
            'aguardando_lead'      => ['ativo' => true,  'prompt' => $this->promptAguardandoLead($empresa),         'etapa' => 'etapa_1'],
            'pagamento'            => ['ativo' => true,  'prompt' => $this->promptPagamento($empresa),              'etapa' => 'etapa_1'],
            'servico_agendado'     => ['ativo' => true,  'prompt' => $this->promptServicoAgendado($empresa),        'etapa' => 'handoff'],
            'encerrado'            => ['ativo' => true,  'prompt' => $this->promptEncerrado($empresa),              'etapa' => 'handoff'],
        ];

        foreach ($configs as $coluna => $cfg) {
            KanbanColunaConfig::firstOrCreate(
                ['tenant_id' => $tenant->id, 'coluna_kanban' => $coluna],
                [
                    'kanban_coluna_id'  => $colunasCriadas[$coluna]->id,
                    'ia_ativo'          => $cfg['ativo'],
                    'ia_contexto'       => $cfg['prompt'],
                    'etapa_ia_ao_mover' => $cfg['etapa'],
                ]
            );
        }
    }
```

Adicionar os `use` no topo do arquivo (se ainda não existirem): `use App\Models\Kanban;` e `use App\Models\KanbanColuna;` (ou manter `\App\Models\...` totalmente qualificado como acima, sem novo `use` — ambas as formas funcionam; o código acima já usa FQN pra não exigir edição da lista de `use`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/TenantSetupServiceKanbanColunasTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/TenantSetupService.php tests/Feature/TenantSetupServiceKanbanColunasTest.php
git commit -m "feat(kanban): TenantSetupService liga configs ao Kanban de Vendas e etapa_ia_ao_mover"
```

---

### Task 8: Migration de backfill (tenants existentes) + widen do ENUM

**Files:**
- Create: `database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php`
- Create: `database/migrations/2026_07_17_000005_widen_coluna_kanban_to_string_tickets_atendimento.php`
- Test: `tests/Feature/KanbanColunasBackfillTest.php`

**Interfaces:**
- Produces: qualquer tenant que já existia ANTES desta migration ganha 1 `kanbans` + 8 `kanban_colunas` + `kanban_coluna_configs` existentes ligados por `kanban_coluna_id`, sem perder nenhum dado. `tickets_atendimento.coluna_kanban` deixa de ser ENUM MySQL.

> Migrations são fotografias congeladas no tempo — por isso a definição das 8 colunas é duplicada aqui em SQL puro (`DB::table`), em vez de reaproveitar `TenantFactory::colunasPadrao()`. Isso é intencional: se o array da factory mudar no futuro, esta migration continua reproduzindo exatamente o estado de 2026-07-17.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KanbanColunasBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_recria_as_8_colunas_e_liga_configs_existentes_sem_perder_dado(): void
    {
        // Simula um tenant "antigo": cria via factory (que já semeia colunas — Task 6),
        // então apaga a estrutura nova pra simular o estado ANTES desta migration,
        // deixando só o config com conteúdo customizado (como estaria em produção).
        $tenant = Tenant::factory()->create();
        KanbanColuna::where('tenant_id', $tenant->id)->delete();
        DB::table('kanbans')->where('tenant_id', $tenant->id)->delete();

        KanbanColunaConfig::where('tenant_id', $tenant->id)->delete();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_orcamento',
            'ia_contexto' => 'PROMPT CUSTOMIZADO PELO FRANQUEADO — NÃO PODE SER SOBRESCRITO',
            'ia_ativo' => true,
        ]);

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php', '--realpath' => false])
            ->run();

        $chaves = KanbanColuna::chavesDoTenant($tenant->id);
        $this->assertSame(
            ['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros'],
            $chaves
        );

        $colunaAguardandoOrcamento = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'aguardando_orcamento')->firstOrFail();
        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')->firstOrFail();

        $this->assertSame($colunaAguardandoOrcamento->id, $config->kanban_coluna_id);
        $this->assertSame('PROMPT CUSTOMIZADO PELO FRANQUEADO — NÃO PODE SER SOBRESCRITO', $config->ia_contexto);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunasBackfillTest.php`
Expected: FAIL — arquivo de migration não existe ainda

- [ ] **Step 3: Write the backfill migration and the widen migration**

`database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUNAS = [
        ['chave' => 'lead_novo',            'label' => 'Novo',                 'emoji' => '🟢', 'papel' => 'entrada',              'ordem' => 1],
        ['chave' => 'em_atendimento',       'label' => 'Em Atendimento',       'emoji' => '🔵', 'papel' => 'em_andamento',         'ordem' => 2],
        ['chave' => 'aguardando_orcamento', 'label' => 'Aguardando Orçamento', 'emoji' => '🟡', 'papel' => 'em_andamento',         'ordem' => 3],
        ['chave' => 'aguardando_lead',      'label' => 'Aguardando Lead',      'emoji' => '🟠', 'papel' => 'em_andamento',         'ordem' => 4],
        ['chave' => 'pagamento',            'label' => 'Pagamento',            'emoji' => '💳', 'papel' => 'em_andamento',         'ordem' => 5],
        ['chave' => 'servico_agendado',     'label' => 'Serviço Agendado',     'emoji' => '📅', 'papel' => 'em_andamento',         'ordem' => 6],
        ['chave' => 'encerrado',            'label' => 'Encerrado',            'emoji' => '⚫', 'papel' => 'encerramento',         'ordem' => 7],
        ['chave' => 'outros',               'label' => 'Outros / Internos',    'emoji' => '👤', 'papel' => 'transferencia_humana', 'ordem' => 8],
    ];

    private const ETAPA_POR_CHAVE = [
        'aguardando_orcamento' => 'handoff',
        'servico_agendado'     => 'handoff',
        'encerrado'            => 'handoff',
    ];

    public function up(): void
    {
        $tenants = DB::table('tenants')->get(['id']);

        foreach ($tenants as $tenant) {
            $kanbanId = DB::table('kanbans')->where('tenant_id', $tenant->id)->where('tipo', 'vendas')->value('id');

            if (! $kanbanId) {
                $kanbanId = DB::table('kanbans')->insertGetId([
                    'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach (self::COLUNAS as $def) {
                $colunaId = DB::table('kanban_colunas')
                    ->where('kanban_id', $kanbanId)->where('chave', $def['chave'])->value('id');

                if (! $colunaId) {
                    $colunaId = DB::table('kanban_colunas')->insertGetId([
                        'tenant_id' => $tenant->id, 'kanban_id' => $kanbanId,
                        'chave' => $def['chave'], 'label' => $def['label'], 'emoji' => $def['emoji'],
                        'papel' => $def['papel'], 'ordem' => $def['ordem'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                DB::table('kanban_coluna_configs')
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $def['chave'])
                    ->whereNull('kanban_coluna_id')
                    ->update([
                        'kanban_coluna_id'  => $colunaId,
                        'etapa_ia_ao_mover' => self::ETAPA_POR_CHAVE[$def['chave']] ?? 'etapa_1',
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Backfill não-destrutivo — down() intencionalmente não remove kanbans/kanban_colunas
        // criados, pra não arriscar apagar dado que passou a ser referenciado por tickets.
    }
};
```

`database/migrations/2026_07_17_000005_widen_coluna_kanban_to_string_tickets_atendimento.php`:

> **Nota de portabilidade (achada ao planejar a Task 8):** `ALTER TABLE ... MODIFY` é sintaxe exclusiva do MySQL/MariaDB — a suíte de testes automatizados roda em SQLite (`phpunit.xml`), onde esse comando não existe. O projeto já tem um padrão estabelecido pra isso em `database/migrations/2026_07_09_174657_add_pagamento_to_tickets_coluna_kanban.php` e `2026_07_07_000003_add_servico_agendado_to_tickets_coluna.php`: checar `DB::getDriverName()` e usar o schema builder nativo do Laravel (`->change()`, que recria a tabela sob SQLite) como caminho alternativo. Seguir o mesmo padrão aqui.

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
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->string('coluna_kanban', 50)->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban VARCHAR(50) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('tickets_atendimento', function (Blueprint $table) {
                $table->enum('coluna_kanban', [
                    'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
                    'pagamento', 'servico_agendado', 'encerrado', 'outros',
                ])->default('lead_novo')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE tickets_atendimento MODIFY coluna_kanban ENUM(
            'lead_novo','em_atendimento','aguardando_orcamento','aguardando_lead',
            'pagamento','servico_agendado','encerrado','outros'
        ) NOT NULL");
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunasBackfillTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run the FULL suite**

Run: `php artisan test`
Expected: mesma taxa de sucesso de antes — o widen do ENUM não deve quebrar nada (só amplia o que já era aceito)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_17_000004_backfill_kanbans_e_kanban_colunas.php \
        database/migrations/2026_07_17_000005_widen_coluna_kanban_to_string_tickets_atendimento.php \
        tests/Feature/KanbanColunasBackfillTest.php
git commit -m "feat(kanban): backfill não-destrutivo dos tenants existentes + coluna_kanban vira string"
```

---

### Task 9: `KanbanController::index()` e `mover()` dinâmicos

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php:37-90` (index), `:336-360` (mover), `:425-436` (moverParaOutros)
- Test: `tests/Feature/KanbanControllerMoverTest.php` (arquivo já existe — adicionar casos), `tests/Feature/KanbanControllerColunasDinamicasTest.php` (novo)

**Interfaces:**
- Consumes: `KanbanColuna::chavesDoTenant()`, `KanbanColuna::primeiraChaveComPapel()` (Task 4).
- Produces: `KanbanController::index()` responde com `label`/`emoji`/`papel` por coluna (não mais só a chave), pro frontend (Task 15) parar de hardcodar.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerColunasDinamicasTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_retorna_colunas_do_tenant_com_label_emoji_e_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/tickets');

        $response->assertOk();
        $colunas = collect($response->json('colunas'));
        $this->assertSame('lead_novo', $colunas->first()['chave']);
        $this->assertSame('Novo', $colunas->first()['label']);
        $this->assertSame('entrada', $colunas->first()['papel']);
    }

    public function test_mover_aceita_coluna_customizada_criada_pelo_franqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'coluna_customizada', 'label' => 'Minha Coluna', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mover", [
            'coluna' => 'coluna_customizada',
        ]);

        $response->assertOk();
        $this->assertSame('coluna_customizada', $ticket->fresh()->coluna_kanban);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanControllerColunasDinamicasTest.php`
Expected: FAIL — `index()` não retorna chave `colunas` no JSON; `mover()` rejeita `coluna_customizada` (Rule::in fixo)

- [ ] **Step 3: Update `KanbanController`**

Em `app/Http/Controllers/Painel/KanbanController.php`, trocar a linha 39 (`$colunas = [...]` fixo) e o retorno do `index()`:

```php
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $colunas  = \App\Models\KanbanColuna::chavesDoTenant($tenantId);

        $todosTickets = collect();
        $totais       = [];

        // ... (todo o corpo do método continua igual, usando $colunas normalmente) ...
```

No final do método (onde hoje o `index()` monta o array de retorno — localizar o `return response()->json([...])` existente), adicionar a chave `colunas` com metadado completo:

```php
        return response()->json([
            'colunas' => \App\Models\KanbanColuna::query()
                ->whereIn('chave', $colunas)
                ->orderBy('ordem')
                ->get(['chave', 'label', 'emoji', 'papel'])
                ->map(fn ($c) => [
                    'chave' => $c->chave,
                    'label' => $c->label,
                    'emoji' => $c->emoji,
                    'papel' => $c->papel->value,
                ]),
            'tickets' => $todosTickets,
            'totais'  => $totais,
            // ... demais chaves que o método já retorna hoje, sem alterar ...
        ]);
    }
```

Trocar a linha 338 (`$colunas` fixo dentro de `mover()`):

```php
    public function mover(Request $request, int $ticket): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $colunas  = \App\Models\KanbanColuna::chavesDoTenant($tenantId);

        $request->validate([
            'coluna' => ['required', 'string', Rule::in($colunas)],
        ]);
        // ... resto do método sem alteração ...
```

Trocar `moverParaOutros()` (linha ~430) pra usar a primeira coluna de papel Transferência Humana:

```php
    public function moverParaOutros(Request $request, int $ticket): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $colunaOutros = \App\Models\KanbanColuna::primeiraChaveComPapel($tenantId, \App\Enums\PapelColunaKanban::TransferenciaHumana);

        if (! $colunaOutros) {
            return response()->json(['message' => 'Nenhuma coluna de Transferência Humana configurada.'], 422);
        }

        $model = TicketAtendimento::findOrFail($ticket);

        $model->update([
            'coluna_kanban'      => $colunaOutros,
            'agente_responsavel' => 'humano',
            'vendedor_id'        => $request->user()->id,
        ]);

        return response()->json(['ticket_id' => $ticket, 'coluna_kanban' => $colunaOutros]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanControllerColunasDinamicasTest.php tests/Feature/KanbanControllerMoverTest.php`
Expected: PASS (todos os testes, incluindo os já existentes de `KanbanControllerMoverTest`, que continuam passando porque `lead_novo`/`em_atendimento`/`encerrado` seguem existindo via seed do factory)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanControllerColunasDinamicasTest.php
git commit -m "feat(kanban): index() e mover() usam colunas dinâmicas do tenant"
```

---

### Task 10: `KanbanColunaConfigController` — `Rule::in` dinâmico

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php:51-55`
- Test: `tests/Feature/KanbanColunaConfigAutoMoverTest.php` (arquivo já existe — adicionar caso)

**Interfaces:**
- Consumes: `KanbanColuna::chavesDoTenant()` (Task 4).

- [ ] **Step 1: Write the failing test (adicionar ao arquivo existente)**

```php
    public function test_auto_mover_coluna_destino_aceita_coluna_customizada(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'coluna_customizada', 'label' => 'Minha Coluna',
            'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'auto_mover_ativo'          => true,
            'auto_mover_coluna_destino' => 'coluna_customizada',
            'auto_mover_segundos'       => 3600,
        ]);

        $response->assertOk();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaConfigAutoMoverTest.php`
Expected: FAIL — 422, `coluna_customizada` rejeitada pelo `Rule::in` fixo

- [ ] **Step 3: Update the controller**

Em `app/Http/Controllers/Painel/KanbanColunaConfigController.php`, trocar:

```php
                Rule::in(['lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead', 'pagamento', 'servico_agendado', 'encerrado', 'outros']),
```

por:

```php
                Rule::in(\App\Models\KanbanColuna::chavesDoTenant($request->user()->tenant_id)),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaConfigAutoMoverTest.php`
Expected: PASS (todos os testes do arquivo)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php tests/Feature/KanbanColunaConfigAutoMoverTest.php
git commit -m "feat(kanban): auto_mover_coluna_destino valida contra colunas dinâmicas do tenant"
```

---

### Task 11: `TicketAtendimento::dadosParaEncerrar()` aceita destino configurável

**Files:**
- Modify: `app/Models/TicketAtendimento.php:116-128`
- Test: novo teste unitário dentro de `tests/Feature/KanbanColunaHelpersTest.php` (ou arquivo próprio — usar `tests/Feature/TicketAtendimentoDadosParaEncerrarTest.php`)

**Interfaces:**
- Consumes: `KanbanColuna::primeiraChaveComPapel()` (Task 4).
- Produces: `dadosParaEncerrar(array $extra = [], ?string $colunaDestino = null): array` — se `$colunaDestino` não for informado, usa a primeira coluna de papel Encerramento do tenant do próprio ticket.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoDadosParaEncerrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_dados_para_encerrar_usa_a_coluna_de_papel_encerramento_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $dados = $ticket->dadosParaEncerrar();

        $this->assertSame('encerrado', $dados['coluna_kanban']);
        $this->assertSame('encerrado', $dados['status']);
        $this->assertSame('em_atendimento', $dados['coluna_antes_encerrar']);
    }

    public function test_dados_para_encerrar_aceita_coluna_destino_explicita_quando_ha_mais_de_uma_de_encerramento(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'perdido', 'label' => 'Perdido', 'papel' => PapelColunaKanban::Encerramento, 'ordem' => 99,
        ]);
        $contato = Contato::factory()->create();
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $dados = $ticket->dadosParaEncerrar([], 'perdido');

        $this->assertSame('perdido', $dados['coluna_kanban']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TicketAtendimentoDadosParaEncerrarTest.php`
Expected: FAIL — `coluna_kanban` sempre `'encerrado'` hardcoded (2º teste falha)

- [ ] **Step 3: Update `dadosParaEncerrar()`**

```php
    public function dadosParaEncerrar(array $extra = [], ?string $colunaDestino = null): array
    {
        $colunaDestino ??= \App\Models\KanbanColuna::primeiraChaveComPapel($this->tenant_id, \App\Enums\PapelColunaKanban::Encerramento)
            ?? 'encerrado';

        $updates = array_merge($extra, [
            'coluna_kanban' => $colunaDestino,
            'status'        => 'encerrado',
        ]);

        if (\App\Models\KanbanColuna::papelDe($this->tenant_id, $this->coluna_kanban) !== \App\Enums\PapelColunaKanban::Encerramento) {
            $updates['coluna_antes_encerrar'] = $this->coluna_kanban;
        }

        return $updates;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/TicketAtendimentoDadosParaEncerrarTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run regression on existing encerramento tests**

Run: `php artisan test tests/Feature/KanbanControllerMoverTest.php tests/Feature/KanbanTicketShowTest.php`
Expected: PASS (nenhuma quebra — `chaveDeEntrada`/comportamento padrão preservado)

- [ ] **Step 6: Commit**

```bash
git add app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoDadosParaEncerrarTest.php
git commit -m "feat(kanban): dadosParaEncerrar() usa papel de Encerramento em vez de chave fixa"
```

---

### Task 12: `UazapiWebhookController` — entrada e auto-avanço dinâmicos

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php:220` (criação em `processarMensagemLead`), `:319-330` (auto-avanço), `:374` (criação em `processarChamadaWhatsApp`)
- Test: `tests/Feature/UazapiWebhookColunaDinamicaTest.php` (novo)

**Interfaces:**
- Consumes: `KanbanColuna::chaveDeEntrada()`, `KanbanColuna::proximaChave()` (Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookColunaDinamicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_novo_criado_pelo_webhook_usa_a_coluna_de_entrada_do_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'token-teste']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        // Franqueado renomeou a coluna de Entrada de 'lead_novo' para 'novo_contato'
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $response = $this->postJson('/api/webhook/uazapi/token-teste', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511999999999@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('novo_contato', $ticket->coluna_kanban);
    }

    public function test_lead_responde_pela_primeira_vez_avanca_para_a_proxima_coluna_por_ordem(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'token-teste-2',
            'uazapi_instance_token' => 'instance-token-2',
        ]);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $contato = \App\Models\Contato::factory()->create(['telefone' => '5511988884444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo_contato', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);
        \App\Models\Mensagem::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi! Me conta o que precisa.', 'enviado_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/token-teste-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511988884444@s.whatsapp.net',
                'text'    => 'Preciso de um orçamento de mudança',
            ],
        ]);

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/UazapiWebhookColunaDinamicaTest.php`
Expected: FAIL — 1º teste: ticket criado com `coluna_kanban = 'lead_novo'` (hardcoded), não `'novo_contato'`; 2º teste: `$ticket->coluna_kanban === 'lead_novo'` nunca é `true` (o ticket está em `'novo_contato'`), então o ticket nunca avança

- [ ] **Step 3: Update the controller**

Em `processarMensagemLead()`, linha 220 (criação do ticket novo):
```php
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```
(substituindo `'coluna_kanban' => 'lead_novo'`).

Nas linhas 318-330 (bloco `else` de ticket existente), trocar:
```php
            // Lead respondeu em ticket existente
            if ($ticket->coluna_kanban === 'lead_novo' && $conteudo) {
                // Lead respondeu à sequência → avança para em_atendimento e dispara SDR
                $temMensagemBot = Mensagem::where('ticket_id', $ticket->id)
                    ->where('remetente', 'bot')
                    ->exists();
                if ($temMensagemBot) {
                    $ticket->update(['coluna_kanban' => 'em_atendimento']);
                    $ticket->coluna_kanban = 'em_atendimento';
                    $delay = $this->sdrDelay($tenant->id, 'em_atendimento');
                    dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, $delay))
                        ->delay(now()->addSeconds($delay));
                }
            } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
```
por:
```php
            // Lead respondeu em ticket existente
            $chaveEntrada = \App\Models\KanbanColuna::chaveDeEntrada($tenant->id);
            if ($ticket->coluna_kanban === $chaveEntrada && $conteudo) {
                // Lead respondeu à sequência → avança para a próxima coluna e dispara SDR
                $temMensagemBot = Mensagem::where('ticket_id', $ticket->id)
                    ->where('remetente', 'bot')
                    ->exists();
                $proximaColuna = \App\Models\KanbanColuna::proximaChave($tenant->id, $chaveEntrada);
                if ($temMensagemBot && $proximaColuna) {
                    $ticket->update(['coluna_kanban' => $proximaColuna]);
                    $ticket->coluna_kanban = $proximaColuna;
                    $delay = $this->sdrDelay($tenant->id, $proximaColuna);
                    dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, $delay))
                        ->delay(now()->addSeconds($delay));
                }
            } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
```

Em `processarChamadaWhatsApp()`, linha 374 (criação do ticket):
```php
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```
(substituindo `'coluna_kanban' => 'lead_novo'`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/UazapiWebhookColunaDinamicaTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run regression on existing webhook tests**

Run: `php artisan test --filter=UazapiWebhook`
Expected: PASS (nenhuma quebra nos testes de webhook já existentes — tenants sem coluna renomeada continuam com `lead_novo`→`em_atendimento` exatamente como antes)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php tests/Feature/UazapiWebhookColunaDinamicaTest.php
git commit -m "feat(kanban): webhook usa coluna de Entrada dinâmica e avança pela ordem"
```

---

### Task 13: `FollowupConversas` — papel em vez de chave literal

**Files:**
- Modify: `app/Console/Commands/FollowupConversas.php:219-233` (método privado `aplicarMovimentoAutomatico`)
- Test: `tests/Feature/FollowupConversasAutoMoverTest.php` (arquivo já existe — adicionar caso)

**Interfaces:**
- Consumes: `KanbanColuna::papelDe()` (Task 4).

> **Nota de escopo:** as linhas 42 e 93 deste arquivo filtram por `whereNotIn('t.etapa_ia', ['handoff'])` — um campo independente da coluna (não `coluna_kanban`), então não precisam de nenhuma alteração para colunas dinâmicas. A única parte deste arquivo acoplada a chaves literais é o método `aplicarMovimentoAutomatico()` (linhas 195-236), que recebe `$destino` (a chave de coluna resolvida por quem chama) e decide o que fazer comparando `$destino === 'encerrado'` / `'outros'` literalmente.

- [ ] **Step 1: Write the failing test (adicionar ao arquivo existente)**

```php
    public function test_mover_para_coluna_de_papel_transferencia_humana_seta_agente_humano_mesmo_renomeada(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::TransferenciaHumana)
            ->update(['chave' => 'time_humano']);
        $contato = \App\Models\Contato::factory()->create();
        $ticket = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $metodo = new \ReflectionMethod(\App\Console\Commands\FollowupConversas::class, 'aplicarMovimentoAutomatico');
        $metodo->setAccessible(true);
        $metodo->invoke(
            app(\App\Console\Commands\FollowupConversas::class),
            $ticket,
            'time_humano',
            null,
            app(\App\Services\HumanizacaoService::class)
        );

        $this->assertSame('time_humano', $ticket->fresh()->coluna_kanban);
        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    public function test_mover_para_coluna_de_papel_encerramento_renomeada_encerra_o_ticket(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $contato = \App\Models\Contato::factory()->create();
        $ticket = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $metodo = new \ReflectionMethod(\App\Console\Commands\FollowupConversas::class, 'aplicarMovimentoAutomatico');
        $metodo->setAccessible(true);
        $metodo->invoke(
            app(\App\Console\Commands\FollowupConversas::class),
            $ticket,
            'finalizado',
            null,
            app(\App\Services\HumanizacaoService::class)
        );

        $this->assertSame('finalizado', $ticket->fresh()->coluna_kanban);
        $this->assertSame('encerrado', $ticket->fresh()->status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FollowupConversasAutoMover`
Expected: FAIL — 1º teste: `coluna_kanban` vira `'outros'` literal (hardcoded) em vez de `'time_humano'`; 2º teste: `$destino === 'encerrado'` não reconhece `'finalizado'`, cai no `else` genérico e não encerra o `status`

- [ ] **Step 3: Update `aplicarMovimentoAutomatico()`**

Em `app/Console/Commands/FollowupConversas.php:219-233`, trocar:
```php
        if ($destino === 'encerrado') {
            $ticket->update($ticket->dadosParaEncerrar([
                'tag_desfecho' => 'sem_resposta_automatico',
                'encerrado_em' => now(),
            ]));
            ConversationQAJob::dispatch($ticket->id);
            GerarResumoTicketJob::dispatch($ticket->id)->delay(now()->addSeconds(5));
        } elseif ($destino === 'outros') {
            $ticket->update([
                'coluna_kanban'      => 'outros',
                'agente_responsavel' => 'humano',
            ]);
        } else {
            $ticket->update(['coluna_kanban' => $destino]);
        }
```
por:
```php
        $papelDestino = \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $destino);

        if ($papelDestino === \App\Enums\PapelColunaKanban::Encerramento) {
            $ticket->update($ticket->dadosParaEncerrar([
                'tag_desfecho' => 'sem_resposta_automatico',
                'encerrado_em' => now(),
            ], $destino));
            ConversationQAJob::dispatch($ticket->id);
            GerarResumoTicketJob::dispatch($ticket->id)->delay(now()->addSeconds(5));
        } elseif ($papelDestino === \App\Enums\PapelColunaKanban::TransferenciaHumana) {
            $ticket->update([
                'coluna_kanban'      => $destino,
                'agente_responsavel' => 'humano',
            ]);
        } else {
            $ticket->update(['coluna_kanban' => $destino]);
        }
```

> Nota: o código original já tinha um bug latente — a linha `'coluna_kanban' => 'outros'` (literal) em vez de `$destino` significava que, se algum dia `$destino` fosse uma string diferente de `'outros'` mas ainda assim caísse nesse branch, o ticket seria movido pro lugar errado. A troca acima corrige isso de passagem, usando sempre `$destino` (a chave real resolvida por quem chamou o método).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FollowupConversasAutoMover`
Expected: PASS (todos os testes do arquivo, incluindo os 2 novos)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/FollowupConversas.php
git commit -m "feat(kanban): FollowupConversas usa papel em vez de chave literal em aplicarMovimentoAutomatico"
```

---

### Task 14: `GestorKanbanService` — colunas dinâmicas e papel de Encerramento

**Files:**
- Modify: `app/Services/GestorKanbanService.php:16-19` (`self::COLUNAS`), `:40,43` (`coletarNumerosColuna`), `:62,80,95` (`amostrarConversasColuna`), `:107` (`formatarConversa`), `:209` (`gerarRelatorioSemanal`)
- Test: `tests/Feature/GestorKanbanServiceNumerosTest.php` (adicionar caso), `tests/Feature/GestorKanbanServiceOrquestracaoTest.php` (adicionar caso)

**Interfaces:**
- Consumes: `KanbanColuna::chavesDoTenant()`, `KanbanColuna::papelDe()` (Task 4).

> **Achado ao ler o arquivo completo:** além do `self::COLUNAS` fixo usado em `gerarRelatorioSemanal()`, existem **6 outras comparações literais com `'encerrado'`** espalhadas em `coletarNumerosColuna()` (breakdown de motivo de encerramento), `amostrarConversasColuna()` (prioriza os fechados da semana antes dos travados) e `formatarConversa()` (usa o resumo IA em vez do histórico completo). Todas precisam trocar para checagem de papel, senão o relatório semanal perde essas informações justamente para o tenant que renomeou a coluna de Encerramento.

- [ ] **Step 1: Write the failing test**

Adicionar em `tests/Feature/GestorKanbanServiceNumerosTest.php`:
```php
    public function test_breakdown_de_tag_desfecho_funciona_com_coluna_de_encerramento_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $this->criarTicket($tenant->id, 'finalizado', 'preco', Carbon::parse('2026-07-08 10:00:00'));

        $numeros = app(GestorKanbanService::class)->coletarNumerosColuna($tenant, 'finalizado', $inicio, $fim);

        $this->assertSame(['preco' => 1], $numeros['tag_desfecho_breakdown']);
    }
```

Adicionar em `tests/Feature/GestorKanbanServiceOrquestracaoTest.php`:
```php
    public function test_relatorio_inclui_coluna_customizada_criada_pelo_franqueado(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'triagem_extra', 'label' => 'Triagem Extra',
            'papel' => \App\Enums\PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $inicio = Carbon::parse('2026-07-06 00:00:00');
        $fim    = Carbon::parse('2026-07-12 23:59:59');

        $relatorio = app(GestorKanbanService::class)->gerarRelatorioSemanal($tenant, $inicio, $fim);

        $this->assertArrayHasKey('triagem_extra', $relatorio->dados);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GestorKanbanServiceNumeros --filter=GestorKanbanServiceOrquestracao`
Expected: FAIL — 1º teste: `tag_desfecho_breakdown` vazio porque `$coluna === 'encerrado'` não reconhece `'finalizado'`; 2º teste: `triagem_extra` ausente porque `self::COLUNAS` é fixo

- [ ] **Step 3: Update the service**

Trocar a constante fixa (linhas 16-19) por nada (removida) — as colunas passam a vir sempre de `KanbanColuna::chavesDoTenant()`.

Em `coletarNumerosColuna()` (linhas 40-50), trocar:
```php
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
```
por:
```php
        $tagDesfechoBreakdown = [];
        if (\App\Models\KanbanColuna::papelDe($tenant->id, $coluna) === \App\Enums\PapelColunaKanban::Encerramento) {
            $tagDesfechoBreakdown = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', $coluna)
                ->whereBetween('encerrado_em', [$inicio, $fim])
                ->whereNotNull('tag_desfecho')
                ->selectRaw('tag_desfecho, count(*) as total')
                ->groupBy('tag_desfecho')
                ->pluck('total', 'tag_desfecho')
                ->toArray();
        }
```

Em `amostrarConversasColuna()` (linhas 60-103), trocar a condição de entrada:
```php
    public function amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): Collection
    {
        if ($coluna !== 'encerrado') {
```
por:
```php
    public function amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): Collection
    {
        if (\App\Models\KanbanColuna::papelDe($tenant->id, $coluna) !== \App\Enums\PapelColunaKanban::Encerramento) {
```
e, nas duas queries logo abaixo que fazem `->where('coluna_kanban', 'encerrado')` (linhas 80 e 95), trocar por `->where('coluna_kanban', $coluna)` (já é o parâmetro recebido — só remove o literal errado que ignorava qual coluna de encerramento realmente estava sendo consultada).

Em `formatarConversa()` (linha 107), trocar:
```php
        if ($ticket->coluna_kanban === 'encerrado' && $ticket->resumo_ia) {
```
por:
```php
        if (\App\Models\KanbanColuna::papelDe($ticket->tenant_id, $ticket->coluna_kanban) === \App\Enums\PapelColunaKanban::Encerramento && $ticket->resumo_ia) {
```

Em `gerarRelatorioSemanal()` (linha 209), trocar:
```php
        foreach (self::COLUNAS as $coluna) {
```
por:
```php
        foreach (\App\Models\KanbanColuna::chavesDoTenant($tenant->id) as $coluna) {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GestorKanban`
Expected: PASS (todos os testes de `GestorKanbanService*`, incluindo os 2 novos)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GestorKanbanService.php \
        tests/Feature/GestorKanbanServiceNumerosTest.php tests/Feature/GestorKanbanServiceOrquestracaoTest.php
git commit -m "feat(kanban): GestorKanbanService usa colunas e papel de Encerramento dinâmicos do tenant"
```

---

### Task 15: Criação de ticket em lote — 7 arquivos com `'coluna_kanban' => 'lead_novo'` hardcoded

**Files:**
- Modify: `app/Services/FormularioService.php:109-134`
- Modify: `app/Http/Controllers/Api/SecretariaEletronicaController.php:86-115`
- Modify: `app/Http/Controllers/Internal/TicketController.php:29-35`
- Modify: `app/Console/Commands/SincronizarContatosWhatsApp.php:130-140`
- Modify: `app/Jobs/SincronizarAgendaWhatsAppJob.php:90-105`
- Modify: `app/Console/Commands/ImportarParticipantesGrupos.php:130-145`
- Test: `tests/Feature/CriacaoTicketUsaChaveDeEntradaTest.php` (novo, cobre os 6 pontos de criação com um teste por arquivo)

**Interfaces:**
- Consumes: `KanbanColuna::chaveDeEntrada()`, `KanbanColuna::chavesComPapel()` (Task 4).

> **Decisão documentada nesta task:** o dedup `whereIn('coluna_kanban', ['lead_novo', 'em_atendimento'])` (presente em `FormularioService` e `SecretariaEletronicaController`) generaliza para "ticket ainda não chegou em Encerramento nem em Transferência Humana" — `whereNotIn(chavesComPapel(Encerramento) + chavesComPapel(TransferenciaHumana))`. Isso amplia levemente a janela de dedup (hoje um ticket em `aguardando_orcamento` permite abrir um 2º ticket; depois da mudança, não permite mais até o ticket fechar). Ajuste deliberado — evita duplicar atendimento de um lead já em andamento, mais alinhado com a intenção original do dedup do que a lista de 2 chaves fixas.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Formulario;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\FormularioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CriacaoTicketUsaChaveDeEntradaTest extends TestCase
{
    use RefreshDatabase;

    public function test_formulario_service_cria_ticket_na_coluna_de_entrada_do_tenant(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $formulario = Formulario::create([
            'tenant_id' => $tenant->id, 'uuid' => 'form-entrada-teste',
            'nome' => 'Formulário de teste', 'ativo' => true,
        ]);

        app(FormularioService::class)->processar($formulario, [
            'telefone' => '21999998887', 'nome' => 'Cliente Teste',
        ], 'teste.com.br');

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('novo_contato', $ticket->coluna_kanban);
    }
}
```

(Assinatura real confirmada em `FormularioService::processar(Formulario $formulario, array $dados, string $dominioOrigem): array` e no teste existente `tests/Feature/FormularioServiceSemNomeTest.php` — `Formulario::create()` direto, sem factory, e `Bus::fake()` porque `processar()` despacha `FormularioLeadJob`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CriacaoTicketUsaChaveDeEntradaTest.php`
Expected: FAIL — ticket criado em `'lead_novo'` (hardcoded), não `'novo_contato'`

- [ ] **Step 3: Update all 6 files**

Cada arquivo troca `'coluna_kanban' => 'lead_novo'` por `'coluna_kanban' => \App\Models\KanbanColuna::chaveDeEntrada(<id do tenant>)`, usando a variável de tenant já confirmada em cada arquivo:

| Arquivo | Linha | Variável do tenant já em escopo |
|---|---|---|
| `FormularioService.php` | 132 | `$tenant->id` (de `$tenant = $formulario->tenant;`, linha 61) |
| `SecretariaEletronicaController.php` | 112 | `$tenant->id` |
| `SincronizarContatosWhatsApp.php` | 137 | `$tenant->id` |
| `SincronizarAgendaWhatsAppJob.php` | 98 | `$tenant->id` |
| `ImportarParticipantesGrupos.php` | 137 | `$tenant->id` |
| `Internal/TicketController.php` | 32 | `$request->tenant_id` (**não** `$tenant->id` — este arquivo não tem objeto `$tenant`, só o inteiro vindo do request validado) |

Exemplo (`FormularioService.php:132`):
```php
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
```
Exemplo (`Internal/TicketController.php:32`):
```php
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id),
```

E nos 2 pontos de dedup (`FormularioService.php:112`, `SecretariaEletronicaController.php:89` — os únicos 2 arquivos desta lista que têm esse `whereIn`; os outros 4 não fazem dedup):
```php
            ->whereNotIn('coluna_kanban', array_merge(
                \App\Models\KanbanColuna::chavesComPapel($tenant->id, \App\Enums\PapelColunaKanban::Encerramento),
                \App\Models\KanbanColuna::chavesComPapel($tenant->id, \App\Enums\PapelColunaKanban::TransferenciaHumana),
            ))
```
(substituindo `->whereIn('coluna_kanban', ['lead_novo', 'em_atendimento'])`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/CriacaoTicketUsaChaveDeEntradaTest.php`
Expected: PASS

- [ ] **Step 5: Run regression across all 6 touched files' existing test suites**

Run: `php artisan test --filter=Formulario --filter=SecretariaEletronica --filter=TicketController --filter=SincronizarContatos --filter=SincronizarAgenda --filter=ImportarParticipantes`
Expected: PASS em todos (se algum teste existente assumia literalmente `coluna_kanban === 'lead_novo'` num tenant de factory sem customização de coluna, ele continua passando porque a chave de Entrada padrão permanece `'lead_novo'`)

- [ ] **Step 6: Commit**

```bash
git add app/Services/FormularioService.php \
        app/Http/Controllers/Api/SecretariaEletronicaController.php \
        app/Http/Controllers/Internal/TicketController.php \
        app/Console/Commands/SincronizarContatosWhatsApp.php \
        app/Jobs/SincronizarAgendaWhatsAppJob.php \
        app/Console/Commands/ImportarParticipantesGrupos.php \
        tests/Feature/CriacaoTicketUsaChaveDeEntradaTest.php
git commit -m "feat(kanban): criação de ticket usa chaveDeEntrada() dinâmica em todos os pontos de entrada"
```

---

### Task 16: `AgendaImediataService` e `SequenciaMensagemJob` — leitura de papel Encerramento/Entrada

**Files:**
- Modify: `app/Services/AgendaImediataService.php:33` (papel Encerramento) e `:59-62` (papel Entrada)
- Modify: `app/Jobs/SequenciaMensagemJob.php:33`
- Test: `tests/Feature/SequenciaMensagemJobObrigatorioTest.php` (arquivo já existe — adicionar caso), `tests/Feature/AgendaImediataServiceTest.php` (criar se não existir um equivalente)

**Interfaces:**
- Consumes: `KanbanColuna::papelDe()`, `KanbanColuna::chavesComPapel()` (Task 4).

- [ ] **Step 1: Write the failing test**

Adicionar em `tests/Feature/SequenciaMensagemJobObrigatorioTest.php` (seguindo o padrão já usado nos 2 testes existentes do arquivo — `Http::fake()` + `->handle(app(HumanizacaoService::class), app(UazapiService::class))`):

```php
    public function test_nao_envia_mensagem_se_ticket_ja_esta_em_coluna_de_papel_encerramento(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $contato = Contato::factory()->create(['telefone' => '5511999999998']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Mensagem de teste que não deveria ser enviada'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }
```

Criar `tests/Feature/AgendaImediataServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Services\AgendaImediataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaImediataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignora_tickets_em_coluna_de_papel_encerramento_mesmo_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);
        // Mensagem do lead há mais de 15min sem resposta — sem a correção de
        // papel, este ticket apareceria em "urgentes" mesmo estando encerrado.
        \App\Models\Mensagem::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Última msg do lead',
            'enviado_em' => now()->subMinutes(30),
        ]);

        $agenda = app(AgendaImediataService::class)->getAgenda($user);

        $this->assertSame([], $agenda['urgentes']);
    }

    public function test_conta_novos_leads_na_coluna_de_papel_entrada_mesmo_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo_contato', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $agenda = app(AgendaImediataService::class)->getAgenda($user);

        $this->assertCount(1, $agenda['hoje']);
        $this->assertSame('1 lead novo', $agenda['hoje'][0]['titulo']);
    }
}
```

(Shape real de `getAgenda()` confirmado em `app/Services/AgendaImediataService.php:74-77`: retorna `['urgentes' => [...], 'hoje' => [...]]` — `totalNovos` não é uma chave do retorno, só alimenta o item `hoje[0]['titulo']` quando `> 0`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SequenciaMensagemJobObrigatorio --filter=AgendaImediataService`
Expected: FAIL — job envia mensagem mesmo com ticket em `'finalizado'` (papel Encerramento); `getAgenda()` conta 0 novos leads em `'novo_contato'` porque só reconhece a chave literal `'lead_novo'`, e conta erradamente tickets em `'finalizado'` como pendentes de resposta porque só exclui a chave literal `'encerrado'`

- [ ] **Step 3: Update both files**

`app/Jobs/SequenciaMensagemJob.php:33`:
```php
        if (! $ticket || \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $ticket->coluna_kanban) === \App\Enums\PapelColunaKanban::Encerramento) {
            return;
        }
```

`app/Services/AgendaImediataService.php:33` (dentro de `getAgenda()`, onde `$tenantId` já está no escopo desde a linha 12):
```php
            ->whereNotIn('t.coluna_kanban', \App\Models\KanbanColuna::chavesComPapel($tenantId, \App\Enums\PapelColunaKanban::Encerramento))
```
(substituindo `->where('t.coluna_kanban', '!=', 'encerrado')`)

E nas linhas 59-62 do mesmo método:
```php
        // New leads without assignment
        $totalNovos = DB::table('tickets_atendimento')
            ->where('tenant_id', $tenantId)
            ->whereIn('coluna_kanban', \App\Models\KanbanColuna::chavesComPapel($tenantId, \App\Enums\PapelColunaKanban::Entrada))
            ->count();
```
(substituindo `->where('coluna_kanban', 'lead_novo')` por `->whereIn(...)` com as chaves de papel Entrada — na prática sempre 1 chave, já que só pode haver 1 coluna de Entrada por Kanban, mas `whereIn` deixa o código correto mesmo se essa regra de cardinalidade mudar no futuro)

Adicionar `use App\Models\KanbanColuna;` e `use App\Enums\PapelColunaKanban;` no topo de `AgendaImediataService.php`, junto aos `use` já existentes.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SequenciaMensagemJobObrigatorio --filter=AgendaImediataService`
Expected: PASS (todos os testes, incluindo os novos)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AgendaImediataService.php app/Jobs/SequenciaMensagemJob.php \
        tests/Feature/AgendaImediataServiceTest.php tests/Feature/SequenciaMensagemJobObrigatorioTest.php
git commit -m "feat(kanban): AgendaImediataService e SequenciaMensagemJob checam papel Encerramento/Entrada"
```

---

### Task 17: `SequenciaController::sugerirVariaveis()` e `ContatosController::historicoContato()` dinâmicos

**Files:**
- Modify: `app/Models/KanbanColuna.php` (novo helper `descricaoParaIa()`)
- Modify: `app/Http/Controllers/Painel/SequenciaController.php:226-235`
- Modify: `app/Http/Controllers/Painel/ContatosController.php:843-852`
- Test: `tests/Feature/KanbanColunaHelpersTest.php` (adicionar caso do helper), `tests/Feature/ContatosControllerHistoricoContatoTest.php` (novo)

**Interfaces:**
- Consumes: modelo `KanbanColuna` (Task 3).
- Produces: `KanbanColuna::descricaoParaIa(int $tenantId, string $chave): string` — usado por `SequenciaController::sugerirVariaveis()` como contexto enviado à IA (esse endpoint não expõe `labelColuna` na resposta JSON — é usado internamente para montar o prompt de `OpenRouterService::chat()`).

> **Correção de entendimento:** `$labelColuna` em `SequenciaController.php:226` **não** é devolvido ao frontend — é injetado no prompt enviado à IA dentro de `sugerirVariaveis()` (endpoint que sugere variáveis de personalização pras mensagens de uma sequência). Por isso o teste deste controller precisa capturar o prompt via mock de `OpenRouterService`, no mesmo padrão já usado em `tests/Feature/SdrResponderServiceEstagiosTest.php`, em vez de inspecionar uma resposta JSON.

- [ ] **Step 1: Write the failing test**

Adicionar em `tests/Feature/KanbanColunaHelpersTest.php`:
```php
    public function test_descricao_para_ia_combina_label_real_com_descricao_generica_do_papel(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = $this->criarColunasPadrao($tenant);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'triagem_extra', 'label' => 'Minha Triagem Especial',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 5,
        ]);

        $this->assertSame(
            'Minha Triagem Especial — ' . PapelColunaKanban::EmAndamento->descricao(),
            KanbanColuna::descricaoParaIa($tenant->id, 'triagem_extra')
        );
        $this->assertSame('chave_inexistente', KanbanColuna::descricaoParaIa($tenant->id, 'chave_inexistente'));
    }
```

Criar `tests/Feature/ContatosControllerHistoricoContatoTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatosControllerHistoricoContatoTest extends TestCase
{
    use RefreshDatabase;

    public function test_historico_mostra_o_label_real_de_uma_coluna_customizada(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban  = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'triagem_extra', 'label' => 'Minha Triagem Especial',
            'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'triagem_extra', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/contato/{$contato->id}/historico");

        $response->assertOk();
        $this->assertSame('Minha Triagem Especial', $response->json('0.coluna'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaHelpersTest.php tests/Feature/ContatosControllerHistoricoContatoTest.php`
Expected: FAIL — `descricaoParaIa()` não existe; histórico devolve a chave crua `'triagem_extra'` em vez do label

- [ ] **Step 3: Implement**

Adicionar em `app/Models/KanbanColuna.php` (junto aos demais helpers estáticos da Task 4):
```php
    public static function descricaoParaIa(int $tenantId, string $chave): string
    {
        $coluna = static::doTenant($tenantId)->firstWhere('chave', $chave);

        return $coluna ? "{$coluna->label} — {$coluna->papel->descricao()}" : $chave;
    }
```

Em `SequenciaController.php`, trocar o `match` fixo (linhas 226-235):
```php
        $labelColuna = match ($sequencia->coluna_kanban) {
            'lead_novo'            => 'Novo Lead — PRIMEIRO contato, lead nunca interagiu antes',
            'em_atendimento'       => 'Em Atendimento — lead está em conversa ativa',
            'aguardando_orcamento' => 'Aguardando Orçamento — lead qualificado aguardando proposta',
            'aguardando_lead'      => 'Aguardando Lead — follow-up após envio do orçamento (lead sumiu)',
            'pagamento'            => 'Pagamento — orçamento aprovado, aguardando sinal do lead',
            'servico_agendado'     => 'Serviço Agendado — confirmação e orientações pré-serviço',
            'encerrado'            => 'Encerrado — agradecimento e encerramento do atendimento',
            default                => $sequencia->coluna_kanban,
        };
```
por:
```php
        $labelColuna = \App\Models\KanbanColuna::descricaoParaIa($sequencia->tenant_id, $sequencia->coluna_kanban);
```

Em `ContatosController.php:843-852`, trocar o array fixo `$colunaLabel` por:
```php
        $colunaLabel = \App\Models\KanbanColuna::where('tenant_id', $tenantId)->pluck('label', 'chave')->all();
```
(mantendo o uso em `'coluna' => $colunaLabel[$t->coluna_kanban] ?? $t->coluna_kanban` na linha 856 sem alteração — só a origem do array muda).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaHelpersTest.php tests/Feature/ContatosControllerHistoricoContatoTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/KanbanColuna.php app/Http/Controllers/Painel/SequenciaController.php \
        app/Http/Controllers/Painel/ContatosController.php \
        tests/Feature/KanbanColunaHelpersTest.php tests/Feature/ContatosControllerHistoricoContatoTest.php
git commit -m "feat(kanban): sugerirVariaveis() e historicoContato() usam label real da coluna"
```

---

### Task 18: `SdrResponderService` — token dinâmico + `etapa_ia_ao_mover`

**Files:**
- Modify: `app/Services/SdrResponderService.php:53-73`
- Test: `tests/Feature/SdrResponderServiceTokenDinamicoTest.php` (novo)

**Interfaces:**
- Consumes: `KanbanColuna::chavesDoTenant()`, `App\Models\KanbanColunaConfig` (`etapa_ia_ao_mover`) (Tasks 4, 5).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceTokenDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_de_coluna_renomeada_move_o_ticket_e_aplica_etapa_configurada(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('chave', 'aguardando_orcamento')
            ->update(['chave' => 'esperando_preco']);
        KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'aguardando_orcamento')
            ->update(['coluna_kanban' => 'esperando_preco', 'etapa_ia_ao_mover' => 'handoff']);

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, já te retorno! [ESPERANDO_PRECO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('esperando_preco', $ticket->coluna_kanban);
        $this->assertSame('handoff', $ticket->etapa_ia);
    }
}
```

(Padrão de teste — `Http::fake()`, `Tenant::factory()->create(['uazapi_instance_token' => 'tok'])`, `SdrPersona::create([...])` sem factory, `$this->mock(OpenRouterService::class, ...)` — confirmado em `tests/Feature/SdrResponderServiceEstagiosTest.php`, já existente no projeto.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SdrResponderServiceTokenDinamicoTest.php`
Expected: FAIL — `$tokenColunas` fixo não reconhece `[ESPERANDO_PRECO]`, ticket não avança

- [ ] **Step 3: Update `SdrResponderService::responder()`**

Trocar o bloco `$tokenColunas` fixo (linhas ~56-64) e o loop que o usa (linhas ~66-73):

```php
        // Token = chave da coluna em maiúsculas entre colchetes. Gerado dinamicamente
        // a partir das colunas reais do tenant — se o franqueado renomear uma coluna,
        // o token muda junto (a tela de config mostra o token atual como dica).
        $tenantId = $ticket->tenant_id;
        $colunas  = \App\Models\KanbanColuna::query()
            ->where('tenant_id', $tenantId)
            ->get(['chave']);

        $moveu = false;
        foreach ($colunas as $coluna) {
            $token = '[' . mb_strtoupper($coluna->chave) . ']';

            if (str_contains($resposta, $token)) {
                $etapa = \App\Models\KanbanColunaConfig::where('tenant_id', $tenantId)
                    ->where('coluna_kanban', $coluna->chave)
                    ->value('etapa_ia_ao_mover') ?? 'etapa_1';

                $papel = \App\Models\KanbanColuna::papelDe($tenantId, $coluna->chave);
                $updates = $papel === \App\Enums\PapelColunaKanban::Encerramento
                    ? $ticket->dadosParaEncerrar(['etapa_ia' => $etapa], $coluna->chave)
                    : ['coluna_kanban' => $coluna->chave, 'etapa_ia' => $etapa];

                $ticket->update($updates);
                $moveu = true;
                break;
            }
        }
```

(Mantendo inalterado tudo que vem depois — o bloco de fallback `if (! $moveu && ...)` — apenas trocando a fonte de `$moveu` pro resultado deste novo loop.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/SdrResponderServiceTokenDinamicoTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Run regression on existing SdrResponderService tests**

Run: `php artisan test --filter=SdrResponder`
Expected: PASS — tenants sem coluna renomeada continuam usando os tokens `[AGUARDANDO_ORCAMENTO]` etc normalmente, já que a chave default não muda

- [ ] **Step 6: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceTokenDinamicoTest.php
git commit -m "feat(kanban): SdrResponderService gera token de movimento a partir da chave real da coluna"
```

---

### Task 19: `KanbanColunaController` — CRUD de self-service (criar/editar/reordenar/excluir)

**Files:**
- Create: `app/Http/Controllers/Painel/KanbanColunaController.php`
- Modify: `routes/web.php` (grupo `role:admin,dono`, próximo às rotas de `kanban/coluna-config`)
- Test: `tests/Feature/KanbanColunaControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Kanban`, `App\Models\KanbanColuna`, `App\Enums\PapelColunaKanban` (Tasks 1, 3, 4).
- Produces:
  - `GET /api/painel/kanban/colunas` — lista colunas do Kanban de Vendas do tenant, ordenadas
  - `GET /api/painel/kanban/papeis` — lista `PapelColunaKanban::cases()` com label/descrição/exemplos
  - `POST /api/painel/kanban/colunas` — cria coluna (`label`, `emoji`, `papel`)
  - `PUT /api/painel/kanban/colunas/{coluna}` — edita `label`/`emoji`/`papel`
  - `DELETE /api/painel/kanban/colunas/{coluna}` — exclui (bloqueia se houver ticket na coluna)
  - `POST /api/painel/kanban/colunas/reordenar` — recebe array ordenado de ids, atualiza `ordem`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_papeis_disponiveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/papeis');

        $response->assertOk();
        $this->assertCount(4, $response->json());
        $this->assertSame('entrada', $response->json('0.value'));
    }

    public function test_cria_coluna_nova(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas', [
            'label' => 'Minha Coluna', 'emoji' => '⭐', 'papel' => 'em_andamento',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('kanban_colunas', [
            'tenant_id' => $tenant->id, 'label' => 'Minha Coluna', 'chave' => 'minha_coluna', 'papel' => 'em_andamento',
        ]);
    }

    public function test_edita_coluna_existente(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $coluna = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'em_atendimento')->firstOrFail();

        $response = $this->actingAs($user)->putJson("/api/painel/kanban/colunas/{$coluna->id}", [
            'label' => 'Novo Nome', 'emoji' => '🆕', 'papel' => 'em_andamento',
        ]);

        $response->assertOk();
        $this->assertSame('Novo Nome', $coluna->fresh()->label);
    }

    public function test_reordena_colunas(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = $this->usuarioDono($tenant);
        $colunas = KanbanColuna::where('tenant_id', $tenant->id)->orderBy('ordem')->get();
        $idsInvertidos = $colunas->pluck('id')->reverse()->values()->all();

        $response = $this->actingAs($user)->postJson('/api/painel/kanban/colunas/reordenar', [
            'ids' => $idsInvertidos,
        ]);

        $response->assertOk();
        $this->assertSame($idsInvertidos, KanbanColuna::where('tenant_id', $tenant->id)->orderBy('ordem')->pluck('id')->all());
    }

    public function test_bloqueia_exclusao_de_coluna_com_ticket_ativo(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = $this->usuarioDono($tenant);
        $coluna  = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', 'em_atendimento')->firstOrFail();
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/colunas/{$coluna->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('kanban_colunas', ['id' => $coluna->id]);
    }

    public function test_exclui_coluna_sem_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $coluna = KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id,
            'chave' => 'vazia', 'label' => 'Vazia', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 99,
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/kanban/colunas/{$coluna->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('kanban_colunas', ['id' => $coluna->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/KanbanColunaControllerTest.php`
Expected: FAIL — rotas não existem (404)

- [ ] **Step 3: Write the controller and routes**

`app/Http/Controllers/Painel/KanbanColunaController.php`:
```php
<?php

namespace App\Http\Controllers\Painel;

use App\Enums\PapelColunaKanban;
use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\TicketAtendimento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class KanbanColunaController extends Controller
{
    public function papeis(): JsonResponse
    {
        return response()->json(collect(PapelColunaKanban::cases())->map(fn (PapelColunaKanban $papel) => [
            'value'           => $papel->value,
            'label'           => $papel->label(),
            'descricao'       => $papel->descricao(),
            'objetivo_exemplo' => $papel->objetivoExemplo(),
            'prompt_exemplo'  => $papel->promptExemplo(),
        ])->values());
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $colunas = KanbanColuna::where('tenant_id', $tenantId)->orderBy('ordem')->get();

        return response()->json($colunas->map(fn (KanbanColuna $c) => [
            'id'    => $c->id,
            'chave' => $c->chave,
            'label' => $c->label,
            'emoji' => $c->emoji,
            'papel' => $c->papel->value,
            'ordem' => $c->ordem,
            'token' => '[' . mb_strtoupper($c->chave) . ']',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'label' => 'required|string|max:60',
            'emoji' => 'nullable|string|max:10',
            'papel' => ['required', Rule::enum(PapelColunaKanban::class)],
        ]);

        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        if ($dados['papel'] === PapelColunaKanban::Entrada->value
            && KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada->value)->exists()) {
            return response()->json(['message' => 'Já existe uma coluna de Entrada — só pode haver 1 por Kanban.'], 422);
        }

        $chaveBase = Str::slug($dados['label'], '_');
        $chave     = $chaveBase;
        $sufixo    = 1;
        while (KanbanColuna::where('kanban_id', $kanban->id)->where('chave', $chave)->exists()) {
            $chave = "{$chaveBase}_" . (++$sufixo);
        }

        $proximaOrdem = (KanbanColuna::where('kanban_id', $kanban->id)->max('ordem') ?? 0) + 1;

        $coluna = KanbanColuna::create([
            'tenant_id' => $tenantId,
            'kanban_id' => $kanban->id,
            'chave'     => $chave,
            'label'     => $dados['label'],
            'emoji'     => $dados['emoji'] ?? null,
            'papel'     => $dados['papel'],
            'ordem'     => $proximaOrdem,
        ]);

        return response()->json($coluna, 201);
    }

    public function update(Request $request, int $coluna): JsonResponse
    {
        $dados = $request->validate([
            'label' => 'required|string|max:60',
            'emoji' => 'nullable|string|max:10',
            'papel' => ['required', Rule::enum(PapelColunaKanban::class)],
        ]);

        $tenantId  = $request->user()->tenant_id;
        $colunaObj = KanbanColuna::where('tenant_id', $tenantId)->findOrFail($coluna);

        if ($dados['papel'] === PapelColunaKanban::Entrada->value
            && $colunaObj->papel !== PapelColunaKanban::Entrada
            && KanbanColuna::where('kanban_id', $colunaObj->kanban_id)->where('papel', PapelColunaKanban::Entrada->value)->exists()) {
            return response()->json(['message' => 'Já existe uma coluna de Entrada — só pode haver 1 por Kanban.'], 422);
        }

        $colunaObj->update($dados);

        return response()->json($colunaObj->fresh());
    }

    public function destroy(Request $request, int $coluna): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $colunaObj = KanbanColuna::where('tenant_id', $tenantId)->findOrFail($coluna);

        $ticketsNaColuna = TicketAtendimento::where('coluna_kanban', $colunaObj->chave)->count();

        if ($ticketsNaColuna > 0) {
            return response()->json([
                'message' => "Não é possível excluir: {$ticketsNaColuna} ticket(s) ainda estão nesta coluna. Mova-os antes de excluir.",
            ], 422);
        }

        $colunaObj->delete();

        return response()->json(['excluida' => true]);
    }

    public function reordenar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;

        foreach ($dados['ids'] as $indice => $id) {
            KanbanColuna::where('tenant_id', $tenantId)->where('id', $id)->update(['ordem' => $indice + 1]);
        }

        return response()->json(['reordenado' => true]);
    }
}
```

Adicionar em `routes/web.php`, dentro do grupo `role:admin,dono` que já contém `/kanban/coluna-config/{coluna}` (por volta da linha 342-352):
```php
        Route::get('/kanban/colunas',             [KanbanColunaController::class, 'index']);
        Route::get('/kanban/papeis',              [KanbanColunaController::class, 'papeis']);
        Route::post('/kanban/colunas',            [KanbanColunaController::class, 'store']);
        Route::put('/kanban/colunas/{coluna}',    [KanbanColunaController::class, 'update']);
        Route::delete('/kanban/colunas/{coluna}', [KanbanColunaController::class, 'destroy']);
        Route::post('/kanban/colunas/reordenar',  [KanbanColunaController::class, 'reordenar']);
```
(e adicionar `use App\Http\Controllers\Painel\KanbanColunaController;` no topo do arquivo de rotas, junto aos demais `use` de controllers de `Painel`)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/KanbanColunaControllerTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaController.php routes/web.php tests/Feature/KanbanColunaControllerTest.php
git commit -m "feat(kanban): CRUD self-service de colunas (criar/editar/reordenar/excluir)"
```

---

### Task 20: Frontend — `kanban/index.blade.php` consome colunas da API

**Files:**
- Modify: `resources/views/kanban/index.blade.php:609-616` (array `colunas` fixo) e todo lugar que lê `coluna.label`/`emoji` a partir desse array local
- Test: manual (Playwright local) — sem teste PHPUnit, é mudança de frontend puro

**Interfaces:**
- Consumes: resposta de `GET /api/painel/kanban/tickets` já ampliada com a chave `colunas` (Task 9).

- [ ] **Step 1: Remove o array fixo**

Em `resources/views/kanban/index.blade.php`, remover o array literal:
```js
            { key: 'lead_novo',           label: 'Novo' },
            { key: 'em_atendimento',      label: 'Em Atendimento' },
            { key: 'aguardando_orcamento',label: 'Aguardando Orçamento' },
            { key: 'aguardando_lead',     label: 'Aguardando Lead' },
            { key: 'pagamento',           label: 'Pagamento' },
            { key: 'servico_agendado',    label: 'Serviço Agendado' },
            { key: 'encerrado',           label: 'Encerrado' },
            { key: 'outros',              label: 'Outros / Internos' },
```
e trocar por `colunas: []` (populado no `carregar()`/`init()` a partir da resposta da API).

- [ ] **Step 2: Popular a partir da resposta da API**

No método que já faz `fetch`/`api()` pra `/api/painel/kanban/tickets` (usado hoje só pra tickets/totais), adicionar:
```js
                this.colunas = (dados.colunas || []).map(c => ({ key: c.chave, label: c.label, emoji: c.emoji }));
```
(campo `x-text="coluna.label"` no template já existente em outros pontos do arquivo continua funcionando sem alteração, pois o shape `{key, label}` é preservado — só a origem dos dados muda de hardcoded pra dinâmica).

- [ ] **Step 3: Testar manualmente**

Rodar `php artisan serve` local, abrir `/kanban`, confirmar visualmente que as 8 colunas aparecem exatamente como antes (mesmos nomes/ordem), usando o MCP do Playwright (`browser_navigate` → `/kanban`, `browser_snapshot` pra conferir as colunas renderizadas).

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/index.blade.php
git commit -m "feat(kanban): index.blade.php busca colunas da API em vez de array fixo"
```

---

### Task 21: Frontend — `kanban/config.blade.php` — self-service + textos por papel

**Files:**
- Modify: `resources/views/kanban/config.blade.php:895-1002` (array `colunas` fixo com `objetivoEx`/`iaPlaceholder` hardcoded)
- Test: manual (Playwright local)

**Interfaces:**
- Consumes: `GET /api/painel/kanban/colunas`, `GET /api/painel/kanban/papeis`, `POST`/`PUT`/`DELETE /api/painel/kanban/colunas` (Task 19).

- [ ] **Step 1: Remove o array fixo, busca via API**

Trocar o array literal `colunas: [...]` (linhas 897-1002) por `colunas: []`, carregado no `init()`:
```js
        async init() {
            const resPapeis = await this.api('/api/painel/kanban/papeis');
            this.papeisDisponiveis = await resPapeis.json();

            await this.carregarColunas();
            // ... resto do init() já existente, sem alteração ...
        },

        async carregarColunas() {
            const res = await this.api('/api/painel/kanban/colunas');
            const dados = await res.json();

            this.colunas = dados.map(c => {
                const papel = this.papeisDisponiveis.find(p => p.value === c.papel) || {};
                return {
                    key: c.chave, id: c.id, emoji: c.emoji, label: c.label, papel: c.papel, token: c.token,
                    objetivoEx: papel.objetivo_exemplo || '',
                    iaPlaceholder: papel.prompt_exemplo || '',
                };
            });
        },
```

- [ ] **Step 2: Adicionar seção de gerenciamento de colunas**

Adicionar, na mesma tela, um bloco novo (acima ou ao lado das abas de configuração de IA já existentes) com:
- Lista das colunas atuais (`label`, `emoji`, `papel.label()`, `token` exibido como dica copiável)
- Botão "+ Nova Coluna" → modal com campos `label`, `emoji`, dropdown de `papel` (usando `papeisDisponiveis` — mostrar `descricao` de cada papel como ajuda inline) → `POST /api/painel/kanban/colunas`
- Botão "Editar" por coluna → mesmo modal preenchido → `PUT /api/painel/kanban/colunas/{id}`
- Botão "Excluir" por coluna → confirmação → `DELETE /api/painel/kanban/colunas/{id}` → se vier 422, mostrar a mensagem de erro (quantidade de tickets bloqueando) num toast
- Drag-and-drop (reaproveitar o mesmo padrão HTML5 nativo já usado no Kanban de `index.blade.php` para mover cards entre colunas) pra reordenar → ao soltar, `POST /api/painel/kanban/colunas/reordenar` com o novo array de ids

> Implementar o HTML/Alpine desta seção seguindo o estilo visual já estabelecido no restante de `config.blade.php` (mesmas classes Tailwind de card/modal já usadas nas abas de IA) — não introduzir um padrão visual novo.

- [ ] **Step 3: Testar manualmente**

Via Playwright local: criar uma coluna nova, editar seu papel, reordenar arrastando, tentar excluir uma coluna com ticket ativo (confirmar que a mensagem de bloqueio aparece), excluir uma coluna vazia (confirmar que some da lista e do Kanban em `/kanban`).

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat(kanban): tela de config ganha self-service de colunas (criar/editar/reordenar/excluir)"
```

---

### Task 22: Regressão final — suíte completa

**Files:** nenhum (task de verificação)

- [ ] **Step 1: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesma taxa de sucesso da suíte antes deste plano começar (mencionada em memória do projeto como 160/161, com a única falha pré-existente sendo `ExampleTest`, não relacionada) — qualquer falha nova precisa ser investigada e corrigida antes de considerar a tarefa concluída.

- [ ] **Step 2: Rodar `php artisan migrate:fresh --seed` local e conferir manualmente via Playwright**

Confirmar que um tenant novo (criado localmente via `php artisan tenant:criar` ou equivalente, ver `TenantCriarCommand`) nasce com o Kanban de Vendas completo e funcional, e que o tenant Frete.Rio (se houver seed/dump local de produção disponível) preserva todos os tickets nas colunas corretas depois do backfill.

- [ ] **Step 3: Revisar a spec uma última vez**

Reler `docs/superpowers/specs/2026-07-17-kanban-colunas-dinamicas-design.md` e confirmar que todos os itens da seção "Testes" têm cobertura correspondente neste plano (todos cobertos pelas Tasks 1-19 acima).

- [ ] **Step 4: Commit final (se houver ajustes pendentes de regressão)**

```bash
git add -A
git commit -m "fix(kanban): ajustes finais de regressão da suíte completa"
```

(Pular este commit se a Step 1 não encontrar nenhuma quebra.)
