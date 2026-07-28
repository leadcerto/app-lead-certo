# Canal WhatsApp Multi-Número + Vínculo por Kanban — Plano de Implementação (Plano A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar suporte a múltiplos números de WhatsApp não-oficiais (Uazapi) por tenant, vinculados a Kanbans específicos, com seleção aleatória de canal na hora de prospectar — preparando o terreno de dados para o canal oficial (Covercut), que entra num Plano B separado.

**Architecture:** Nova tabela `whatsapp_canais` substitui os campos soltos de conexão hoje em `tenants` (com migração retroativa dos dados existentes). Uma tabela pivot `kanban_whatsapp_canais` vincula canais a Kanbans. Toda leitura de token Uazapi passa a vir do canal resolvido (via `$ticket->canal` quando há ticket, ou via seleção aleatória entre os canais não-oficiais vinculados ao Kanban de entrada, quando é uma prospecção nova). Os campos antigos em `tenants` (`uazapi_instance_token` etc.) permanecem no banco até uma migration de limpeza separada, rodada só depois de validar esta entrega em produção — não faz parte deste plano.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8, Alpine.js v3, Tailwind CSS, PHPUnit (estilo clássico `test_*`, sem Pest).

## Global Constraints

- Nunca fazer deploy manual via SSH — sempre `git commit` local + `./deploy.sh` (regra do `CLAUDE.md` do projeto).
- Toda migration de dados deve ser idempotente (segura de rodar mais de uma vez).
- Não remover os campos legados de `tenants` (`uazapi_instance_token`, `uazapi_webhook_token`, `uazapi_instance_name`, `whatsapp_status`, `whatsapp_phone`, `whatsapp_connected_since`) nesta entrega — isso é uma migration de limpeza separada, só depois de validar em produção.
- Models de tenant usam `TenantScope` como global scope (ver `app/Scopes/TenantScope.php`) — sempre seguir essa convenção nos models novos.
- Convenção de nome de migration: `YYYY_MM_DD_NNNNNN_verbo_descricao.php`, sufixo numérico de 6 dígitos manual (não o timestamp de `make:migration`).
- Testes em `tests/Feature/*.php`, PHPUnit clássico, métodos `test_descricao_em_snake_case()`, `use RefreshDatabase;`, factories via `Tenant::factory()->create([...])`.

---

### Task 1: Tabela `whatsapp_canais` + model `WhatsappCanal` + factory + relação em `Tenant`

**Files:**
- Create: `database/migrations/2026_07_27_000001_create_whatsapp_canais_table.php`
- Create: `app/Models/WhatsappCanal.php`
- Create: `database/factories/WhatsappCanalFactory.php`
- Modify: `app/Models/Tenant.php`
- Test: `tests/Feature/WhatsappCanalModelTest.php`

**Interfaces:**
- Produces: `WhatsappCanal` model com `fillable = [tenant_id, tipo, provider, status, phone, connected_since, webhook_token, config]`, cast `config` como `array`, método `tokenUazapi(): ?string`, relações `tenant(): BelongsTo` e `kanbans(): BelongsToMany` (implementada na Task 2). `Tenant::canais(): HasMany`.

- [ ] **Step 1: Escrever a migration da tabela**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_canais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('tipo', 20); // 'oficial' | 'nao_oficial'
            $table->string('provider', 20); // 'uazapi' | 'covercut'
            $table->string('status', 20)->default('disconnected'); // 'connected' | 'connecting' | 'disconnected'
            $table->string('phone')->nullable();
            $table->timestamp('connected_since')->nullable();
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_canais');
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `2026_07_27_000001_create_whatsapp_canais_table ... DONE`

- [ ] **Step 3: Criar o model `WhatsappCanal`**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WhatsappCanal extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_canais';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'tipo',
        'provider',
        'status',
        'phone',
        'connected_since',
        'webhook_token',
        'config',
    ];

    protected $casts = [
        'connected_since' => 'datetime',
        'config'          => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function kanbans(): BelongsToMany
    {
        return $this->belongsToMany(Kanban::class, 'kanban_whatsapp_canais');
    }

    public function tokenUazapi(): ?string
    {
        return $this->config['instance_token'] ?? null;
    }
}
```

- [ ] **Step 4: Criar a factory**

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappCanal>
 */
class WhatsappCanalFactory extends Factory
{
    protected $model = WhatsappCanal::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'phone'     => '55' . $this->faker->numerify('###########'),
            'connected_since' => now(),
            'webhook_token'   => $this->faker->unique()->regexify('[A-Za-z0-9]{48}'),
            'config'    => ['instance_name' => $this->faker->slug(), 'instance_token' => $this->faker->uuid()],
        ];
    }
}
```

- [ ] **Step 5: Adicionar `canais()` em `Tenant`**

Em `app/Models/Tenant.php`, logo após o método `personas()`:

```php
    public function canais(): HasMany
    {
        return $this->hasMany(WhatsappCanal::class);
    }
```

(`HasMany` já está importado no arquivo — não precisa adicionar `use` novo.)

- [ ] **Step 6: Escrever o teste do model**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappCanalModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_pertence_ao_tenant_correto(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($tenant->canais->contains($canal));
        $this->assertSame($tenant->id, $canal->tenant->id);
    }

    public function test_token_uazapi_le_do_config_json(): void
    {
        $canal = WhatsappCanal::factory()->create([
            'config' => ['instance_token' => 'abc123'],
        ]);

        $this->assertSame('abc123', $canal->tokenUazapi());
    }

    public function test_escopo_de_tenant_isola_canais_entre_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        WhatsappCanal::factory()->create(['tenant_id' => $tenantA->id]);
        WhatsappCanal::factory()->create(['tenant_id' => $tenantB->id]);

        session(['tenant_id' => $tenantA->id]);

        $this->assertSame(1, WhatsappCanal::count());
    }
}
```

- [ ] **Step 7: Rodar os testes**

Run: `php artisan test --filter=WhatsappCanalModelTest`
Expected: 3 passed

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_27_000001_create_whatsapp_canais_table.php app/Models/WhatsappCanal.php database/factories/WhatsappCanalFactory.php app/Models/Tenant.php tests/Feature/WhatsappCanalModelTest.php
git commit -m "feat: adiciona tabela e model whatsapp_canais"
```

---

### Task 2: Tabela pivot `kanban_whatsapp_canais` + relação `Kanban::canais()`

**Files:**
- Create: `database/migrations/2026_07_27_000002_create_kanban_whatsapp_canais_table.php`
- Modify: `app/Models/Kanban.php`
- Test: `tests/Feature/KanbanCanalVinculoTest.php`

**Interfaces:**
- Produces: `Kanban::canais(): BelongsToMany` (pivot `kanban_whatsapp_canais`) — consumido pela Task 3 (backfill), que precisa desta tabela já existir.

- [ ] **Step 1: Escrever a migration do pivot**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_whatsapp_canais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kanban_id')->constrained('kanbans')->cascadeOnDelete();
            $table->foreignId('whatsapp_canal_id')->constrained('whatsapp_canais')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['kanban_id', 'whatsapp_canal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_whatsapp_canais');
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `..._create_kanban_whatsapp_canais_table ... DONE`

- [ ] **Step 3: Adicionar `canais()` em `Kanban`**

Em `app/Models/Kanban.php`, adicionar o import e o método:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
```

```php
    public function canais(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappCanal::class, 'kanban_whatsapp_canais');
    }
```

- [ ] **Step 4: Escrever o teste do vínculo**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalVinculoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vincula_e_desvincula_canais_de_um_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canalA = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $canalB = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $kanban->canais()->attach([$canalA->id, $canalB->id]);

        $this->assertCount(2, $kanban->canais);
        $this->assertTrue($canalA->kanbans->contains($kanban));

        $kanban->canais()->sync([$canalA->id]);

        $this->assertCount(1, $kanban->fresh()->canais);
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=KanbanCanalVinculoTest`
Expected: 1 passed

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_27_000002_create_kanban_whatsapp_canais_table.php app/Models/Kanban.php tests/Feature/KanbanCanalVinculoTest.php
git commit -m "feat: adiciona pivot kanban_whatsapp_canais"
```

---

### Task 3: Migration de backfill dos tenants existentes + vínculo automático ao Kanban

**Files:**
- Create: `database/migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php`
- Test: `tests/Feature/BackfillWhatsappCanaisTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal` (Task 1), `Kanban::canais()` (Task 2 — precisa estar implementada antes desta task, já que o backfill vincula os canais migrados aos Kanbans do tenant).

> Note que o número do arquivo (`000004`) é maior que o da Task 4 (`000003`, ver a seguir) — isso é intencional: a ordem de execução das migrations no banco não depende da ordem dos números entre tasks diferentes, só que cada uma exista e rode sem erro. O backfill só precisa rodar depois de `000002` (pivot) existir, o que já é garantido por esta task vir depois da Task 2 no plano.

- [ ] **Step 1: Escrever a migration de backfill**

```php
<?php

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::whereNotNull('uazapi_instance_token')->each(function (Tenant $tenant) {
            $jaMigrado = WhatsappCanal::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'uazapi')
                ->exists();

            if ($jaMigrado) {
                return; // idempotente — já rodou antes
            }

            $canal = WhatsappCanal::withoutGlobalScopes()->create([
                'tenant_id'       => $tenant->id,
                'tipo'            => 'nao_oficial',
                'provider'        => 'uazapi',
                'status'          => $tenant->whatsapp_status ?? 'disconnected',
                'phone'           => $tenant->whatsapp_phone,
                'connected_since' => $tenant->whatsapp_connected_since,
                'webhook_token'   => $tenant->uazapi_webhook_token,
                'config'          => [
                    'instance_name'  => $tenant->uazapi_instance_name,
                    'instance_token' => $tenant->uazapi_instance_token,
                ],
            ]);

            // Vincula o canal migrado a TODOS os Kanbans do tenant — sem isso,
            // a seleção de canal por Kanban (Task 5) não encontraria nenhum
            // canal vinculado e a prospecção pararia de funcionar para quem
            // já estava conectado antes desta entrega.
            $kanbanIds = Kanban::where('tenant_id', $tenant->id)->pluck('id');
            $canal->kanbans()->syncWithoutDetaching($kanbanIds);
        });
    }

    public function down(): void
    {
        // Backfill não é destrutivo o suficiente para reverter com segurança
        // (canais podem já ter sido usados por tickets criados depois do backfill).
        // Reversão manual, se necessário.
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `..._backfill_whatsapp_canais_from_tenants ... DONE`

- [ ] **Step 3: Escrever o teste do backfill**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillWhatsappCanaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_cria_canal_e_vincula_ao_kanban_do_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'uazapi_instance_name'      => 'tenant-42',
            'uazapi_instance_token'     => 'token-abc',
            'uazapi_webhook_token'      => 'webhook-xyz',
            'whatsapp_status'           => 'connected',
            'whatsapp_phone'            => '5511999999999',
            'whatsapp_connected_since'  => now(),
        ]);

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php', '--realpath' => false]);

        $canal = WhatsappCanal::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($canal);
        $this->assertSame('nao_oficial', $canal->tipo);
        $this->assertSame('uazapi', $canal->provider);
        $this->assertSame('token-abc', $canal->tokenUazapi());
        $this->assertSame('webhook-xyz', $canal->webhook_token);

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertTrue($canal->kanbans->contains($kanban));
    }

    public function test_backfill_ignora_tenants_sem_uazapi_conectado(): void
    {
        Tenant::factory()->create(['uazapi_instance_token' => null]);

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php', '--realpath' => false]);

        $this->assertSame(0, WhatsappCanal::withoutGlobalScopes()->count());
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=BackfillWhatsappCanaisTest`
Expected: 2 passed

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php tests/Feature/BackfillWhatsappCanaisTest.php
git commit -m "feat: backfill de whatsapp_canais a partir dos tenants existentes"
```

---

### Task 4: `whatsapp_canal_id` em `tickets_atendimento` + relação `TicketAtendimento::canal()`

**Files:**
- Create: `database/migrations/2026_07_27_000003_add_whatsapp_canal_id_to_tickets_atendimento.php`
- Modify: `app/Models/TicketAtendimento.php`
- Test: `tests/Feature/TicketAtendimentoCanalTest.php`

**Interfaces:**
- Produces: `TicketAtendimento::canal(): BelongsTo`, campo `whatsapp_canal_id` fillable.

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreignId('whatsapp_canal_id')->nullable()->after('contato_id')
                ->constrained('whatsapp_canais')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_canal_id');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `..._add_whatsapp_canal_id_to_tickets_atendimento ... DONE`

- [ ] **Step 3: Atualizar o model `TicketAtendimento`**

Em `app/Models/TicketAtendimento.php`, adicionar `'whatsapp_canal_id'` ao array `$fillable` (logo após `'contato_id',`):

```php
        'contato_id',
        'whatsapp_canal_id',
```

E adicionar o método, logo após `contato()`:

```php
    public function canal(): BelongsTo
    {
        return $this->belongsTo(WhatsappCanal::class, 'whatsapp_canal_id');
    }
```

- [ ] **Step 4: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_resolve_o_canal_vinculado(): void
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertSame($canal->id, $ticket->canal->id);
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoCanalTest`
Expected: 1 passed

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_27_000003_add_whatsapp_canal_id_to_tickets_atendimento.php app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoCanalTest.php
git commit -m "feat: adiciona whatsapp_canal_id em tickets_atendimento"
```

---

### Task 5: `SelecaoCanalWhatsappService` (seleção aleatória de canal não-oficial por Kanban)

**Files:**
- Create: `app/Services/SelecaoCanalWhatsappService.php`
- Test: `tests/Feature/SelecaoCanalWhatsappServiceTest.php`

**Interfaces:**
- Consumes: `Kanban::canais()` (Task 2)
- Produces: `SelecaoCanalWhatsappService::naoOficialAleatorioParaKanban(Kanban $kanban): ?WhatsappCanal` — usado nas Tasks 12 e 13.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Services\SelecaoCanalWhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelecaoCanalWhatsappServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seleciona_apenas_entre_canais_vinculados_e_conectados(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $vinculadoConectado    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $vinculadoDesconectado = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'disconnected']);
        $naoVinculado          = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $kanban->canais()->attach([$vinculadoConectado->id, $vinculadoDesconectado->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertSame($vinculadoConectado->id, $selecionado->id);
    }

    public function test_retorna_null_quando_nao_ha_canal_disponivel(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertNull($selecionado);
    }

    public function test_ignora_canais_oficiais_na_selecao_de_prospeccao(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $oficial = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'status' => 'connected']);
        $kanban->canais()->attach([$oficial->id]);

        $selecionado = app(SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban);

        $this->assertNull($selecionado);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=SelecaoCanalWhatsappServiceTest`
Expected: FAIL — `Class "App\Services\SelecaoCanalWhatsappService" not found`

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services;

use App\Models\Kanban;
use App\Models\WhatsappCanal;

class SelecaoCanalWhatsappService
{
    public function naoOficialAleatorioParaKanban(Kanban $kanban): ?WhatsappCanal
    {
        return $kanban->canais()
            ->where('tipo', 'nao_oficial')
            ->where('status', 'connected')
            ->inRandomOrder()
            ->first();
    }
}
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SelecaoCanalWhatsappServiceTest`
Expected: 3 passed

- [ ] **Step 5: Commit**

```bash
git add app/Services/SelecaoCanalWhatsappService.php tests/Feature/SelecaoCanalWhatsappServiceTest.php
git commit -m "feat: adiciona SelecaoCanalWhatsappService com sorteio v1"
```

---

### Task 6: `WhatsappCanalController` (conectar múltiplos números não-oficiais) + rotas

**Files:**
- Create: `app/Http/Controllers/Painel/WhatsappCanalController.php`
- Modify: `app/Http/Controllers/Painel/WhatsAppController.php:45-125` (remover `status()` e `qrcode()`)
- Modify: `routes/web.php:190-191`
- Test: `tests/Feature/WhatsappCanalControllerTest.php`

**Interfaces:**
- Consumes: `UazapiService` (existente, métodos `criarInstancia`, `status`, `conectar`, `configurarWebhook`, `deletarInstancia`), `WhatsappCanal` (Task 1).
- Produces: endpoints `GET/POST /api/painel/whatsapp/canais`, `GET /api/painel/whatsapp/canais/{canal}/status`, `GET /api/painel/whatsapp/canais/{canal}/qrcode`, `DELETE /api/painel/whatsapp/canais/{canal}` — consumidos pela Task 8 (UI).

- [ ] **Step 1: Remover `status()` e `qrcode()` de `WhatsAppController`**

Em `app/Http/Controllers/Painel/WhatsAppController.php`, remover os métodos `status()` (linhas 45-76) e `qrcode()` (linhas 78-125) inteiros, e remover os imports que só eles usavam (`use App\Jobs\SincronizarAgendaWhatsAppJob;` e `use App\Services\UazapiService;` e `use Illuminate\Support\Str;` — mas `UazapiService` ainda é usado no construtor injetado; como o construtor `__construct(private UazapiService $uazapi) {}` não é mais usado por nenhum método restante (`view`, `salvarRetencao`, `toggleSdrAtivo` não usam `$this->uazapi`), remova também o construtor inteiro). O arquivo final deve ficar:

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function view(): View
    {
        $tenant = request()->user()->tenant;
        return view('configuracoes.whatsapp', [
            'sdrAtivo'      => (bool) $tenant->sdr_ativo,
            'retencaoDias'  => $tenant->retencao_conversas_dias,
        ]);
    }

    public function salvarRetencao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dias' => 'nullable|integer|min:1|max:3650',
        ]);

        $tenant = $request->user()->tenant;
        $tenant->update(['retencao_conversas_dias' => $validated['dias'] ?? null]);

        return response()->json(['ok' => true, 'dias' => $tenant->fresh()->retencao_conversas_dias]);
    }

    public function toggleSdrAtivo(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->update(['sdr_ativo' => $request->boolean('sdr_ativo')]);
        return response()->json(['sdr_ativo' => $tenant->sdr_ativo]);
    }
}
```

- [ ] **Step 2: Criar `WhatsappCanalController`**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizarAgendaWhatsAppJob;
use App\Models\WhatsappCanal;
use App\Services\UazapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WhatsappCanalController extends Controller
{
    public function __construct(private UazapiService $uazapi) {}

    public function index(Request $request): JsonResponse
    {
        $canais = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'nao_oficial')
            ->orderBy('id')
            ->get(['id', 'status', 'phone', 'connected_since']);

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $nome     = 'tenant-' . $tenantId . '-' . Str::random(6);

        $result = $this->uazapi->criarInstancia($nome);

        if (! $result || ! $result['token']) {
            return response()->json(['message' => 'Erro ao criar instância WhatsApp. Tente novamente.'], 500);
        }

        $webhookToken = Str::random(48);

        $canal = WhatsappCanal::create([
            'tenant_id'     => $tenantId,
            'tipo'          => 'nao_oficial',
            'provider'      => 'uazapi',
            'status'        => 'connecting',
            'webhook_token' => $webhookToken,
            'config'        => [
                'instance_name'  => $result['name'],
                'instance_token' => $result['token'],
            ],
        ]);

        $webhookUrl = config('app.url') . '/api/webhook/uazapi/' . $webhookToken;
        $this->uazapi->configurarWebhook($result['token'], $webhookUrl, ['messages', 'connection']);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    public function status(WhatsappCanal $canal): JsonResponse
    {
        $data      = $this->uazapi->status($canal->tokenUazapi());
        $connected = $data['status']['connected'] ?? false;

        if ($connected && $canal->status !== 'connected') {
            $canal->update([
                'status'          => 'connected',
                'phone'           => $data['status']['phone'] ?? null,
                'connected_since' => now(),
            ]);

            SincronizarAgendaWhatsAppJob::dispatch($canal->id)->delay(now()->addSeconds(10));
        }

        return response()->json([
            'status'          => $connected ? 'connected' : 'disconnected',
            'phone'           => $canal->fresh()->phone,
            'connected_since' => $canal->connected_since,
        ]);
    }

    public function qrcode(WhatsappCanal $canal): JsonResponse
    {
        $statusData = $this->uazapi->status($canal->tokenUazapi());
        if ($statusData['status']['connected'] ?? false) {
            return response()->json(['message' => 'WhatsApp já está conectado.'], 409);
        }

        $qr = $this->uazapi->conectar($canal->tokenUazapi());

        if (! $qr) {
            return response()->json([
                'message' => 'QR Code ainda não disponível. Aguarde alguns segundos e tente novamente.',
            ], 503);
        }

        $qr = preg_replace('/^data:image\/[^;]+;base64,/', '', $qr);

        return response()->json(['qrcode' => $qr]);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        $this->uazapi->deletarInstancia($canal->tokenUazapi());
        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
```

Nota: como `WhatsappCanal` tem `TenantScope` como global scope, o route-model-binding (`WhatsappCanal $canal`) já aplica o filtro por tenant automaticamente (via `session('tenant_id')`, setado pelo middleware `tenant` do grupo de rotas) — um canal de outro tenant simplesmente não é encontrado (404), sem necessidade de checagem manual extra.

- [ ] **Step 3: Atualizar rotas em `routes/web.php`**

Substituir as linhas 190-191:

```php
    Route::get('/whatsapp/status', [WhatsAppController::class, 'status']);
    Route::get('/whatsapp/qrcode', [WhatsAppController::class, 'qrcode']);
```

por:

```php
    Route::get('/whatsapp/canais', [\App\Http\Controllers\Painel\WhatsappCanalController::class, 'index']);
    Route::post('/whatsapp/canais', [\App\Http\Controllers\Painel\WhatsappCanalController::class, 'store']);
    Route::get('/whatsapp/canais/{canal}/status', [\App\Http\Controllers\Painel\WhatsappCanalController::class, 'status']);
    Route::get('/whatsapp/canais/{canal}/qrcode', [\App\Http\Controllers\Painel\WhatsappCanalController::class, 'qrcode']);
    Route::delete('/whatsapp/canais/{canal}', [\App\Http\Controllers\Painel\WhatsappCanalController::class, 'destroy']);
```

- [ ] **Step 4: Escrever o teste do controller**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappCanalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_apenas_canais_nao_oficiais_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'nao_oficial']);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial']);
        WhatsappCanal::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'tipo' => 'nao_oficial']);

        $response = $this->actingAs($user)->getJson('/api/painel/whatsapp/canais');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_cria_novo_canal_nao_oficial(): void
    {
        Http::fake([
            '*/instance/create' => Http::response([
                'token'    => 'novo-token',
                'instance' => ['id' => 1, 'name' => 'inst-1', 'status' => 'connecting'],
            ], 200),
            '*/webhook' => Http::response(['ok' => true], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais');

        $response->assertCreated();
        $this->assertDatabaseHas('whatsapp_canais', [
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi', 'status' => 'connecting',
        ]);
    }

    public function test_nao_acessa_canal_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $user         = $this->usuarioDono($tenant);
        $canalDeOutro = WhatsappCanal::factory()->create(['tenant_id' => $outroTenant->id]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais/{$canalDeOutro->id}");

        $response->assertNotFound();
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=WhatsappCanalControllerTest`
Expected: 3 passed

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/WhatsappCanalController.php app/Http/Controllers/Painel/WhatsAppController.php routes/web.php tests/Feature/WhatsappCanalControllerTest.php
git commit -m "feat: WhatsappCanalController permite conectar múltiplos números não-oficiais"
```

---

### Task 7: `SincronizarAgendaWhatsAppJob` passa a operar por canal, não por tenant

**Files:**
- Modify: `app/Jobs/SincronizarAgendaWhatsAppJob.php` (inteiro)
- Test: `tests/Feature/SincronizarAgendaWhatsAppJobTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal::tokenUazapi()` (Task 1)
- Produces: `SincronizarAgendaWhatsAppJob(int $whatsappCanalId)` — assinatura muda de `int $tenantId` para `int $whatsappCanalId` (já refletido na chamada da Task 6, `status()`).

- [ ] **Step 1: Reescrever o job**

```php
<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\UazapiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Disparado automaticamente quando um canal WhatsApp não-oficial conecta pela
 * primeira vez. Importa todos os contatos da agenda do celular → CRM + Google + ticket.
 */
class SincronizarAgendaWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public int $whatsappCanalId) {}

    public function handle(UazapiService $uazapi): void
    {
        $canal = WhatsappCanal::withoutGlobalScopes()->find($this->whatsappCanalId);

        if (! $canal || ! $canal->tokenUazapi()) {
            return;
        }

        $tenant = $canal->tenant;

        $contatos = $uazapi->listarContatos($canal->tokenUazapi());

        if (empty($contatos)) {
            Log::info("SincronizarAgendaWhatsApp: sem contatos para canal #{$this->whatsappCanalId}");
            return;
        }

        $personaId = $tenant->personas()
            ->where('is_default', true)
            ->where('ativo', true)
            ->value('id');

        $criados = 0;

        foreach ($contatos as $wa) {
            $jid  = $wa['jid'] ?? null;
            $nome = $this->limparNome($wa['contact_name'] ?? '', $wa['contact_FirstName'] ?? '');

            if (! $jid || ! str_contains($jid, '@s.whatsapp.net') || ! $nome) {
                continue;
            }

            $telefone = preg_replace('/@.+$/', '', $jid);

            $contato = Contato::where('telefone', $telefone)
                ->orWhere('telefone', ltrim($telefone, '55'))
                ->first();

            if ($contato) {
                VinculoContatoTenant::firstOrCreate([
                    'contato_id' => $contato->id,
                    'tenant_id'  => $tenant->id,
                ]);
                continue;
            }

            $contato = Contato::create([
                'telefone' => $telefone,
                'nome'     => $nome ?: 'Sem Nome',
                'origem'   => 'whatsapp_agenda',
                'opt_out'  => false,
            ]);

            VinculoContatoTenant::firstOrCreate([
                'contato_id' => $contato->id,
                'tenant_id'  => $tenant->id,
            ]);

            PushContatoParaGoogleJob::dispatch($contato->id, $tenant->id);

            $temTicket = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('contato_id', $contato->id)
                ->whereIn('status', ['aberto', 'aguardando'])
                ->exists();

            if (! $temTicket) {
                TicketAtendimento::withoutGlobalScopes()->create([
                    'tenant_id'          => $tenant->id,
                    'contato_id'         => $contato->id,
                    'whatsapp_canal_id'  => $canal->id,
                    'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
                    'agente_responsavel' => 'humano',
                    'sdr_persona_id'     => $personaId,
                    'status'             => 'aberto',
                    'aberto_em'          => now(),
                    'origem'             => 'whatsapp_agenda',
                ]);
            }

            $criados++;
        }

        Log::info("SincronizarAgendaWhatsApp: canal #{$this->whatsappCanalId} — {$criados} novos contatos importados");
    }

    private function limparNome(string $contactName, string $firstName): string
    {
        $nome = trim($firstName) ?: trim($contactName);
        if (! $nome) return '';
        return trim(preg_replace('/^(.+\s.+)\s\d{4}$/', '$1', $nome));
    }
}
```

- [ ] **Step 2: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SincronizarAgendaWhatsAppJob;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarAgendaWhatsAppJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_contato_e_marca_o_canal_no_ticket_criado(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511988887777@s.whatsapp.net', 'contact_name' => 'Fulano', 'contact_FirstName' => 'Fulano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        (new SincronizarAgendaWhatsAppJob($canal->id))->handle(app(\App\Services\UazapiService::class));

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
```

(`UazapiService::listarContatos()` chama `GET {baseUrl}/contacts` — confirmado em `app/Services/UazapiService.php:378-383`.)

- [ ] **Step 3: Rodar os testes**

Run: `php artisan test --filter=SincronizarAgendaWhatsAppJobTest`
Expected: 1 passed

- [ ] **Step 4: Commit**

```bash
git add app/Jobs/SincronizarAgendaWhatsAppJob.php tests/Feature/SincronizarAgendaWhatsAppJobTest.php
git commit -m "refactor: SincronizarAgendaWhatsAppJob opera por canal, não por tenant"
```

---

### Task 8: Refazer `configuracoes/whatsapp.blade.php` — seção não-oficial vira lista de números

**Files:**
- Modify: `resources/views/configuracoes/whatsapp.blade.php:116-273` (bloco `x-data="whatsapp()"` até o fim do `<script>`)
- Test: `tests/Feature/ConfiguracoesWhatsappViewTest.php`

**Interfaces:**
- Consumes: endpoints da Task 6 (`/api/painel/whatsapp/canais*`).

- [ ] **Step 1: Substituir o bloco `<div x-data="whatsapp()" ...>` até o fim do arquivo**

Substituir do início da linha 116 (`<div x-data="whatsapp()" x-init="verificarStatus()">`) até o fim do arquivo (linha 273, incluindo o `@endsection`) por:

```blade
    <div x-data="whatsappCanais()" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Não-Oficial</h1>
        <button @click="conectarNovo()" :disabled="conectando"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
            + Conectar novo número
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        Conexão direta via QR Code (WhatsApp comum, tecnologia Baileys) — sem garantias de entrega da Meta.
        Use para prospecção. Para o número que recebe leads de anúncios, veja a API Oficial (em breve nesta página).
    </p>

    <div class="space-y-4">
        <template x-for="canal in canais" :key="canal.id">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <template x-if="canal.status === 'connected'">
                            <span class="flex items-center gap-2 text-green-600 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                                Conectado <span class="text-gray-400 font-normal" x-text="canal.phone"></span>
                            </span>
                        </template>
                        <template x-if="canal.status !== 'connected'">
                            <span class="flex items-center gap-2 text-gray-500 font-medium text-sm">
                                <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                                Desconectado
                            </span>
                        </template>
                    </div>
                    <button @click="excluirCanal(canal)" class="text-red-300 hover:text-red-500 text-xs">Remover</button>
                </div>

                <template x-if="canal.status !== 'connected'">
                    <div class="flex justify-center">
                        <template x-if="canal.qrcode">
                            <img :src="'data:image/png;base64,' + canal.qrcode" class="w-48 h-48 border border-gray-200 rounded-xl p-2">
                        </template>
                        <template x-if="!canal.qrcode">
                            <button @click="gerarQr(canal)"
                                    class="w-48 h-48 border-2 border-dashed border-gray-300 rounded-xl flex items-center justify-center text-gray-400 hover:border-green-400 hover:text-green-500 text-sm font-medium transition-colors">
                                Gerar QR Code
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="canais.length === 0 && !conectando">
            <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhum número não-oficial conectado ainda.
            </div>
        </template>
    </div>

    </div>

<script>
function whatsappCanais() {
    return {
        canais: [],
        conectando: false,
        intervalos: {},

        async carregar() {
            const res = await fetch('/api/painel/whatsapp/canais', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) this.canais = await res.json();
        },

        async conectarNovo() {
            this.conectando = true;
            const res = await fetch('/api/painel/whatsapp/canais', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            this.conectando = false;
            if (res.ok) {
                await this.carregar();
            }
        },

        async gerarQr(canal) {
            const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}/qrcode`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) {
                const data = await res.json();
                canal.qrcode = data.qrcode;
                this.iniciarPolling(canal);
            }
        },

        iniciarPolling(canal) {
            clearInterval(this.intervalos[canal.id]);
            this.intervalos[canal.id] = setInterval(async () => {
                const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}/status`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                });
                if (res.ok) {
                    const data = await res.json();
                    canal.status = data.status;
                    canal.phone  = data.phone;
                    if (canal.status === 'connected') {
                        canal.qrcode = null;
                        clearInterval(this.intervalos[canal.id]);
                    }
                }
            }, 3000);
        },

        async excluirCanal(canal) {
            if (!confirm('Remover este número? Essa ação não pode ser desfeita.')) return;
            const res = await fetch(`/api/painel/whatsapp/canais/${canal.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) await this.carregar();
        },
    };
}
</script>
@endsection
```

- [ ] **Step 2: Escrever um teste de smoke da view**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracoesWhatsappViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_de_configuracoes_whatsapp_carrega_sem_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get(route('configuracoes'));

        $response->assertOk();
        $response->assertSee('WhatsApp Não-Oficial');
    }
}
```

- [ ] **Step 3: Rodar os testes**

Run: `php artisan test --filter=ConfiguracoesWhatsappViewTest`
Expected: 1 passed

- [ ] **Step 4: Testar manualmente no navegador**

Acesse a página de configurações logado como dono de um tenant de teste, clique em "Conectar novo número" e confirme que um novo card aparece com botão de gerar QR Code.

- [ ] **Step 5: Commit**

```bash
git add resources/views/configuracoes/whatsapp.blade.php tests/Feature/ConfiguracoesWhatsappViewTest.php
git commit -m "feat: tela de configurações WhatsApp lista múltiplos números não-oficiais"
```

---

### Task 9: Vínculo de canais por Kanban — `KanbanCanalController` + rotas

**Files:**
- Create: `app/Http/Controllers/Painel/KanbanCanalController.php`
- Modify: `routes/web.php` (grupo de rotas perto da linha 350-366)
- Test: `tests/Feature/KanbanCanalControllerTest.php`

**Interfaces:**
- Consumes: `Kanban::canais()` (Task 2), `WhatsappCanal` (Task 1).
- Produces: `GET /api/painel/kanban/canais`, `PUT /api/painel/kanban/canais` — consumidos pela Task 10 (UI).

- [ ] **Step 1: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanCanalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        $vinculadosIds = $kanban->canais()->pluck('whatsapp_canais.id')->all();

        $canais = WhatsappCanal::where('tenant_id', $tenantId)
            ->orderBy('tipo')
            ->orderBy('id')
            ->get(['id', 'tipo', 'provider', 'status', 'phone'])
            ->map(fn (WhatsappCanal $c) => [
                'id'        => $c->id,
                'tipo'      => $c->tipo,
                'provider'  => $c->provider,
                'status'    => $c->status,
                'phone'     => $c->phone,
                'vinculado' => in_array($c->id, $vinculadosIds, true),
            ]);

        return response()->json($canais);
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal_ids'   => 'present|array',
            'canal_ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        $idsValidos = WhatsappCanal::where('tenant_id', $tenantId)
            ->whereIn('id', $dados['canal_ids'])
            ->pluck('id')
            ->all();

        $kanban->canais()->sync($idsValidos);

        return response()->json(['sincronizado' => true]);
    }
}
```

- [ ] **Step 2: Adicionar as rotas**

Em `routes/web.php`, no grupo `role:admin,dono` que já contém `/kanban/colunas` (perto da linha 350-366), adicionar logo após a linha `Route::post('/kanban/colunas/reordenar', [KanbanColunaController::class, 'reordenar']);`:

```php
        // Vínculo de canais WhatsApp por Kanban
        Route::get('/kanban/canais', [\App\Http\Controllers\Painel\KanbanCanalController::class, 'index']);
        Route::put('/kanban/canais', [\App\Http\Controllers\Painel\KanbanCanalController::class, 'update']);
```

- [ ] **Step 3: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_canais_do_tenant_com_flag_vinculado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $kanban->canais()->attach($canal->id);
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]); // não vinculado

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/canais');

        $response->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertTrue(collect($response->json())->firstWhere('id', $canal->id)['vinculado']);
    }

    public function test_sincroniza_canais_vinculados(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canalA = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $canalB = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais', [
            'canal_ids' => [$canalA->id],
        ]);

        $response->assertOk();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertCount(1, $kanban->canais);
        $this->assertSame($canalA->id, $kanban->canais->first()->id);
    }

    public function test_nao_vincula_canal_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $user         = $this->usuarioDono($tenant);
        $canalDeOutro = WhatsappCanal::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais', [
            'canal_ids' => [$canalDeOutro->id],
        ]);

        $response->assertOk();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertCount(0, $kanban->canais);
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=KanbanCanalControllerTest`
Expected: 3 passed

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanCanalController.php routes/web.php tests/Feature/KanbanCanalControllerTest.php
git commit -m "feat: KanbanCanalController vincula canais WhatsApp ao Kanban"
```

---

### Task 10: UI de vínculo de canais na tela `kanban/config.blade.php`

**Files:**
- Modify: `resources/views/kanban/config.blade.php:71` (inserir card antes de `{{-- Tabs de colunas --}}`)
- Modify: `resources/views/kanban/config.blade.php:1202` (novo estado Alpine)
- Modify: `resources/views/kanban/config.blade.php:1204-1214` (`carregar()`)

**Interfaces:**
- Consumes: endpoints da Task 9.

- [ ] **Step 1: Inserir o novo card HTML**

Em `resources/views/kanban/config.blade.php`, logo depois da linha 71 (`</div>` que fecha o card "Colunas do Kanban") e antes da linha 73 (`{{-- Tabs de colunas --}}`), inserir:

```blade
    {{-- Canais de WhatsApp usados por este Kanban --}}
    <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm mb-6">
        <div class="mb-3">
            <h2 class="font-bold text-gray-800 text-base">Canais de WhatsApp</h2>
            <p class="text-xs text-gray-400 mt-0.5">Escolha quais números (oficiais e não-oficiais) este Kanban usa. A prospecção sorteia entre os não-oficiais marcados.</p>
        </div>

        <div class="space-y-2">
            <template x-for="c in canaisDisponiveis" :key="c.id">
                <label class="flex items-center gap-3 border border-gray-200 rounded-xl px-3 py-2 bg-gray-50 cursor-pointer">
                    <input type="checkbox" :checked="c.vinculado" @change="toggleCanal(c)" class="rounded border-gray-300 text-green-600">
                    <span class="text-xs font-medium px-1.5 py-0.5 rounded"
                          :class="c.tipo === 'oficial' ? 'bg-blue-100 text-blue-700' : 'bg-gray-200 text-gray-600'"
                          x-text="c.tipo === 'oficial' ? 'Oficial' : 'Não-Oficial'"></span>
                    <span class="text-sm text-gray-700 flex-1" x-text="c.phone || '(ainda não conectado)'"></span>
                    <span class="text-xs" :class="c.status === 'connected' ? 'text-green-600' : 'text-gray-400'" x-text="c.status"></span>
                </label>
            </template>

            <template x-if="canaisDisponiveis.length === 0">
                <div class="text-center py-4 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                    Nenhum canal de WhatsApp conectado ainda.
                </div>
            </template>
        </div>
    </div>

```

- [ ] **Step 2: Adicionar estado Alpine**

Logo após `colunaDragIndex: null,` (linha 1202), adicionar:

```javascript
        canaisDisponiveis: [],
```

- [ ] **Step 3: Carregar e sincronizar canais**

Na função `carregar()` (linha 1204-1214), adicionar a chamada, logo após `await this.carregarColunas();`:

```javascript
            await this.carregarCanais();
```

E adicionar os dois novos métodos, logo após o fechamento de `carregarColunas()`:

```javascript
        async carregarCanais() {
            const res = await this.api('/api/painel/kanban/canais');
            if (res.ok) this.canaisDisponiveis = await res.json();
        },

        async toggleCanal(canal) {
            canal.vinculado = !canal.vinculado;
            const idsVinculados = this.canaisDisponiveis.filter(c => c.vinculado).map(c => c.id);
            const res = await this.api('/api/painel/kanban/canais', 'PUT', { canal_ids: idsVinculados });
            if (!res.ok) {
                canal.vinculado = !canal.vinculado; // reverte em caso de erro
                this.mostrarToast('Não foi possível atualizar o vínculo do canal.', 'erro');
            }
        },
```

- [ ] **Step 4: Testar manualmente no navegador**

Acesse `/kanban/config`, confirme que o card "Canais de WhatsApp" aparece com os números já conectados, marque/desmarque um checkbox e confirme (via `php artisan tinker` ou reload da página) que o vínculo persiste.

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: tela de configuração do Kanban permite vincular canais WhatsApp"
```

---

### Task 11: `UazapiWebhookController` resolve o canal (não mais o tenant) e propaga `whatsapp_canal_id`

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php` (inteiro)
- Test: rodar a suíte de webhook existente (`tests/Feature/UazapiWebhook*.php`, 7 arquivos)

**Interfaces:**
- Consumes: `WhatsappCanal` (Task 1, resolvido via `webhook_token` — já copiado dos tenants existentes na Task 3 de backfill, então o valor da URL do webhook **não muda** para quem já estava conectado).
- Produces: tickets criados/reativados pelo webhook agora têm `whatsapp_canal_id` preenchido.

- [ ] **Step 1: Trocar a resolução do tenant pela resolução do canal em `handle()`**

Substituir (linhas 26-49):

```php
    public function handle(Request $request, string $webhookToken): JsonResponse
    {
        // Autentica pelo token opaco na URL — lookup por coluna unique
        $tenant = Tenant::where('uazapi_webhook_token', $webhookToken)->first();

        if (! $tenant) {
            Log::warning('Uazapi webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
            abort(401);
        }

        $payload = $request->all();

        $tipo = $payload['EventType'] ?? null;

        Log::debug('Uazapi webhook recebido', ['tenant' => $tenant->id, 'EventType' => $tipo]);

        match ($tipo) {
            'messages'   => $this->handleMensagem($payload, $tenant),
            'connection' => $this->handleConexao($payload, $tenant),
            default      => null,
        };

        return response()->json(['ok' => true]);
    }
```

por:

```php
    public function handle(Request $request, string $webhookToken): JsonResponse
    {
        // Autentica pelo token opaco na URL — lookup por coluna unique
        $canal = \App\Models\WhatsappCanal::withoutGlobalScopes()->where('webhook_token', $webhookToken)->first();

        if (! $canal) {
            Log::warning('Uazapi webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
            abort(401);
        }

        $tenant = $canal->tenant;

        $payload = $request->all();

        $tipo = $payload['EventType'] ?? null;

        Log::debug('Uazapi webhook recebido', ['tenant' => $tenant->id, 'canal' => $canal->id, 'EventType' => $tipo]);

        match ($tipo) {
            'messages'   => $this->handleMensagem($payload, $tenant, $canal),
            'connection' => $this->handleConexao($payload, $canal),
            default      => null,
        };

        return response()->json(['ok' => true]);
    }
```

Remover o `use App\Models\Tenant;` só se `Tenant` não for mais usado em nenhuma assinatura — ele continua sendo usado como tipo do parâmetro `Tenant $tenant` nos métodos privados, então **mantenha o import**.

- [ ] **Step 2: Propagar `$canal` por `handleMensagem` e usar o token do canal**

Substituir a assinatura e o corpo de `handleMensagem` (linhas 55-128) — trocar `private function handleMensagem(array $payload, Tenant $tenant): void` por `private function handleMensagem(array $payload, Tenant $tenant, \App\Models\WhatsappCanal $canal): void`, e dentro do método:

- Linha 121: `$this->transferirParaHumano($tenant, $telefone, $conteudo, $msg, $tenant->uazapi_instance_token);` → `$this->transferirParaHumano($tenant, $telefone, $conteudo, $msg, $canal);`
- Linha 127: `$this->processarMensagemLead($tenant, $telefone, $conteudo, $pushName, $msg, $tenant->uazapi_instance_token);` → `$this->processarMensagemLead($tenant, $telefone, $conteudo, $pushName, $msg, $canal);`
- Linha 113-115 (chamada perdida): `$this->processarChamadaWhatsApp($tenant, $telefone, $pushName);` → `$this->processarChamadaWhatsApp($tenant, $telefone, $pushName, $canal);`

- [ ] **Step 3: Atualizar `processarMensagemLead` para receber o canal e gravar `whatsapp_canal_id`**

Trocar a assinatura (linha 130):

```php
    private function processarMensagemLead(Tenant $tenant, string $telefone, ?string $conteudo, ?string $pushName, array $msg = [], string $instanceToken = ''): void
```

por:

```php
    private function processarMensagemLead(Tenant $tenant, string $telefone, ?string $conteudo, ?string $pushName, array $msg, \App\Models\WhatsappCanal $canal): void
```

Dentro do método:
- Linha 219 (criação de ticket novo), adicionar `'whatsapp_canal_id' => $canal->id,` logo após `'contato_id' => $contato->id,`.
- Linha 237 (`if ($mediaType && $instanceToken)`) → `if ($mediaType && $canal->tokenUazapi())`, e as 3 ocorrências de `$instanceToken` dentro desse bloco (linhas 246, 253, 259) → `$canal->tokenUazapi()`.

- [ ] **Step 4: Atualizar `processarChamadaWhatsApp` para receber o canal e gravar `whatsapp_canal_id`**

Trocar a assinatura (linha 343):

```php
    private function processarChamadaWhatsApp(Tenant $tenant, string $telefone, ?string $pushName): void
```

por:

```php
    private function processarChamadaWhatsApp(Tenant $tenant, string $telefone, ?string $pushName, \App\Models\WhatsappCanal $canal): void
```

E na criação do ticket (linha 375), adicionar `'whatsapp_canal_id' => $canal->id,` logo após `'contato_id' => $contato->id,`.

- [ ] **Step 5: Atualizar `transferirParaHumano` para usar o token do canal**

Trocar a assinatura (linha 599):

```php
    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo, array $msg = [], string $instanceToken = ''): void
```

por:

```php
    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo, array $msg, \App\Models\WhatsappCanal $canal): void
```

E dentro do método, linha 628 (`if ($mediaType && $instanceToken && ...)`) → `if ($mediaType && $canal->tokenUazapi() && ...)`, e a linha 630 (`$midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrl($msg, $instanceToken, $mediaType);`) → `..., $canal->tokenUazapi(), $mediaType);`.

- [ ] **Step 6: Atualizar `handleConexao` para atualizar o canal, não o tenant**

Substituir (linhas 665-678):

```php
    private function handleConexao(array $payload, Tenant $tenant): void
    {
        $status = $payload['data']['status'] ?? null;

        if ($status === 'open') {
            $tenant->update([
                'whatsapp_status'          => 'connected',
                'whatsapp_connected_since' => now(),
            ]);
        } elseif (in_array($status, ['close', 'connecting', 'timeout'])) {
            $tenant->update(['whatsapp_status' => 'disconnected']);
            Log::warning("Tenant #{$tenant->id} WhatsApp desconectado", ['status' => $status]);
        }
    }
```

por:

```php
    private function handleConexao(array $payload, \App\Models\WhatsappCanal $canal): void
    {
        $status = $payload['data']['status'] ?? null;

        if ($status === 'open') {
            $canal->update([
                'status'          => 'connected',
                'connected_since' => now(),
            ]);
        } elseif (in_array($status, ['close', 'connecting', 'timeout'])) {
            $canal->update(['status' => 'disconnected']);
            Log::warning("Canal #{$canal->id} WhatsApp desconectado", ['status' => $status]);
        }
    }
```

- [ ] **Step 7: Rodar a suíte de testes de webhook existente**

Run: `php artisan test --filter=UazapiWebhook`
Expected: todos os testes das 7 suítes (`UazapiWebhookColunaDinamicaTest`, `UazapiWebhookReativacaoTest`, `UazapiWebhookMidiaTest`, `UazapiWebhookNomeExtraidoTest`, `UazapiWebhookFollowupResetTest`, `UazapiWebhookButtonTest`) continuam passando — o valor do `webhook_token` na URL não muda (veio do backfill da Task 3), só a fonte de resolução interna. Se algum teste falhar por acessar `$tenant->whatsapp_status` diretamente após simular conexão, ajuste a asserção para ler o `WhatsappCanal` correspondente em vez do `Tenant`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php
git commit -m "refactor: webhook Uazapi resolve WhatsappCanal em vez de Tenant"
```

---

### Task 12: Consumidores com ticket em mãos passam a resolver o token via `$ticket->canal`

**Files:**
- Modify: `app/Services/KanbanBotaoActionService.php:91`
- Modify: `app/Jobs/SequenciaMensagemJob.php:64,69`
- Modify: `app/Services/SdrResponderService.php:90-101`
- Modify: `app/Console/Commands/FollowupConversas.php:199-200`
- Modify: `app/Jobs/FormularioLeadJob.php:43-46,53-56,64-68`
- Test: `tests/Feature/EnvioUsaCanalDoTicketTest.php`

**Interfaces:**
- Consumes: `TicketAtendimento::canal()` (Task 4), `WhatsappCanal::tokenUazapi()` (Task 1).

- [ ] **Step 1: `KanbanBotaoActionService.php:90-91`**

Substituir:

```php
        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->tenant?->uazapi_instance_token;
```

por:

```php
        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->canal?->tokenUazapi();
```

- [ ] **Step 2: `SequenciaMensagemJob.php:61-69`**

Substituir:

```php
        $telefone = $ticket->contato?->telefone;
        $tenant   = $ticket->tenant;

        if (! $telefone || ! $tenant?->uazapi_instance_token) {
            Log::warning('SequenciaMensagemJob: sem telefone ou token', ['ticket_id' => $this->ticketId]);
            return;
        }

        $token = $tenant->uazapi_instance_token;
```

por:

```php
        $telefone = $ticket->contato?->telefone;
        $tenant   = $ticket->tenant;
        $token    = $ticket->canal?->tokenUazapi();

        if (! $telefone || ! $token) {
            Log::warning('SequenciaMensagemJob: sem telefone ou token', ['ticket_id' => $this->ticketId]);
            return;
        }
```

E ajustar a linha 31 (`$ticket = TicketAtendimento::with(['contato', 'tenant'])->find($this->ticketId);`) para eager-loadar o canal também: `TicketAtendimento::with(['contato', 'tenant', 'canal'])->find($this->ticketId);`.

- [ ] **Step 3: `SdrResponderService.php:87-102`**

Substituir:

```php
        $tenant   = $ticket->tenant;
        $telefone = $ticket->contato?->telefone;

        if ($telefone && $tenant?->uazapi_instance_token) {
            $this->humanizacao->processar(
                $tenant->uazapi_instance_token,
                $telefone,
                $resposta
            );
        } else {
            Log::warning('SdrResponder: sem token ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_token' => (bool) $tenant?->uazapi_instance_token,
            ]);
        }
```

por:

```php
        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->canal?->tokenUazapi();

        if ($telefone && $token) {
            $this->humanizacao->processar($token, $telefone, $resposta);
        } else {
            Log::warning('SdrResponder: sem token ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_token' => (bool) $token,
            ]);
        }
```

E ajustar a linha 27 (`$ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant']);`) para incluir `'canal'`: `$ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant', 'canal']);`.

- [ ] **Step 4: `FollowupConversas.php:199-200`**

Substituir:

```php
            $telefone = $ticket->contato?->telefone;
            $token    = $ticket->tenant?->uazapi_instance_token;
```

por:

```php
            $telefone = $ticket->contato?->telefone;
            $token    = $ticket->canal?->tokenUazapi();
```

- [ ] **Step 5: `FormularioLeadJob.php:43-68`**

Substituir:

```php
        $tenant   = $formulario->tenant;
        $telefone = $ticket->contato?->telefone;

        if (! $telefone || ! $tenant?->uazapi_instance_token) {
            Log::warning("FormularioLeadJob: sem telefone ou token Uazapi", ['envio' => $this->envioId]);
            return;
        }

        if ($formulario->double_optin) {
            // Double opt-in: envia confirmação antes de disparar o bot
            $humanizacao->processar(
                $tenant->uazapi_instance_token,
                $telefone,
                "Olá! Recebemos seu cadastro. ✅\n\nResponda *SIM* para confirmar que foi você mesmo que preencheu."
            );

            $envio->update(['processado' => true]);
            return;
        }

        if ($formulario->acao_pos_envio === 'mensagem_unica' && $formulario->mensagem_custom) {
            $humanizacao->processar(
                $tenant->uazapi_instance_token,
                $telefone,
                $formulario->mensagem_custom
            );

            $ticket->update(['agente_responsavel' => 'humano']);
            $envio->update(['processado' => true]);
            return;
        }
```

por:

```php
        $telefone = $ticket->contato?->telefone;
        $token    = $ticket->canal?->tokenUazapi();

        if (! $telefone || ! $token) {
            Log::warning("FormularioLeadJob: sem telefone ou token Uazapi", ['envio' => $this->envioId]);
            return;
        }

        if ($formulario->double_optin) {
            // Double opt-in: envia confirmação antes de disparar o bot
            $humanizacao->processar(
                $token,
                $telefone,
                "Olá! Recebemos seu cadastro. ✅\n\nResponda *SIM* para confirmar que foi você mesmo que preencheu."
            );

            $envio->update(['processado' => true]);
            return;
        }

        if ($formulario->acao_pos_envio === 'mensagem_unica' && $formulario->mensagem_custom) {
            $humanizacao->processar(
                $token,
                $telefone,
                $formulario->mensagem_custom
            );

            $ticket->update(['agente_responsavel' => 'humano']);
            $envio->update(['processado' => true]);
            return;
        }
```

E ajustar a linha 35-37 (`$ticket = TicketAtendimento::withoutGlobalScopes()->with('contato')->find($this->ticketId);`) para incluir `'canal'`: `->with(['contato', 'canal'])`.

- [ ] **Step 6: Escrever o teste consolidado**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvioUsaCanalDoTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdr_responder_envia_pelo_token_do_canal_do_ticket_nao_do_tenant(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg1'], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-legado-do-tenant']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-do-canal-certo'],
        ]);
        $contato = Contato::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'joao_teste', 'nome_display' => 'João',
            'system_prompt' => 'Você é um SDR de teste.', 'is_default' => true, 'ativo' => true,
        ]);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'sdr_persona_id' => $persona->id,
            'status' => 'aberto', 'aberto_em' => now(), 'etapa_ia' => 'etapa_1',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Oi! Tudo certo por aqui.']]],
            ], 200),
            '*/send/text' => Http::response(['id' => 'msg1'], 200),
        ]);

        app(SdrResponderService::class)->responder($ticket);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-do-canal-certo'));
    }
}
```

(`OpenRouterService` chama `https://openrouter.ai/api/v1/chat/completions` — confirmado em `app/Services/OpenRouterService.php:11`. `SdrPersona` não tem factory própria — o teste cria via `::create()` direto com os campos obrigatórios de `app/Models/SdrPersona.php`.)

- [ ] **Step 7: Rodar os testes**

Run: `php artisan test --filter=EnvioUsaCanalDoTicketTest`
Expected: 1 passed (ajuste o teste conforme a nota acima se o endpoint do OpenRouter for diferente)

- [ ] **Step 8: Rodar toda a suíte de testes do projeto para checar regressão**

Run: `php artisan test`
Expected: nenhuma falha nova introduzida pelas mudanças desta task

- [ ] **Step 9: Commit**

```bash
git add app/Services/KanbanBotaoActionService.php app/Jobs/SequenciaMensagemJob.php app/Services/SdrResponderService.php app/Console/Commands/FollowupConversas.php app/Jobs/FormularioLeadJob.php tests/Feature/EnvioUsaCanalDoTicketTest.php
git commit -m "refactor: envio de mensagens resolve token via canal do ticket"
```

---

### Task 13: Novos tickets de prospecção recebem canal sorteado do Kanban de entrada

**Files:**
- Modify: `app/Services/FormularioService.php:131-140`
- Modify: `app/Http/Controllers/Api/SecretariaEletronicaController.php:111-119`
- Modify: `app/Http/Controllers/Internal/TicketController.php:28-38`
- Test: `tests/Feature/NovoTicketSorteiaCanalTest.php`

**Interfaces:**
- Consumes: `SelecaoCanalWhatsappService::naoOficialAleatorioParaKanban()` (Task 5).

- [ ] **Step 1: `FormularioService.php:132-140`**

Substituir:

```php
        // Cria ticket
        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'formulario',
            'formulario_id'      => $formulario->id,
        ]);
```

por:

```php
        // Cria ticket
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $canal  = $kanban ? app(\App\Services\SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban) : null;

        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'whatsapp_canal_id'  => $canal?->id,
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'formulario',
            'formulario_id'      => $formulario->id,
        ]);
```

- [ ] **Step 2: `SecretariaEletronicaController.php:112-119`**

Substituir:

```php
        // Cria ticket
        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'ligacao',
        ]);
```

por:

```php
        // Cria ticket
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $canal  = $kanban ? app(\App\Services\SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban) : null;

        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'whatsapp_canal_id'  => $canal?->id,
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'ligacao',
        ]);
```

- [ ] **Step 3: `Internal/TicketController.php:28-38`**

Substituir:

```php
        if (! $ticket) {
            $ticket = TicketAtendimento::create([
                'tenant_id'          => $request->tenant_id,
                'contato_id'         => $request->contato_id,
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id),
                'agente_responsavel' => 'bot',
                'etapa_ia'           => 'etapa_1',
                'status'             => 'aberto',
            ]);
            $novo = true;
        }
```

por:

```php
        if (! $ticket) {
            $kanban = \App\Models\Kanban::where('tenant_id', $request->tenant_id)->where('tipo', 'vendas')->first();
            $canal  = $kanban ? app(\App\Services\SelecaoCanalWhatsappService::class)->naoOficialAleatorioParaKanban($kanban) : null;

            $ticket = TicketAtendimento::create([
                'tenant_id'          => $request->tenant_id,
                'contato_id'         => $request->contato_id,
                'whatsapp_canal_id'  => $canal?->id,
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id),
                'agente_responsavel' => 'bot',
                'etapa_ia'           => 'etapa_1',
                'status'             => 'aberto',
            ]);
            $novo = true;
        }
```

- [ ] **Step 4: Escrever o teste consolidado**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NovoTicketSorteiaCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_interno_recebe_canal_vinculado_ao_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $kanban->canais()->attach($canal->id);
        $contato = \App\Models\Contato::factory()->create();

        config(['app.service_key' => 'chave-de-teste']);

        $response = $this->postJson('/api/internal/ticket', [
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], ['X-Service-Key' => 'chave-de-teste']);

        $response->assertOk();
        $ticket = \App\Models\TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
```

(Rota confirmada em `routes/api.php:24` — `POST /internal/ticket` com prefixo automático `/api`, middleware `service.key` = `app/Http/Middleware/EnsureServiceKey.php`, que exige o header `X-Service-Key` igual a `config('app.service_key')`.)

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=NovoTicketSorteiaCanalTest`
Expected: 1 passed (ajuste conforme a nota acima)

- [ ] **Step 6: Commit**

```bash
git add app/Services/FormularioService.php app/Http/Controllers/Api/SecretariaEletronicaController.php app/Http/Controllers/Internal/TicketController.php tests/Feature/NovoTicketSorteiaCanalTest.php
git commit -m "feat: novos tickets de prospecção sorteiam canal vinculado ao Kanban"
```

---

### Task 14: Jobs de sincronização/importação iteram canais, não tenants

**Files:**
- Modify: `app/Console/Commands/ImportarParticipantesGrupos.php:18-37,48,134`
- Modify: `app/Console/Commands/SincronizarContatosWhatsApp.php:18-45,134`
- Test: `tests/Feature/SincronizacaoIteraPorCanalTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal` (Task 1).

- [ ] **Step 1: `ImportarParticipantesGrupos.php:18-37`**

Substituir:

```php
    public function handle(UazapiService $uazapi): int
    {
        $query = Tenant::whereNotNull('uazapi_instance_token');

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        }

        foreach ($query->get() as $tenant) {
            $this->info("Tenant #{$tenant->id} — {$tenant->nome}");
            $this->importar($tenant, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function importar(Tenant $tenant, UazapiService $uazapi): void
    {
        $this->line('  Buscando grupos...');
        $grupos = $uazapi->listarGrupos($tenant->uazapi_instance_token);
```

por:

```php
    public function handle(UazapiService $uazapi): int
    {
        $query = \App\Models\WhatsappCanal::withoutGlobalScopes()
            ->where('tipo', 'nao_oficial')
            ->where('status', 'connected');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->get() as $canal) {
            $tenant = $canal->tenant;
            $this->info("Tenant #{$tenant->id} — {$tenant->nome} (canal #{$canal->id})");
            $this->importar($tenant, $canal, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function importar(Tenant $tenant, \App\Models\WhatsappCanal $canal, UazapiService $uazapi): void
    {
        $this->line('  Buscando grupos...');
        $grupos = $uazapi->listarGrupos($canal->tokenUazapi());
```

E a linha 48 (`$agenda = $uazapi->listarContatos($tenant->uazapi_instance_token);`) → `$agenda = $uazapi->listarContatos($canal->tokenUazapi());`.

E na criação do ticket (linha 134), adicionar `'whatsapp_canal_id' => $canal->id,` logo após `'contato_id' => $contato->id,`.

- [ ] **Step 2: `SincronizarContatosWhatsApp.php:18-45`**

Substituir:

```php
    public function handle(UazapiService $uazapi): int
    {
        $query = Tenant::whereNotNull('uazapi_instance_token');

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('Nenhum tenant com WhatsApp conectado.');
            return Command::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->info("Tenant #{$tenant->id} — {$tenant->nome}");
            $this->sincronizar($tenant, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function sincronizar(Tenant $tenant, UazapiService $uazapi): void
    {
        $this->line('  Buscando contatos do WhatsApp...');

        $contatos = $uazapi->listarContatos($tenant->uazapi_instance_token);
```

por:

```php
    public function handle(UazapiService $uazapi): int
    {
        $query = \App\Models\WhatsappCanal::withoutGlobalScopes()
            ->where('tipo', 'nao_oficial')
            ->where('status', 'connected');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        $canais = $query->get();

        if ($canais->isEmpty()) {
            $this->warn('Nenhum canal WhatsApp conectado.');
            return Command::SUCCESS;
        }

        foreach ($canais as $canal) {
            $tenant = $canal->tenant;
            $this->info("Tenant #{$tenant->id} — {$tenant->nome} (canal #{$canal->id})");
            $this->sincronizar($tenant, $canal, $uazapi);
        }

        return Command::SUCCESS;
    }

    private function sincronizar(Tenant $tenant, \App\Models\WhatsappCanal $canal, UazapiService $uazapi): void
    {
        $this->line('  Buscando contatos do WhatsApp...');

        $contatos = $uazapi->listarContatos($canal->tokenUazapi());
```

E na criação do ticket (linha 134), adicionar `'whatsapp_canal_id' => $canal->id,` logo após `'contato_id' => $contato->id,`.

- [ ] **Step 3: Escrever o teste consolidado**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizacaoIteraPorCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincronizar_contatos_grava_canal_no_ticket_criado(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511977776666@s.whatsapp.net', 'contact_name' => 'Ciclano', 'contact_FirstName' => 'Ciclano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $this->artisan('contatos:sincronizar-whatsapp', ['--tenant' => $tenant->id])->assertSuccessful();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=SincronizacaoIteraPorCanalTest`
Expected: 1 passed

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ImportarParticipantesGrupos.php app/Console/Commands/SincronizarContatosWhatsApp.php tests/Feature/SincronizacaoIteraPorCanalTest.php
git commit -m "refactor: comandos de sincronização iteram por canal WhatsApp, não por tenant"
```

---

### Task 15 (NÃO executar automaticamente — deploy separado, só após validar em produção): Limpeza dos campos legados em `tenants`

**Files:**
- Create: `database/migrations/YYYY_MM_DD_000001_remove_uazapi_fields_from_tenants.php` (data real do dia em que for de fato executada)
- Modify: `app/Models/Tenant.php` (remover os campos legados do `$fillable`)

> **Atenção:** esta task só deve ser puxada para um novo deploy depois que as Tasks 1-14 estiverem rodando em produção por tempo suficiente para confirmar que nenhum código residual ainda lê `tenants.uazapi_instance_token` diretamente. Rode `grep -r "uazapi_instance_token\|uazapi_webhook_token\|uazapi_instance_name" app/` antes de aplicar — se aparecer qualquer resultado fora deste plano, pare e corrija antes de remover as colunas.

- [ ] **Step 1: Confirmar que não há mais leituras diretas dos campos legados**

Run: `grep -rn "uazapi_instance_token\|uazapi_webhook_token\|uazapi_instance_name" app/`
Expected: nenhum resultado (todos os pontos foram migrados nas Tasks 6, 7, 11, 12, 14)

- [ ] **Step 2: Escrever a migration de limpeza**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'uazapi_instance_name',
                'uazapi_instance_token',
                'uazapi_webhook_token',
                'whatsapp_status',
                'whatsapp_phone',
                'whatsapp_connected_since',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('uazapi_instance_name')->nullable();
            $table->string('uazapi_instance_token')->nullable();
            $table->string('uazapi_webhook_token', 64)->nullable()->unique();
            $table->string('whatsapp_status')->default('disconnected');
            $table->string('whatsapp_phone')->nullable();
            $table->timestamp('whatsapp_connected_since')->nullable();
        });
    }
};
```

- [ ] **Step 3: Remover os campos do `$fillable` de `Tenant`**

Remover de `app/Models/Tenant.php`: `'whatsapp_status'`, `'whatsapp_phone'`, `'whatsapp_connected_since'`, `'uazapi_instance_name'`, `'uazapi_instance_token'`, `'uazapi_webhook_token'`.

- [ ] **Step 4: Rodar toda a suíte de testes**

Run: `php artisan test`
Expected: nenhuma falha

- [ ] **Step 5: Commit (em deploy próprio, separado desta entrega)**

```bash
git add database/migrations/YYYY_MM_DD_000001_remove_uazapi_fields_from_tenants.php app/Models/Tenant.php
git commit -m "chore: remove campos legados de conexão WhatsApp de tenants"
```

---

## Fora deste plano (Plano B — depende de confirmação externa)

- Conexão do canal oficial via widget da Covercut (bloqueado até confirmar com o Sandro como o `phone_number_id` volta pra nós).
- `CovercutChannelService`, `CovercutWebhookController`, checagem de janela de 24h/72h e bloqueio de envio fora dela.
- Rodízio ponderado por maturidade do número (v2 — fora de escopo desta entrega, ver spec seção 8).
