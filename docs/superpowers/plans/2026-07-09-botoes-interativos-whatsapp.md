# Botões Interativos do WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o dono do tenant configure até 3 botões de resposta rápida do WhatsApp por coluna do Kanban (via Uazapi `POST /send/menu`), e que o clique do lead (`buttonOrListid` no webhook) dispare uma de três ações: mover o ticket de coluna, acionar a IA da coluna atual, ou marcar o contato como bloqueado (opt-out) só para este tenant.

**Architecture:** Configuração por coluna fica em `kanban_coluna_configs.button_settings` (JSON, até 3 entradas `{text, action, target}`). `UazapiService` ganha um método de envio (`enviarMenuBotoes`). `UazapiWebhookController` extrai `buttonOrListid` da mensagem recebida e delega para um novo serviço, `KanbanBotaoActionService`, que lê a config da coluna atual do ticket e executa a ação — sem nenhum `if (id == "...")` no controller. Opt-out é gravado em `vinculos_contato_tenant.bloqueado_em` (tenant-scoped, não bloqueia outros franqueados) e checado em `SequenciaMensagemJob` antes de enviar.

**Tech Stack:** Laravel 13 / PHP 8.4, MySQL, PHPUnit (sqlite `:memory:` já configurado em `phpunit.xml`), Alpine.js v3 no front-end (sem build step de teste JS neste projeto).

## Global Constraints

- WhatsApp só exibe de forma confiável **até 3 botões de resposta** por mensagem — trava mandatória no backend (validação) e no front-end (UI desabilita "adicionar" no 3º).
- Nunca fazer hardcode de `id` de botão no `UazapiWebhookController` — toda ação vem de `kanban_coluna_configs.button_settings`.
- Opt-out é **por tenant**, nunca global — grava em `vinculos_contato_tenant` (tabela já tenant-scoped via `[contato_id, tenant_id]`), nunca em `contatos` (tabela compartilhada entre tenants).
- Todo código Eloquent tenant-scoped deve respeitar o `TenantScope` já usado em `KanbanColunaConfig` e `VinculoContatoTenant` — não introduzir queries globais sem escopo.
- Todo deploy passa por `./deploy.sh` (push + trava de VPS suja + migrate + build de assets + cache) — nunca ssh manual (ver `CLAUDE.md`).
- Seguir o formato canônico de telefone `55DDXXXXXXXX` já usado em todo o projeto (nenhuma task aqui precisa normalizar telefone, mas nenhuma deve reformatar por conta própria).

---

## Achado corrigido de passagem — Task 0

Durante a pesquisa para este plano, encontrei um bug real e pequeno em código que eu mesmo toquei nesta sessão: `App\Models\KanbanColunaConfig::$fillable` **não inclui `sdr_delay_segundos`**, embora a coluna exista e seja validada/enviada por `KanbanColunaConfigController::update()`. Sem estar em `$fillable`, o Eloquent descarta esse campo silenciosamente em `updateOrCreate()` (sem exceção, sem erro) — ou seja, o temporizador do Agente de IA pode nunca estar salvando de verdade. Corrijo isso como Task 1 antes de tocar em qualquer coisa nova, porque a Task 3 (model) mexe no mesmo arquivo.

---

### Task 1: Corrigir `$fillable` ausente em `KanbanColunaConfig`

**Files:**
- Modify: `app/Models/KanbanColunaConfig.php`
- Test: `tests/Feature/KanbanColunaConfigFillableTest.php`

**Interfaces:**
- Produces: `KanbanColunaConfig::$fillable` agora inclui `sdr_delay_segundos` — todas as tasks seguintes que usam `updateOrCreate` nesse model dependem disso.

- [ ] **Step 1: Escrever o teste que expõe o bug**

```php
<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigFillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdr_delay_segundos_e_persistido_via_mass_assignment(): void
    {
        $tenant = Tenant::factory()->create();

        $config = KanbanColunaConfig::updateOrCreate(
            ['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento'],
            ['sdr_delay_segundos' => 120]
        );

        $this->assertSame(120, $config->fresh()->sdr_delay_segundos);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter test_sdr_delay_segundos_e_persistido_via_mass_assignment`
Expected: FAIL — `sdr_delay_segundos` vem `null` ou `45` (default), não `120`, porque o mass assignment descartou o valor.

Se o projeto não tiver `database/factories/TenantFactory.php`, crie-o antes deste passo:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'nome' => $this->faker->company(),
        ];
    }
}
```

Ajuste os campos de `definition()` para os campos `NOT NULL` reais de `tenants` (confira `database/migrations` da tabela `tenants` antes de rodar — se houver campos obrigatórios além de `nome`, adicione-os aqui).

- [ ] **Step 3: Corrigir o model**

```php
// app/Models/KanbanColunaConfig.php
protected $fillable = [
    'tenant_id', 'coluna_kanban', 'objetivo', 'seq_objetivo',
    'ia_objetivo', 'ia_contexto', 'ia_ativo', 'sdr_delay_segundos',
];
```

- [ ] **Step 4: Rodar o teste de novo e confirmar que passa**

Run: `php artisan test --filter test_sdr_delay_segundos_e_persistido_via_mass_assignment`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/KanbanColunaConfig.php tests/Feature/KanbanColunaConfigFillableTest.php database/factories/TenantFactory.php
git commit -m "fix: sdr_delay_segundos ausente do \$fillable de KanbanColunaConfig"
```

---

### Task 2: Migration — coluna `button_settings` em `kanban_coluna_configs`

**Files:**
- Create: `database/migrations/2026_07_10_000001_add_button_settings_to_kanban_coluna_configs.php`

**Interfaces:**
- Produces: coluna `kanban_coluna_configs.button_settings` (JSON, nullable) — Task 3 (model) e Task 5 (controller) dependem dela existir.

- [ ] **Step 1: Criar a migration**

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
            $table->json('button_settings')->nullable()->after('sdr_delay_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('button_settings');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration localmente e conferir o schema**

Run: `php artisan migrate`
Expected: `2026_07_10_000001_add_button_settings_to_kanban_coluna_configs ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_10_000001_add_button_settings_to_kanban_coluna_configs.php
git commit -m "feat: coluna button_settings em kanban_coluna_configs"
```

---

### Task 3: Migration — coluna `bloqueado_em` em `vinculos_contato_tenant` (opt-out por tenant)

**Achado na checagem pré-voo do plano — três campos parecidos, três significados diferentes, não confundir:**
1. `contatos.opt_out` (boolean, já existe, GLOBAL — mesma tabela em todos os tenants): contato pediu pra não receber **nenhuma** mensagem de **nenhum** franqueado do ecossistema. Checado hoje em `Internal/ContatoController.php:29` no recebimento de webhook.
2. `contatos.bloqueado` (boolean, já existe, GLOBAL): "empresa não quer mais atender" — a própria plataforma Lead Certo bloqueou o contato (abuso/spam), não é uma escolha do lead.
3. `vinculos_contato_tenant.bloqueado_em` (**esta task, tenant-scoped**): o lead clicou "Parar mensagens" no botão de UM franqueado específico. Não afeta os outros dois campos acima nem os outros franqueados.

Nenhum dos dois campos existentes serve pro opt-out do botão — ambos são globais, e a regra de negócio exige que o bloqueio valha só pro franqueado que recebeu o clique.

**Files:**
- Create: `database/migrations/2026_07_10_000002_add_bloqueado_em_to_vinculos_contato_tenant.php`

**Interfaces:**
- Produces: coluna `vinculos_contato_tenant.bloqueado_em` (timestamp, nullable). Distinta também de `ativo`/`desativado_em` (que já existem nessa tabela e representam merge de duplicatas — `ContatosController.php:289` grava `desativado_em` ao mesclar contatos; **não reutilizar esse par de colunas para opt-out**, são conceitos diferentes).

- [ ] **Step 1: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->timestamp('bloqueado_em')->nullable()->after('desativado_em');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn('bloqueado_em');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration localmente**

Run: `php artisan migrate`
Expected: `2026_07_10_000002_add_bloqueado_em_to_vinculos_contato_tenant ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_10_000002_add_bloqueado_em_to_vinculos_contato_tenant.php
git commit -m "feat: coluna bloqueado_em em vinculos_contato_tenant (opt-out por tenant)"
```

---

### Task 4: Atualizar os models (`KanbanColunaConfig`, `VinculoContatoTenant`)

**Files:**
- Modify: `app/Models/KanbanColunaConfig.php`
- Modify: `app/Models/VinculoContatoTenant.php`
- Test: `tests/Feature/KanbanColunaConfigButtonSettingsTest.php`

**Interfaces:**
- Consumes: colunas criadas nas Tasks 2 e 3.
- Produces: `KanbanColunaConfig::$button_settings` acessível como array PHP (cast `array`). `VinculoContatoTenant::$bloqueado_em` acessível como `Carbon|null` (cast `datetime`) e fillable.

- [ ] **Step 1: Escrever o teste do cast**

```php
<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigButtonSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_button_settings_e_salvo_e_lido_como_array(): void
    {
        $tenant = Tenant::factory()->create();

        $botoes = [
            ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ['text' => 'Continuar com IA', 'action' => 'trigger_ia', 'target' => null],
            ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
        ];

        $config = KanbanColunaConfig::updateOrCreate(
            ['tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead'],
            ['button_settings' => $botoes]
        );

        $this->assertIsArray($config->fresh()->button_settings);
        $this->assertCount(3, $config->fresh()->button_settings);
        $this->assertSame('move_column', $config->fresh()->button_settings[0]['action']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter test_button_settings_e_salvo_e_lido_como_array`
Expected: FAIL — `button_settings` não está em `$fillable`, ou vem como string JSON crua (sem o cast).

- [ ] **Step 3: Atualizar `KanbanColunaConfig`**

```php
// app/Models/KanbanColunaConfig.php
protected $fillable = [
    'tenant_id', 'coluna_kanban', 'objetivo', 'seq_objetivo',
    'ia_objetivo', 'ia_contexto', 'ia_ativo', 'sdr_delay_segundos',
    'button_settings',
];

protected $casts = [
    'ia_ativo'        => 'boolean',
    'button_settings' => 'array',
];
```

- [ ] **Step 4: Atualizar `VinculoContatoTenant`**

```php
// app/Models/VinculoContatoTenant.php — adicionar 'bloqueado_em' ao $fillable existente
protected $fillable = [
    'contato_id', 'tenant_id', 'google_resource_name', 'google_etag',
    'google_given_name', 'nome_sugerido', 'auditoria_pendente', 'bloqueado_em',
];

protected $casts = [
    'bloqueado_em' => 'datetime',
];
```

(Se `VinculoContatoTenant` já tiver um método `casts()` ou array `$casts` com outras entradas, mesclar em vez de substituir — confira o arquivo atual antes de aplicar.)

- [ ] **Step 5: Rodar o teste de novo e confirmar que passa**

Run: `php artisan test --filter test_button_settings_e_salvo_e_lido_como_array`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/KanbanColunaConfig.php app/Models/VinculoContatoTenant.php tests/Feature/KanbanColunaConfigButtonSettingsTest.php
git commit -m "feat: casts/fillable pra button_settings e bloqueado_em"
```

---

### Task 5: `UazapiService::enviarMenuBotoes()`

**Files:**
- Modify: `app/Services/UazapiService.php`
- Test: `tests/Feature/UazapiServiceMenuTest.php`

**Interfaces:**
- Consumes: nada de novo (segue o padrão de `enviarTexto`/`enviarImagem` já existentes no mesmo arquivo).
- Produces: `UazapiService::enviarMenuBotoes(string $instanceToken, string $numero, string $texto, array $botoes, string $footerText = ''): bool` — usado pela Task 7 (webhook) e por qualquer chamador futuro (ex: `KanbanController` disparando manualmente). `$botoes` é um array de strings já no formato Uazapi `"texto|id"` (a montagem desse formato a partir do `button_settings` acontece em quem chama este método, não aqui — mantém o serviço burro e testável).

- [ ] **Step 1: Escrever o teste com `Http::fake`**

```php
<?php

namespace Tests\Feature;

use App\Services\UazapiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiServiceMenuTest extends TestCase
{
    public function test_enviar_menu_botoes_monta_payload_correto(): void
    {
        Http::fake(['*/send/menu' => Http::response(['id' => 'msg123'], 200)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarMenuBotoes(
            'token-abc',
            '5511999999999',
            'Como podemos ajudar?',
            ['Suporte Técnico|suporte', 'Fazer Pedido|pedido', 'Não tenho interesse|opt_out'],
            'Escolha uma opção'
        );

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/menu')
                && $request['type'] === 'button'
                && $request['number'] === '5511999999999'
                && $request['text'] === 'Como podemos ajudar?'
                && $request['footerText'] === 'Escolha uma opção'
                && $request['choices'] === ['Suporte Técnico|suporte', 'Fazer Pedido|pedido', 'Não tenho interesse|opt_out']
                && $request->hasHeader('token', 'token-abc');
        });
    }

    public function test_enviar_menu_botoes_retorna_false_em_falha(): void
    {
        Http::fake(['*/send/menu' => Http::response(['error' => 'bad request'], 400)]);

        $service = app(UazapiService::class);

        $ok = $service->enviarMenuBotoes('token-abc', '5511999999999', 'Oi', ['A|a']);

        $this->assertFalse($ok);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter UazapiServiceMenuTest`
Expected: FAIL — método `enviarMenuBotoes` não existe.

- [ ] **Step 3: Implementar o método**

Adicionar em `app/Services/UazapiService.php`, próximo aos outros métodos de envio (`enviarTexto`, `enviarImagem`):

```php
/**
 * Envia menu interativo de botões via POST /send/menu (type: button).
 * $botoes já vem no formato Uazapi "texto|id" (ou "texto|https://...", "texto|call:+55...").
 * Limite de 3 botões de resposta é responsabilidade de quem monta $botoes — o
 * WhatsApp não garante exibição confiável acima disso.
 */
public function enviarMenuBotoes(string $instanceToken, string $numero, string $texto, array $botoes, string $footerText = ''): bool
{
    try {
        $body = [
            'number'  => $numero,
            'type'    => 'button',
            'text'    => $texto,
            'choices' => $botoes,
        ];
        if ($footerText !== '') {
            $body['footerText'] = $footerText;
        }

        $response = Http::withHeaders(['token' => $instanceToken])
            ->post("{$this->baseUrl}/send/menu", $body);

        if (!$response->successful()) {
            Log::warning('Uazapi enviarMenuBotoes falhou', [
                'numero' => $numero,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response->successful();
    } catch (\Exception $e) {
        Log::error('Uazapi enviarMenuBotoes exception', ['erro' => $e->getMessage()]);
        return false;
    }
}
```

- [ ] **Step 4: Rodar de novo e confirmar que passa**

Run: `php artisan test --filter UazapiServiceMenuTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/UazapiService.php tests/Feature/UazapiServiceMenuTest.php
git commit -m "feat: UazapiService::enviarMenuBotoes() via /send/menu"
```

---

### Task 6: `KanbanBotaoActionService` (o motor das 3 reações)

**Files:**
- Create: `app/Services/KanbanBotaoActionService.php`
- Test: `tests/Feature/KanbanBotaoActionServiceTest.php`

**Interfaces:**
- Consumes: `KanbanColunaConfig::$button_settings` (Task 4), `VinculoContatoTenant::$bloqueado_em` (Task 4), `TicketAtendimento` model (já existente, `contato()`/`tenant()` relations).
- Produces: `KanbanBotaoActionService::executar(TicketAtendimento $ticket, string $buttonId): bool` — retorna `true` se encontrou e executou uma ação configurada pra aquele `buttonId` na coluna atual do ticket, `false` se não achou config correspondente (permite ao chamador cair no fallback de texto). Usado pela Task 7 (webhook).

Este serviço implementa só 3 ações no MVP: `move_column`, `trigger_ia`, `opt_out`. "Adicionar tag" fica fora deste plano — o sistema de Etiquetas atual (`app/Models/Etiqueta.php`) é acoplado à sincronização de grupos do Google Contacts (`PushContatoParaGoogleJob`), não é um encaixe direto pra "tag interna do Kanban". Precisa de desenho próprio antes de implementar (ver nota no manual de uso).

- [ ] **Step 1: Escrever os testes de comportamento**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\KanbanBotaoActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBotaoActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => $coluna,
            'agente_responsavel' => 'bot',
            'status'             => 'aberto',
            'aberto_em'          => now(),
        ]);
    }

    public function test_move_column_move_o_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ],
        ]);
        $ticket = $this->criarTicket($tenant, 'aguardando_lead');

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertTrue($executou);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_opt_out_marca_vinculo_como_bloqueado(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'lead_novo',
            'button_settings' => [
                ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
            ],
        ]);
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        VinculoContatoTenant::create(['contato_id' => $ticket->contato_id, 'tenant_id' => $tenant->id]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        // id não corresponde a nenhuma config -> não executa
        $this->assertFalse($executou);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'opt_out:0');
        $this->assertTrue($executou);

        $vinculo = VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($vinculo->bloqueado_em);
    }

    public function test_botao_de_outra_coluna_nao_e_executado(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id'       => $tenant->id,
            'coluna_kanban'   => 'em_atendimento',
            'button_settings' => [
                ['text' => 'Encerrar', 'action' => 'move_column', 'target' => 'encerrado'],
            ],
        ]);
        // ticket está em outra coluna — a config de 'em_atendimento' não deve se aplicar aqui
        $ticket = $this->criarTicket($tenant, 'aguardando_lead');

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'move_column:0');

        $this->assertFalse($executou);
        $this->assertSame('aguardando_lead', $ticket->fresh()->coluna_kanban);
    }
}
```

`database/factories/ContatoFactory.php` não existe ainda — criar (schema real confirmado em `database/migrations/0003_create_consumidores_table.php` + `0031_add_tipo_and_bloqueado_to_contatos.php`: `telefone` único obrigatório, `origem` obrigatória sem default, demais campos têm default):

```php
<?php

namespace Database\Factories;

use App\Models\Contato;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContatoFactory extends Factory
{
    protected $model = Contato::class;

    public function definition(): array
    {
        return [
            'telefone' => '55119' . fake()->unique()->numerify('########'),
            'nome'     => fake()->name(),
            'origem'   => 'whatsapp',
        ];
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter KanbanBotaoActionServiceTest`
Expected: FAIL — classe `KanbanBotaoActionService` não existe.

- [ ] **Step 3: Implementar o serviço**

O `id` do botão é montado como `"{action}:{indice}"` (ex: `move_column:0`) no momento do ENVIO (Task 7 monta isso ao montar `choices`) — assim o `buttonId` que volta no webhook já carrega a ação e o índice dela dentro de `button_settings`, sem precisar re-serializar nada extra nem manter um mapa paralelo.

```php
<?php

namespace App\Services;

use App\Models\TicketAtendimento;
use App\Models\KanbanColunaConfig;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\Log;

class KanbanBotaoActionService
{
    /**
     * Executa a ação configurada para $buttonId na coluna ATUAL do ticket.
     * $buttonId vem no formato "{action}:{indice}" (ver enviarBotoesDaColuna()
     * em UazapiWebhookController). Retorna false se não há config correspondente
     * — o chamador deve tratar isso como "não era um clique de botão conhecido".
     */
    public function executar(TicketAtendimento $ticket, string $buttonId): bool
    {
        [$action, $indice] = array_pad(explode(':', $buttonId, 2), 2, null);

        $config = KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();

        $botoes = $config?->button_settings ?? [];
        $botao  = $botoes[(int) $indice] ?? null;

        if (! $botao || ($botao['action'] ?? null) !== $action) {
            return false;
        }

        return match ($action) {
            'move_column' => $this->moverColuna($ticket, $botao['target'] ?? null),
            'trigger_ia'  => $this->acionarIa($ticket),
            'opt_out'     => $this->optOut($ticket),
            default       => false,
        };
    }

    private function moverColuna(TicketAtendimento $ticket, ?string $destino): bool
    {
        if (! $destino) {
            Log::warning('KanbanBotaoActionService: move_column sem target', ['ticket_id' => $ticket->id]);
            return false;
        }

        $ticket->update(['coluna_kanban' => $destino]);
        return true;
    }

    private function acionarIa(TicketAtendimento $ticket): bool
    {
        $ticket->update(['agente_responsavel' => 'bot']);
        return true;
    }

    private function optOut(TicketAtendimento $ticket): bool
    {
        VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $ticket->tenant_id)
            ->update(['bloqueado_em' => now()]);

        return true;
    }
}
```

- [ ] **Step 4: Rodar de novo e confirmar que passa**

Run: `php artisan test --filter KanbanBotaoActionServiceTest`
Expected: PASS (3 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/KanbanBotaoActionService.php tests/Feature/KanbanBotaoActionServiceTest.php
git commit -m "feat: KanbanBotaoActionService — move_column/trigger_ia/opt_out"
```

---

### Task 7: Webhook — capturar `buttonOrListid` e montar/enviar os botões

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php`
- Test: `tests/Feature/UazapiWebhookButtonTest.php`

**Interfaces:**
- Consumes: `KanbanBotaoActionService::executar()` (Task 6), `KanbanColunaConfig::$button_settings` (Task 4).
- Produces: novo método privado `enviarBotoesDaColuna(TicketAtendimento $ticket): void` (chamável de qualquer ponto do controller que precise disparar os botões da coluna atual — ex: ao criar/mover um ticket). Modifica `processarMensagemLead()` para checar `buttonOrListid` **antes** de processar a mensagem como texto normal.

- [ ] **Step 1: Escrever o teste do fluxo de webhook**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_clique_no_botao_move_o_ticket_e_nao_processa_como_texto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-token-123']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ],
        ]);

        $response = $this->postJson('/webhook/uazapi/wh-token-123', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'        => false,
                'isGroup'       => false,
                'chatid'        => '5511999999999@s.whatsapp.net',
                'buttonOrListid' => 'move_column:0',
                'text'          => 'Falar com Humano',
                'messageType'   => 'buttonsResponseMessage',
            ],
        ]);

        $response->assertOk();
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_mensagem_sem_buttonorlistid_continua_fluxo_normal_de_texto(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-token-456']);

        $response = $this->postJson('/webhook/uazapi/wh-token-456', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511988888888@s.whatsapp.net',
                'text'    => 'Oi, tudo bem?',
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('contatos', ['telefone' => '5511988888888']);
    }
}
```

Confira antes de rodar: o caminho exato da rota do webhook (`routes/web.php`, procure por `Route::post` com `webhookToken` — o teste acima assume `/webhook/uazapi/{webhookToken}`; ajuste a URL no teste para o path real se for diferente).

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter UazapiWebhookButtonTest`
Expected: FAIL no primeiro teste — hoje `buttonOrListid` é ignorado, o ticket não muda de coluna.

- [ ] **Step 3: Implementar a captura no controller**

Em `processarMensagemLead()` (por volta da linha 101), logo após extrair `$msg` e antes de qualquer outro processamento de conteúdo, adicionar a checagem de botão:

```php
$buttonId = $msg['buttonOrListid'] ?? null;

if ($buttonId) {
    $ticketExistente = TicketAtendimento::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('contato_id', $contato->id)
        ->whereIn('status', ['aberto', 'aguardando'])
        ->latest()
        ->first();

    if ($ticketExistente) {
        $executou = app(\App\Services\KanbanBotaoActionService::class)->executar($ticketExistente, $buttonId);

        if ($executou) {
            return; // clique tratado — não cai no fluxo de texto normal
        }
    }
    // buttonId presente mas sem config correspondente (ou sem ticket aberto):
    // cai no fluxo normal abaixo, tratando a resposta como texto (fallback).
}
```

Isso precisa vir **depois** de `$contato` já estar resolvido (o `firstOrCreate` de contato já acontece antes na função) e **antes** do bloco de criação/reativação de ticket, pra reaproveitar o ticket que já existe em vez de criar um novo. Ajuste a ordem exata olhando o arquivo atual — o objetivo é: contato resolvido → checa botão contra ticket já aberto → se não tratou, segue o fluxo de sempre.

- [ ] **Step 4: Implementar o método de envio dos botões da coluna**

Adicionar no mesmo controller (método privado, chamado por quem dispara mensagens de entrada/transição de coluna — a integração de ONDE chamar isso fica a critério de uma task futura de UI; aqui só criamos o método):

```php
private function enviarBotoesDaColuna(TicketAtendimento $ticket): void
{
    $config = KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)
        ->where('coluna_kanban', $ticket->coluna_kanban)
        ->first();

    $botoes = $config?->button_settings ?? [];
    if (empty($botoes)) {
        return;
    }

    $choices = [];
    foreach ($botoes as $i => $botao) {
        $choices[] = "{$botao['text']}|{$botao['action']}:{$i}";
    }

    $telefone = $ticket->contato?->telefone;
    $token    = $ticket->tenant?->uazapi_instance_token;
    if (! $telefone || ! $token) {
        return;
    }

    app(\App\Services\UazapiService::class)->enviarMenuBotoes(
        $token,
        $telefone,
        $config->objetivo ?: 'Escolha uma opção:',
        $choices
    );
}
```

- [ ] **Step 5: Rodar os testes de novo e confirmar que passam**

Run: `php artisan test --filter UazapiWebhookButtonTest`
Expected: PASS (2 testes)

- [ ] **Step 6: Rodar a suíte inteira pra checar que nada quebrou**

Run: `php artisan test`
Expected: todos os testes passam (incluindo os das Tasks 1–6).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php tests/Feature/UazapiWebhookButtonTest.php
git commit -m "feat: webhook captura buttonOrListid e delega pro KanbanBotaoActionService"
```

---

### Task 8: Opt-out bloqueia envio em `SequenciaMensagemJob`

**Files:**
- Modify: `app/Jobs/SequenciaMensagemJob.php`
- Test: `tests/Feature/SequenciaMensagemJobOptOutTest.php`

**Interfaces:**
- Consumes: `VinculoContatoTenant::$bloqueado_em` (Task 4).
- Produces: nenhuma interface nova — só adiciona uma guarda de saída antecipada no `handle()` já existente.

Escopo deste MVP: só cobre o caminho de sequências automáticas. Envio manual pelo painel (`KanbanController::enviarMensagem`) e o SDR responder (`SdrResponderJob`) **não** são cobertos aqui — anotar como pendência explícita no manual de uso (Task 10), não fingir que está coberto.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaMensagemJobOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_nao_envia_para_contato_bloqueado_naquele_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'bloqueado_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Oi {nome}, tudo bem?'))
            ->handle(app(\App\Services\HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter SequenciaMensagemJobOptOutTest`
Expected: FAIL — hoje o job envia normalmente, ignorando o bloqueio.

- [ ] **Step 3: Adicionar a guarda no `handle()`**

Em `app/Jobs/SequenciaMensagemJob.php`, logo após a checagem existente de `coluna_kanban === 'encerrado'` (linha ~30) e antes da checagem de `colunaKanban` (linha ~34):

```php
$bloqueado = \App\Models\VinculoContatoTenant::where('contato_id', $ticket->contato_id)
    ->where('tenant_id', $ticket->tenant_id)
    ->whereNotNull('bloqueado_em')
    ->exists();

if ($bloqueado) {
    Log::info('SequenciaMensagemJob: contato bloqueado (opt-out) neste tenant, envio cancelado', [
        'ticket_id' => $this->ticketId,
    ]);
    return;
}
```

- [ ] **Step 4: Rodar de novo e confirmar que passa**

Run: `php artisan test --filter SequenciaMensagemJobOptOutTest`
Expected: PASS

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php artisan test`
Expected: todos os testes passam.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/SequenciaMensagemJob.php tests/Feature/SequenciaMensagemJobOptOutTest.php
git commit -m "fix: SequenciaMensagemJob respeita opt-out (bloqueado_em) por tenant"
```

---

### Task 9: Validação + rotas no `KanbanColunaConfigController`

**Achado na checagem pré-voo do plano:** a tabela `users` usa a coluna `perfil` (enum — valores incluem `dono`, ver `database/migrations/0018_expand_perfil_users.php`), **não** `role`, e o campo de nome é `nome`, **não** `name`. `database/factories/UserFactory.php` ainda está no estado padrão do scaffold do Laravel (só seta `name`/`email`/`password`) e vai falhar com erro de coluna `nome` NOT NULL se usado como está — corrigir esse factory faz parte do Step 1 abaixo.

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/KanbanColunaConfigButtonValidationTest.php`

**Interfaces:**
- Consumes: `KanbanColunaConfig` (Task 4).
- Produces: `show()` agora retorna `button_settings` no JSON; `update()` valida `button_settings` (array, máx. 3, cada item com `text`/`action`/`target`).

- [ ] **Step 1: Corrigir `UserFactory` e escrever o teste de validação**

Primeiro, atualizar `database/factories/UserFactory.php::definition()` para bater com o schema real (coluna `nome`, não `name`; `perfil` tem default `'vendedor'` no banco, então não precisa ser setado aqui):

```php
public function definition(): array
{
    return [
        'nome' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password' => static::$password ??= Hash::make('password'),
        'remember_token' => Str::random(10),
    ];
}
```

Depois, o teste:

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigButtonValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_mais_de_3_botoes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'A', 'action' => 'move_column', 'target' => 'em_atendimento'],
                ['text' => 'B', 'action' => 'trigger_ia', 'target' => null],
                ['text' => 'C', 'action' => 'opt_out', 'target' => null],
                ['text' => 'D', 'action' => 'opt_out', 'target' => null],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_aceita_ate_3_botoes_validos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/lead_novo', [
            'button_settings' => [
                ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
                ['text' => 'Continuar com IA', 'action' => 'trigger_ia', 'target' => null],
            ],
        ]);

        $response->assertOk();

        $get = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');
        $get->assertJsonCount(2, 'button_settings');
    }
}
```

Os middlewares da rota exigem `auth` + `tenant` + `role:admin,dono` (o middleware `role:` compara contra `$user->perfil`, ver `app/Http/Middleware/CheckRole.php`).

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `php artisan test --filter KanbanColunaConfigButtonValidationTest`
Expected: FAIL — `button_settings` ainda não é validado nem retornado.

- [ ] **Step 3: Atualizar o controller**

```php
// show() — adicionar ao array de retorno:
'button_settings' => $config?->button_settings ?? [],

// update() — adicionar às regras de validação:
'button_settings'             => 'sometimes|array|max:3',
'button_settings.*.text'      => 'required_with:button_settings|string|max:20',
'button_settings.*.action'    => 'required_with:button_settings|string|in:move_column,trigger_ia,opt_out',
'button_settings.*.target'    => 'nullable|string|max:50',
```

O limite de 20 caracteres no texto do botão segue o limite prático do WhatsApp citado no guia estratégico da sessão anterior.

- [ ] **Step 4: Rodar de novo e confirmar que passa**

Run: `php artisan test --filter KanbanColunaConfigButtonValidationTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Rodar a suíte inteira**

Run: `php artisan test`
Expected: todos os testes passam.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php tests/Feature/KanbanColunaConfigButtonValidationTest.php
git commit -m "feat: validação e retorno de button_settings no KanbanColunaConfigController"
```

---

### Task 10: UI — bloco "Botões Interativos" em `kanban/config.blade.php`

**Files:**
- Modify: `resources/views/kanban/config.blade.php`

**Interfaces:**
- Consumes: `GET /api/painel/kanban/coluna-config/{coluna}` e `PUT /api/painel/kanban/coluna-config/{coluna}` (Task 9), campos `text`/`action`/`target` por botão.
- Produces: nenhuma interface nova para outras tasks — é a ponta final da cadeia.

Sem test automatizado aqui — este projeto não tem tooling de teste de front-end (confirmado na pesquisa: só Alpine.js inline, sem Jest/Vitest configurado). Verificação é manual, listada no Step 4.

- [ ] **Step 1: Adicionar estado Alpine**

Ao lado de `iaContexto`, `iaDelay` etc. (por volta da linha 871-878 de `resources/views/kanban/config.blade.php`):

```js
botoes: {},       // { [coluna.key]: [{text, action, target}, ...] }
botoesAlterado: {},
```

- [ ] **Step 2: Carregar e salvar junto com `carregarIa`/`salvarIa`**

Em `carregarIa(key)`, após `this.iaDelayUnidade[key] = delay.unidade;`:

```js
this.botoes[key] = json.button_settings ?? [];
```

Em `salvarIa(key)`, incluir no payload do PUT:

```js
button_settings: this.botoes[key] ?? [],
```

- [ ] **Step 3: Adicionar o bloco de UI**

Logo abaixo do bloco "Aguardar ... antes de responder" (a mesma seção onde ficam `iaAtivo`/`iaDelay`), dentro do card do Agente de IA da coluna:

```html
<div class="mt-4 pt-4 border-t border-gray-100">
    <p class="text-xs font-semibold text-gray-500 mb-2">Botões Interativos (máx. 3)</p>

    <template x-for="(botao, i) in (botoes[col.key] || [])" :key="i">
        <div class="flex items-center gap-2 mb-2">
            <input type="text" maxlength="20"
                   :value="botao.text"
                   @input="botoes[col.key][i].text = $event.target.value; botoesAlterado[col.key] = true"
                   placeholder="Texto do botão (máx. 20)"
                   class="flex-1 text-xs border border-gray-300 rounded px-2 py-1">
            <select :value="botao.action"
                    @change="botoes[col.key][i].action = $event.target.value; botoesAlterado[col.key] = true"
                    class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white">
                <option value="move_column">Mover para coluna</option>
                <option value="trigger_ia">Acionar IA</option>
                <option value="opt_out">Parar mensagens (opt-out)</option>
            </select>
            <template x-if="botao.action === 'move_column'">
                <select :value="botao.target"
                        @change="botoes[col.key][i].target = $event.target.value; botoesAlterado[col.key] = true"
                        class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white">
                    <template x-for="c in colunas" :key="c.key">
                        <option :value="c.key" x-text="c.label"></option>
                    </template>
                </select>
            </template>
            <button @click="botoes[col.key].splice(i, 1); botoesAlterado[col.key] = true"
                    class="text-red-300 hover:text-red-500 text-xs">✕</button>
        </div>
    </template>

    <button @click="botoes[col.key] = [...(botoes[col.key] || []), { text: '', action: 'move_column', target: '' }]; botoesAlterado[col.key] = true"
            :disabled="(botoes[col.key] || []).length >= 3"
            class="text-xs text-purple-600 hover:text-purple-700 disabled:opacity-40 disabled:cursor-not-allowed">
        + Adicionar botão
    </button>
</div>
```

O botão "+ Adicionar" reaproveita `:disabled` para a trava de 3 — igual ao padrão já usado em outros lugares deste arquivo (ex: o botão "Salvar" com `:disabled="!iaAlterado[col.key]"`).

- [ ] **Step 4: Verificação manual**

1. `php artisan serve` (ou ambiente já rodando) → abrir `/kanban/config`.
2. Numa coluna, adicionar 3 botões com ações diferentes (`move_column`, `trigger_ia`, `opt_out`) e salvar.
3. Recarregar a página (F5) e confirmar que os 3 botões voltam preenchidos.
4. Tentar adicionar um 4º botão — confirmar que o botão "+ Adicionar" fica desabilitado.
5. Digitar um texto de botão com mais de 20 caracteres — confirmar que o campo não deixa digitar além disso (`maxlength`).

- [ ] **Step 5: Commit**

```bash
git add resources/views/kanban/config.blade.php
git commit -m "feat: UI de configuração dos botões interativos por coluna"
```

---

## Fora de escopo deste plano (anotado, não esquecido)

- **Ação "adicionar tag"**: precisa de desenho próprio — o sistema de Etiquetas atual está acoplado à sincronização de grupos do Google Contacts, não serve como "tag interna do Kanban" sem generalizar primeiro.
- **Opt-out em outros pontos de envio**: `KanbanController::enviarMensagem()` (envio manual pelo painel) e `SdrResponderJob` não checam `bloqueado_em` neste plano — só `SequenciaMensagemJob` (Task 8). Cobrir os demais é a próxima iteração natural.
- **Idempotência contra duplo-clique**: o MVP aqui não trava clique duplicado no mesmo botão (ex: lead clica 2x rápido, o `move_column` roda 2x — inofensivo porque `update()` é idempotente por natureza, mas `trigger_ia` acionado 2x poderia gerar 2 respostas da IA). Se isso aparecer como problema real de uso, adicionar um registro de "último buttonId processado por ticket" antes de executar.
- **Snooze/reabordagem futura (1/3/6 meses)** e **dashboard de métricas** (taxa de interação, deflection rate): ficam para depois que o MVP estiver validado com cenários reais no Frete Rio — fazem parte do guia estratégico, não da arquitetura mínima.

---

## Self-Review

**Cobertura do spec:** as 3 ações do MVP (mover coluna / acionar IA / opt-out) — Task 6. Envio via `/send/menu` — Task 5. Captura do clique — Task 7. Config por coluna em JSON — Tasks 2, 4, 9. UI de até 3 botões com trava — Task 10. Opt-out por tenant (não global) — Tasks 3, 8. Bug de `$fillable` achado na pesquisa — Task 1.

**Placeholders:** nenhum "TBD"/"implementar depois" nos steps — toda task tem código completo. As exceções explícitas (tag, idempotência, outros pontos de envio) estão listadas em "Fora de escopo", não escondidas dentro de uma task como se estivessem prontas.

**Consistência de tipos:** `KanbanBotaoActionService::executar(TicketAtendimento $ticket, string $buttonId): bool` é o mesmo assinatura usado nas Tasks 6 e 7. `UazapiService::enviarMenuBotoes(string $instanceToken, string $numero, string $texto, array $botoes, string $footerText = ''): bool` consistente entre Tasks 5 e 7.
