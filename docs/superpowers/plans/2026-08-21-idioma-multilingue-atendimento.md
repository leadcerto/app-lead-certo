# Idioma Multilíngue no Atendimento — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detectar o idioma do cliente por 4 camadas (DDI → botão → IA → comando manual do atendente), traduzir nos dois sentidos usando o idioma real do atendente (não mais fixo em português), e registrar origem/confiança de cada detecção.

**Architecture:** Estende o que já existe (`TraducaoService`, `idioma_lead` em `TicketAtendimento`) em vez de recriar. Camada 1 (DDI) roda na criação do ticket, sem custo de IA. Camada 2 (botão) reaproveita `KanbanBotaoActionService::enviarBotoes()` (Uazapi) com um fallback de texto numerado pro Covercut, que não tem suporte a botão interativo hoje (limitação já documentada, não nova). Camada 3 (IA) é o mecanismo já construído, ganhando alvo de tradução dinâmico (idioma do atendente, não mais `'pt'` fixo) e uma regra anti-oscilação. Camada 4 (comando manual) é uma entrada nova no autocomplete `/` do composer do Kanban, que dispara a mesma ação da Camada 2 sob demanda.

**Tech Stack:** Laravel 13 / PHP 8.4 / MySQL 8, Alpine.js (composer do Kanban), PHPUnit clássico + `RefreshDatabase` + `Http::fake()`/Mockery.

**Spec:** `docs/superpowers/specs/2026-08-21-idioma-multilingue-atendimento-design.md`

**Desvios da spec, decididos durante o mapeamento de arquivos (implementam a intenção da spec, não a contradizem):**

1. **Camada 2 no canal Covercut** — `KanbanBotaoActionService::enviarBotoes()` só existe pra Uazapi (`docs/paridade-canais-whatsapp.md` já documenta botão interativo como limitação aceita do canal Oficial hoje, não um esquecimento novo). Pra não deixar clientes do canal Oficial sem a Camada 2, o Covercut recebe um **fallback de texto numerado** ("Responda com o número: 1) Português 2) English 3) Español") em vez do menu de botões — mesma função, formato diferente por canal.
2. **Camada 4 não reaproveita literalmente `RespostaProntaController`** — aquele sistema é client-side (Alpine.js autocompleta o texto do `codigo_curto` no campo, sem chamada especial ao backend). `/idioma` precisa **disparar uma ação** (reenviar a escolha de idioma), não preencher texto — vira uma entrada hardcoded no mesmo dropdown `/`, com comportamento de clique diferente das respostas prontas reais.
3. **Regra anti-oscilação sem tabela de histórico nova** — em vez de contar uma "sequência" em campos novos, consulta as últimas 2 mensagens do lead já salvas (`mensagens.idioma`, que já existe) pra checar se ambas batem com o idioma recém-detectado.
4. **Mapeamento DDI → idioma é uma lista fixa pequena**, não uma tabela nem uma lib de terceiros — cobre só os idiomas que este projeto suporta hoje (pt-BR, pt-PT, es-ES, en-US). Resolve a pendência 2 da spec.
5. **`tenants.locale` não ganha UI de edição neste plano** — coluna criada com default `'pt-BR'` (100% dos tenants atuais são brasileiros); editor fica pra quando houver um tenant não-BR de verdade.

## Global Constraints

- Regra de paridade entre canais (CLAUDE.md): toda mudança de webhook precisa existir nos dois canais (Uazapi e Covercut) na mesma tarefa, exceto onde documentado o porquê (ver desvio 1 acima).
- Multi-tenant: `TicketAtendimento`/`WhatsappCanal`/etc usam `TenantScope`; webhooks já usam `withoutGlobalScopes()` onde apropriado — seguir o padrão já existente em cada arquivo tocado.
- `TraducaoService::traduzir()`/`detectarIdioma()` nunca bloqueiam o envio em caso de falha — todo novo código que os chama precisa manter esse contrato (retorno `null` → segue sem traduzir, nunca lança exceção pro chamador).
- Testes: PHPUnit clássico, `RefreshDatabase`, `Http::fake()`/`Mockery` pra IA e canais. Rodar via `php.bat artisan test` (Herd PHP, Windows — usar a ferramenta PowerShell).
- Deploy sempre via `./deploy.sh` a partir de `leadcerto-app/` — nunca editar a VPS direto.

---

### Task 1: Schema — `users.idioma`, `tenants.locale`, campos novos em `tickets_atendimento`

**Files:**
- Create: `database/migrations/2026_08_21_000001_add_idioma_ao_usuario_e_tenant.php`
- Create: `database/migrations/2026_08_21_000002_add_campos_deteccao_idioma_a_tickets.php`
- Modify: `app/Models/User.php` (fillable)
- Modify: `app/Models/Tenant.php` (fillable)
- Modify: `app/Models/TicketAtendimento.php:219-253` (fillable) e `:255-274` (casts)
- Test: `tests/Feature/IdiomaSchemaTest.php`

**Interfaces:**
- Produces: `users.idioma` (string, default `'pt-BR'`), `tenants.locale` (string, default `'pt-BR'`), `tickets_atendimento.idioma_pais_ddi` (string, nullable), `.idioma_origem` (enum `ddi`/`botao`/`ia`/`manual`, nullable), `.idioma_confianca` (decimal 3,2, nullable), `.idioma_atualizado_em` (timestamp, nullable), `.idioma_aguardando_escolha` (boolean, default false).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdiomaSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_novo_usuario_e_tenant_nascem_com_pt_br_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSame('pt-BR', $tenant->fresh()->locale);
        $this->assertSame('pt-BR', $user->fresh()->idioma);
    }

    public function test_ticket_aceita_os_campos_novos_de_deteccao_de_idioma(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'idioma_pais_ddi' => 'es-ES',
            'idioma_origem' => 'ddi',
            'idioma_confianca' => 0.90,
            'idioma_atualizado_em' => now(),
            'idioma_aguardando_escolha' => true,
        ]);

        $fresh = $ticket->fresh();
        $this->assertSame('es-ES', $fresh->idioma_pais_ddi);
        $this->assertSame('ddi', $fresh->idioma_origem);
        $this->assertSame('0.90', $fresh->idioma_confianca);
        $this->assertNotNull($fresh->idioma_atualizado_em);
        $this->assertTrue($fresh->idioma_aguardando_escolha);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=IdiomaSchemaTest`
Expected: FAIL — colunas/campos não existem ainda.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_08_21_000001_add_idioma_ao_usuario_e_tenant.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('idioma', 5)->default('pt-BR')->after('perfil');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('locale', 5)->default('pt-BR')->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('idioma');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
```

`database/migrations/2026_08_21_000002_add_campos_deteccao_idioma_a_tickets.php`:

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
            $table->string('idioma_pais_ddi', 5)->nullable()->after('idioma_lead');
            $table->enum('idioma_origem', ['ddi', 'botao', 'ia', 'manual'])->nullable()->after('idioma_pais_ddi');
            $table->decimal('idioma_confianca', 3, 2)->nullable()->after('idioma_origem');
            $table->timestamp('idioma_atualizado_em')->nullable()->after('idioma_confianca');
            $table->boolean('idioma_aguardando_escolha')->default(false)->after('idioma_atualizado_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['idioma_pais_ddi', 'idioma_origem', 'idioma_confianca', 'idioma_atualizado_em', 'idioma_aguardando_escolha']);
        });
    }
};
```

- [ ] **Step 4: Update the models**

Em `app/Models/User.php`, adicionar `'idioma'` ao array `$fillable`.

Em `app/Models/Tenant.php`, adicionar `'locale'` ao array `$fillable`.

Em `app/Models/TicketAtendimento.php`, adicionar ao `$fillable` (perto de `'idioma_lead'`):

```php
        'idioma_pais_ddi',
        'idioma_origem',
        'idioma_confianca',
        'idioma_atualizado_em',
        'idioma_aguardando_escolha',
```

E ao array retornado por `casts()`:

```php
            'idioma_confianca'          => 'decimal:2',
            'idioma_atualizado_em'      => 'datetime',
            'idioma_aguardando_escolha' => 'boolean',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php.bat artisan test --filter=IdiomaSchemaTest`
Expected: PASS (2 testes)

- [ ] **Step 6: Run the full suite to check for regressions**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest` conhecido)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_21_000001_add_idioma_ao_usuario_e_tenant.php \
        database/migrations/2026_08_21_000002_add_campos_deteccao_idioma_a_tickets.php \
        app/Models/User.php app/Models/Tenant.php app/Models/TicketAtendimento.php \
        tests/Feature/IdiomaSchemaTest.php
git commit -m "feat(idioma): schema — idioma do usuário/tenant, campos de detecção no ticket"
```

---

### Task 2: `PaisIdiomaService` — mapeamento DDI → idioma (Camada 1)

**Files:**
- Create: `app/Services/PaisIdiomaService.php`
- Test: `tests/Feature/PaisIdiomaServiceTest.php`

**Interfaces:**
- Produces: `PaisIdiomaService::sugerirIdioma(string $telefone): ?string` — recebe telefone no formato canônico (`55DDXXXXXXXX`, ver CLAUDE.md), retorna código de locale (`pt-BR`, `pt-PT`, `es-ES`, `en-US`) ou `null` se o DDI não for reconhecido.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\PaisIdiomaService;
use Tests\TestCase;

class PaisIdiomaServiceTest extends TestCase
{
    public function test_reconhece_ddi_brasileiro(): void
    {
        $this->assertSame('pt-BR', app(PaisIdiomaService::class)->sugerirIdioma('5521987654321'));
    }

    public function test_reconhece_ddi_portugues(): void
    {
        $this->assertSame('pt-PT', app(PaisIdiomaService::class)->sugerirIdioma('351912345678'));
    }

    public function test_reconhece_ddi_espanhol(): void
    {
        $this->assertSame('es-ES', app(PaisIdiomaService::class)->sugerirIdioma('34612345678'));
    }

    public function test_reconhece_ddi_americano(): void
    {
        $this->assertSame('en-US', app(PaisIdiomaService::class)->sugerirIdioma('12025551234'));
    }

    public function test_ddi_desconhecido_retorna_null(): void
    {
        $this->assertNull(app(PaisIdiomaService::class)->sugerirIdioma('8613800001234'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=PaisIdiomaServiceTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

/**
 * Camada 1 de detecção de idioma (ver
 * docs/superpowers/specs/2026-08-21-idioma-multilingue-atendimento-design.md):
 * sugere o idioma provável a partir do DDI do telefone, sem custo de IA. É só
 * uma SUGESTÃO inicial — nunca confirmação (um número espanhol não garante
 * que a pessoa fala espanhol). Lista fixa, cobre só os idiomas que este
 * projeto suporta hoje — não é uma lib de países do mundo todo.
 */
class PaisIdiomaService
{
    private const DDI_PARA_IDIOMA = [
        '55'  => 'pt-BR',
        '351' => 'pt-PT',
        '34'  => 'es-ES',
        '1'   => 'en-US',
    ];

    public function sugerirIdioma(string $telefoneCanonico): ?string
    {
        $digitos = preg_replace('/\D/', '', $telefoneCanonico);

        // Checa os DDIs de 3 dígitos antes dos de 2 — '351' não pode ser lido
        // como '35' (que nem existe na lista, mas o princípio vale em geral:
        // prefixo mais longo primeiro evita colisão).
        foreach (self::DDI_PARA_IDIOMA as $ddi => $idioma) {
            if (strlen($ddi) === 3 && str_starts_with($digitos, $ddi)) {
                return $idioma;
            }
        }
        foreach (self::DDI_PARA_IDIOMA as $ddi => $idioma) {
            if (strlen($ddi) === 2 && str_starts_with($digitos, $ddi)) {
                return $idioma;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=PaisIdiomaServiceTest`
Expected: PASS (5 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/PaisIdiomaService.php tests/Feature/PaisIdiomaServiceTest.php
git commit -m "feat(idioma): PaisIdiomaService — Camada 1, sugestão por DDI"
```

---

### Task 3: `TraducaoService` — alvo dinâmico + regra anti-oscilação

**Files:**
- Modify: `app/Services/TraducaoService.php`
- Test: `tests/Feature/TraducaoServiceAlvoEAntiOscilacaoTest.php`

**Interfaces:**
- Consumes: nada de novo (usa `OpenRouterService::chat()` já injetado)
- Produces: `TraducaoService::resolverIdiomaAtendente(?int $vendedorId, string $localeTenant): string` — resolve o idioma-alvo pra tradução de entrada (cliente → atendente): `users.idioma` do vendedor, se atribuído; senão `tenants.locale`; nunca retorna vazio (tem `'pt-BR'` como piso). `TraducaoService::deveAtualizarIdiomaLead(string $idiomaAtual, string $idiomaDetectado, \Illuminate\Support\Collection $ultimasMensagensIdioma, string $textoAtual): bool` — regra anti-oscilação: `true` só quando o idioma detectado difere do atual E (o texto atual é "longo" — acima de 40 caracteres — OU as últimas 2 mensagens do lead já estavam nesse mesmo idioma detectado).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TraducaoService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TraducaoServiceAlvoEAntiOscilacaoTest extends TestCase
{
    public function test_resolve_idioma_do_vendedor_quando_atribuido(): void
    {
        $tenant   = Tenant::factory()->create(['locale' => 'pt-BR']);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'idioma' => 'en-US']);

        $idioma = app(TraducaoService::class)->resolverIdiomaAtendente($vendedor->id, $tenant->locale);

        $this->assertSame('en-US', $idioma);
    }

    public function test_cai_pro_locale_do_tenant_sem_vendedor_atribuido(): void
    {
        $idioma = app(TraducaoService::class)->resolverIdiomaAtendente(null, 'es-ES');

        $this->assertSame('es-ES', $idioma);
    }

    public function test_nunca_muda_idioma_com_mensagem_curta_e_sem_historico(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect([]), textoAtual: 'ok'
        );

        $this->assertFalse($deve);
    }

    public function test_muda_idioma_com_mensagem_longa_mesmo_sem_historico(): void
    {
        $textoLongo = 'I would like to know if my reservation for next week is already confirmed, please.';

        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect([]), textoAtual: $textoLongo
        );

        $this->assertTrue($deve);
    }

    public function test_muda_idioma_com_duas_mensagens_consecutivas_no_mesmo_idioma(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'en',
            ultimasMensagensIdioma: collect(['en', 'en']), textoAtual: 'ok'
        );

        $this->assertTrue($deve);
    }

    public function test_nao_muda_quando_idioma_detectado_e_igual_ao_atual(): void
    {
        $deve = app(TraducaoService::class)->deveAtualizarIdiomaLead(
            idiomaAtual: 'pt', idiomaDetectado: 'pt',
            ultimasMensagensIdioma: collect(['pt', 'pt']), textoAtual: 'texto qualquer, tanto faz o tamanho'
        );

        $this->assertFalse($deve);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=TraducaoServiceAlvoEAntiOscilacaoTest`
Expected: FAIL — métodos não existem.

- [ ] **Step 3: Write the implementation**

Adicionar em `app/Services/TraducaoService.php`, dentro da classe:

```php
    /**
     * Alvo de tradução pra mensagens de ENTRADA (cliente → atendente):
     * o idioma real do atendente atribuído ao ticket, se houver; senão o
     * locale padrão do tenant. Nunca retorna vazio.
     */
    public function resolverIdiomaAtendente(?int $vendedorId, string $localeTenant): string
    {
        if ($vendedorId) {
            $idiomaVendedor = \App\Models\User::find($vendedorId)?->idioma;
            if ($idiomaVendedor) {
                return $idiomaVendedor;
            }
        }

        return $localeTenant ?: 'pt-BR';
    }

    /**
     * Regra anti-oscilação (Camada 3 do desenho de detecção): uma mensagem
     * curta isolada nunca muda o idioma corrente da conversa sozinha. Só
     * atualiza quando o texto é claramente longo (uma frase inteira, não um
     * "ok"/"thanks") OU quando as duas últimas mensagens do lead já estavam
     * no idioma recém-detectado (padrão consistente, não um one-off).
     */
    public function deveAtualizarIdiomaLead(
        string $idiomaAtual,
        string $idiomaDetectado,
        \Illuminate\Support\Collection $ultimasMensagensIdioma,
        string $textoAtual
    ): bool {
        if ($idiomaDetectado === $idiomaAtual) {
            return false;
        }

        $textoLongo = mb_strlen(trim($textoAtual)) > 40;
        if ($textoLongo) {
            return true;
        }

        $duasUltimasNoIdiomaDetectado = $ultimasMensagensIdioma->count() >= 2
            && $ultimasMensagensIdioma->slice(-2)->every(fn ($i) => $i === $idiomaDetectado);

        return $duasUltimasNoIdiomaDetectado;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=TraducaoServiceAlvoEAntiOscilacaoTest`
Expected: PASS (6 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/TraducaoService.php tests/Feature/TraducaoServiceAlvoEAntiOscilacaoTest.php
git commit -m "feat(idioma): TraducaoService ganha alvo dinâmico e regra anti-oscilação"
```

---

### Task 4: Camada 1 na criação do ticket — Uazapi + Covercut (paridade)

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php:254-264` (bloco de criação de ticket)
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php` (bloco equivalente de criação de ticket)
- Test: `tests/Feature/CamadaUmDdiTest.php`

**Interfaces:**
- Consumes: `PaisIdiomaService::sugerirIdioma(string $telefone): ?string` (Task 2)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CamadaUmDdiTest extends TestCase
{
    use RefreshDatabase;

    public function test_uazapi_marca_idioma_pais_ddi_e_idioma_lead_quando_bate_com_o_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-ddi-1', 'config' => ['instance_token' => 'inst-ddi-1'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-ddi-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '5521987654321@s.whatsapp.net',
                'messageid' => 'msg-ddi-1',
                'text' => 'Oi, tudo bem?',
            ],
        ]);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('pt-BR', $ticket->idioma_pais_ddi);
        $this->assertSame('pt', $ticket->idioma_lead);
        $this->assertSame('ddi', $ticket->idioma_origem);
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_covercut_marca_idioma_pais_ddi_quando_bate_com_o_tenant(): void
    {
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-ddi'],
        ]);

        $this->postComAssinatura([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5521987654321', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.ddi-1', 'type' => 'text', 'text' => 'Oi!'],
        ], 'segredo-ddi');

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('pt-BR', $ticket->idioma_pais_ddi);
    }
}
```

> **Formato real do payload do Covercut confirmado em `CovercutWebhookControllerTest.php`** (não é o formato bruto da Meta Cloud API — é um formato já achatado por esse provider específico): `POST /api/webhook/covercut` (sem token na URL), autenticado por HMAC no header `X-BSP-Signature` (segredo vem de `WhatsappCanal.config.webhook_secret`), corpo `{event, direction, from_number_id, contact: {wa_id, name}, message: {id, type, text}}`. Usar exatamente esse formato em todos os testes do Covercut deste plano, não o formato `entry[].changes[].value.messages[]` do Meta Cloud API cru.

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=CamadaUmDdiTest`
Expected: FAIL — `idioma_pais_ddi` continua `null`.

- [ ] **Step 3: Write the implementation**

Em `UazapiWebhookController.php`, dentro do bloco `TicketAtendimento::create([...])` (linha ~254-264), adicionar antes do `create`:

```php
                        $idiomaSugerido = app(\App\Services\PaisIdiomaService::class)->sugerirIdioma($contato->telefone);
                        $idiomaBate     = $idiomaSugerido && $idiomaSugerido === $tenant->locale;
```

E dentro do array do `create`, adicionar:

```php
                            'idioma_pais_ddi'     => $idiomaSugerido,
                            'idioma_lead'         => $idiomaBate ? substr($idiomaSugerido, 0, 2) : null,
                            'idioma_origem'       => $idiomaBate ? 'ddi' : null,
                            'idioma_atualizado_em' => $idiomaBate ? now() : null,
```

Repetir o mesmo padrão no bloco equivalente de criação de ticket em
`CovercutWebhookController.php` — localizar o `TicketAtendimento::create([...])`
correspondente (mesma estrutura, telefone já normalizado disponível no
`$contato`) e aplicar a mesma lógica.

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=CamadaUmDdiTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest` conhecido) — confirmar que nenhum teste existente de criação de ticket (Uazapi/Covercut) quebrou.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php \
        app/Http/Controllers/Webhook/CovercutWebhookController.php \
        tests/Feature/CamadaUmDdiTest.php
git commit -m "feat(idioma): Camada 1 (DDI) na criação do ticket, Uazapi + Covercut"
```

---

### Task 5: Detecção de entrada usa alvo dinâmico + anti-oscilação — Uazapi + Covercut (paridade)

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php` (bloco de detecção de idioma na mensagem do lead)
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php:254-267` (bloco equivalente)
- Test: `tests/Feature/DeteccaoIdiomaAlvoDinamicoTest.php`

**Interfaces:**
- Consumes: `TraducaoService::resolverIdiomaAtendente()`, `::deveAtualizarIdiomaLead()` (Task 3)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeteccaoIdiomaAlvoDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_covercut_traduz_pro_idioma_do_vendedor_atribuido_nao_mais_fixo_em_portugues(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.x'], 200)]);
        $tenant   = Tenant::factory()->create(['locale' => 'pt-BR']);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'idioma' => 'es-ES']);
        $canal    = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900001111']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano', 'vendedor_id' => $vendedor->id,
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $this->mock(TraducaoService::class, function ($mock) use ($vendedor) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()
                ->with($vendedor->id, 'pt-BR')->andReturn('es-ES');
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('Do you deliver to São Paulo? I need this done urgently please.')
                ->andReturn('en');
            $mock->shouldReceive('deveAtualizarIdiomaLead')->once()->andReturn(true);
            $mock->shouldReceive('traduzir')->once()
                ->with('Do you deliver to São Paulo? I need this done urgently please.', 'es-ES', 'en')
                ->andReturn('¿Entregan en São Paulo? Necesito esto urgentemente por favor.');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900001111', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.det-1', 'type' => 'text', 'text' => 'Do you deliver to São Paulo? I need this done urgently please.'],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-det');
        $canal->update(['config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-det']]);

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'lead')->first();
        $this->assertSame('¿Entregan en São Paulo? Necesito esto urgentemente por favor.', $mensagem->conteudo_pt);
        $this->assertSame('es-ES', $ticket->fresh()->idioma_lead);
        $this->assertSame('ia', $ticket->fresh()->idioma_origem);
    }

    /**
     * Regra de prioridade da spec: escolha explícita (Camada 2) ou manual
     * (Camada 4) nunca é sobreposta silenciosamente pela IA — mesmo que o
     * texto seja longo/consistente o bastante pra passar na regra
     * anti-oscilação normalmente.
     */
    public function test_ia_nao_sobrepoe_idioma_definido_por_escolha_explicita(): void
    {
        $tenant  = Tenant::factory()->create(['locale' => 'pt-BR']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-travado'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900009999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(), 'janela_expira_em' => now()->addHours(10),
            'idioma_lead' => 'es', 'idioma_origem' => 'botao',
        ]);

        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('resolverIdiomaAtendente')->once()->andReturn('pt-BR');
            $mock->shouldReceive('detectarIdioma')->once()
                ->with('I would like to know if my reservation for next week is already confirmed, please.')
                ->andReturn('en');
            $mock->shouldNotReceive('deveAtualizarIdiomaLead');
            $mock->shouldReceive('traduzir')->once()->andReturn('Tradução qualquer');
        });

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900009999', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.travado-1', 'type' => 'text', 'text' => 'I would like to know if my reservation for next week is already confirmed, please.'],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-travado');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        // idioma_lead continua 'es' (escolha explícita), mesmo a mensagem sendo
        // claramente em inglês e longa o bastante pra passar na regra normal.
        $this->assertSame('es', $ticket->fresh()->idioma_lead);
        $this->assertSame('botao', $ticket->fresh()->idioma_origem);
    }
}
```

> **Formato do payload confirmado** — ver a nota da Task 4: `POST /api/webhook/covercut`, HMAC no header `X-BSP-Signature`, corpo `{event, direction, from_number_id, contact, message}`. `WhatsappCanal.config.webhook_secret` precisa bater com o segredo usado pra assinar.

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=DeteccaoIdiomaAlvoDinamicoTest`
Expected: FAIL — o controller ainda chama `traduzir($conteudo, 'pt', $idiomaDetectado)` fixo.

- [ ] **Step 3: Write the implementation**

Em `CovercutWebhookController.php`, no bloco de detecção (linhas ~254-267 na versão atual, dentro do `if ($conteudo && ...)`), substituir o corpo por:

```php
        $idiomaMensagem = null;
        $conteudoPt     = null;
        if ($conteudo && in_array($tipoMensagem, ['texto', 'audio'], true)
            && ! str_starts_with(trim($conteudo), '[')) {
            $traducao = app(\App\Services\TraducaoService::class);

            $idiomaDetectado = $traducao->detectarIdioma($conteudo);
            if ($idiomaDetectado) {
                $idiomaAtual = $ticket->idioma_lead;

                // Regra de prioridade da spec: escolha explícita (Camada 2) e
                // alteração manual (Camada 4) só perdem pra uma nova escolha
                // explícita/manual — a IA nunca sobrepõe silenciosamente uma
                // vez que o cliente ou o atendente já decidiu.
                $idiomaTravado = in_array($ticket->idioma_origem, ['botao', 'manual'], true);

                if (! $idiomaAtual) {
                    // Primeira detecção do ticket — sempre aceita, sem regra anti-oscilação
                    // (não há "idioma atual" ainda pra comparar).
                    $ticket->update(['idioma_lead' => $idiomaDetectado, 'idioma_origem' => 'ia', 'idioma_atualizado_em' => now()]);
                    $idiomaAtual = $idiomaDetectado;
                } elseif ($idiomaDetectado !== $idiomaAtual && ! $idiomaTravado) {
                    $ultimasMensagens = \App\Models\Mensagem::withoutGlobalScopes()
                        ->where('ticket_id', $ticket->id)->where('remetente', 'lead')
                        ->orderByDesc('enviado_em')->limit(2)->pluck('idioma')->reverse()->values();

                    if ($traducao->deveAtualizarIdiomaLead($idiomaAtual, $idiomaDetectado, $ultimasMensagens, $conteudo)) {
                        $ticket->update(['idioma_lead' => $idiomaDetectado, 'idioma_origem' => 'ia', 'idioma_atualizado_em' => now()]);
                        $idiomaAtual = $idiomaDetectado;
                    }
                }

                $idiomaMensagem = $idiomaDetectado;

                // Achado real na revisão da Task 5 (2026-08-21): o alvo virou
                // dinâmico (locale de 5 chars, ex. 'es-ES'), mas o guard
                // continuava comparando contra o literal 'pt' — deixava de
                // traduzir quando o atendente não é brasileiro e o cliente
                // escreve em português, e desperdiçava chamada de IA quando
                // cliente e atendente já falam o mesmo idioma (comparação de
                // string cheia nunca batia, 'es' !== 'es-ES'). Resolve o
                // alvo ANTES do guard e compara só os 2 primeiros chars.
                $idiomaAlvo = $traducao->resolverIdiomaAtendente($ticket->vendedor_id, $tenant->locale);
                if (substr($idiomaAlvo, 0, 2) !== $idiomaDetectado) {
                    $conteudoPt = $traducao->traduzir($conteudo, $idiomaAlvo, $idiomaDetectado);
                }
            }
        }
```

Repetir o mesmo padrão no bloco equivalente de `UazapiWebhookController.php`
(mesma estrutura, mesma condição de guarda).

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=DeteccaoIdiomaAlvoDinamicoTest`
Expected: PASS

- [ ] **Step 5: Run the full suite, prestando atenção nos testes existentes de idioma**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`) — em especial confirmar que
`UazapiWebhookDeteccaoIdiomaTest`/`SdrResponderServiceTraducaoTest` (já
existentes) continuam passando: eles mockam `traduzir(..., 'pt', ...)`
como alvo fixo — como o novo alvo é dinâmico e o `resolverIdiomaAtendente()`
sem vendedor atribuído cai no `tenants.locale` (que é `'pt-BR'` por padrão
nos testes, `substr` os 2 primeiros caracteres bate com `'pt'`), os testes
antigos devem continuar passando sem alteração — mas rodar pra confirmar, e
ajustar o mock desses testes existentes pra `resolverIdiomaAtendente` +
`traduzir(..., 'pt-BR', ...)` **só se** o teste realmente quebrar, sem mudar
nada que já passa.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php \
        app/Http/Controllers/Webhook/CovercutWebhookController.php \
        tests/Feature/DeteccaoIdiomaAlvoDinamicoTest.php
git commit -m "feat(idioma): entrada traduz pro idioma real do atendente, com regra anti-oscilação"
```

---

### Task 6: Saída (bot e humano) usa idioma-origem real, não mais 'pt' fixo

**Files:**
- Modify: `app/Services/SdrResponderService.php:267-278`
- Modify: `app/Http/Controllers/Painel/KanbanController.php` (bloco de tradução em `enviarMensagem`)
- Test: `tests/Feature/SaidaIdiomaOrigemDinamicoTest.php`

**Interfaces:**
- Consumes: nada novo — só muda o parâmetro `$idiomaOrigem` passado pra `TraducaoService::traduzir()`, hoje fixo em `'pt'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaidaIdiomaOrigemDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_atendente_com_idioma_espanhol_traduz_a_partir_do_espanhol_nao_do_portugues(): void
    {
        $tenant   = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true, 'idioma' => 'es-ES']);
        $canal    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $contato  = Contato::factory()->create(['telefone' => '5511900002222']);
        $ticket   = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano', 'vendedor_id' => $vendedor->id,
            'status' => 'aberto', 'aberto_em' => now(), 'idioma_lead' => 'en',
        ]);

        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')->once()
                ->with('Hola, ¿cómo estás?', 'en', 'es-ES')
                ->andReturn('Hi, how are you?');
        });
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->actingAs($vendedor)
            ->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", ['conteudo' => 'Hola, ¿cómo estás?']);

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Hi, how are you?']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=SaidaIdiomaOrigemDinamicoTest`
Expected: FAIL — o controller ainda chama `traduzir($texto, $idiomaAlvo)` sem
passar `$idiomaOrigem`, então o mock (que espera `'es-ES'` como origem) não
bate.

- [ ] **Step 3: Write the implementation**

Em `app/Http/Controllers/Painel/KanbanController.php`, no bloco de tradução
dentro de `enviarMensagem()` (o trecho que chama
`app(\App\Services\TraducaoService::class)->traduzir($request->conteudo, $model->idioma_lead)`),
trocar pra passar o idioma do usuário autenticado como origem:

```php
        if ($model->idioma_lead && $model->idioma_lead !== 'pt') {
            $idiomaOrigemAtendente = $request->user()->idioma ?? 'pt-BR';
            // Mesmo achado da revisão da Task 5: traduzir() compara
            // $idiomaAlvo === $idiomaOrigem por string cheia — passar o
            // locale de 5 chars ('es-ES') faria isso nunca bater contra o
            // idioma_lead de 2 chars ('es'), mesmo quando já são o mesmo
            // idioma, desperdiçando uma chamada de IA. Normaliza pros 2
            // primeiros chars antes de chamar.
            $traduzido = app(\App\Services\TraducaoService::class)->traduzir(
                $request->conteudo, $model->idioma_lead, substr($idiomaOrigemAtendente, 0, 2)
            );
            if ($traduzido) {
                $textoParaEnviar = $traduzido;
                $idiomaEnviado    = $model->idioma_lead;
                $conteudoPt       = $request->conteudo;
            }
        }
```

Em `app/Services/SdrResponderService.php` (linhas ~267-278), o bot escreve
sempre no `tenants.locale` (não tem um "usuário" atendente — é a IA
respondendo em nome da operação):

```php
        if ($ticket->idioma_lead && $ticket->idioma_lead !== 'pt') {
            $idiomaOrigemBot = $ticket->tenant->locale ?? 'pt-BR';
            // Mesma normalização da nota acima (enviarMensagem) — traduzir()
            // compara por string cheia, então o locale de 5 chars precisa
            // virar 2 chars antes de comparar contra idioma_lead.
            $traduzida = app(\App\Services\TraducaoService::class)->traduzir(
                $resposta, $ticket->idioma_lead, substr($idiomaOrigemBot, 0, 2)
            );
            if ($traduzida) {
                $respostaParaEnviar = $traduzida;
                $idiomaEnviado       = $ticket->idioma_lead;
                $respostaPtOriginal  = $resposta;
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=SaidaIdiomaOrigemDinamicoTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`) — confirmar `KanbanEnviarMensagemTraducaoTest`/`SdrResponderServiceTraducaoTest` existentes continuam passando (usuários/tenants de teste sem `idioma`/`locale` explícito caem no default `'pt-BR'` da migration da Task 1, que ao ser truncado nos 2 primeiros caracteres em qualquer lugar que compare precisa considerar isso — mas aqui `$idiomaOrigem` é usado por inteiro, não truncado, então `'pt-BR'` como origem funciona igual a `'pt'` pro propósito da tradução).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php app/Services/SdrResponderService.php \
        tests/Feature/SaidaIdiomaOrigemDinamicoTest.php
git commit -m "feat(idioma): saída traduz a partir do idioma real do atendente/tenant, não mais 'pt' fixo"
```

---

### Task 7: `IdiomaEscolhaService` — monta e envia a Camada 2 (botão Uazapi / texto numerado Covercut)

**Files:**
- Create: `app/Services/IdiomaEscolhaService.php`
- Test: `tests/Feature/IdiomaEscolhaServiceTest.php`

**Interfaces:**
- Consumes: `KanbanBotaoActionService::enviarBotoes(TicketAtendimento, string, array): bool` (já existe), `$canal->servico()->enviarTextoDireto()` (já existe, ver `KanbanController::enviarMensagem` pro padrão de uso)
- Produces: `IdiomaEscolhaService::enviarEscolha(TicketAtendimento $ticket, array $idiomasDisponiveis): bool` — `$idiomasDisponiveis` é uma lista tipo `['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español']`. Envia via botão (Uazapi) ou texto numerado (Covercut, provider `'covercut'`); marca `idioma_aguardando_escolha = true` em caso de sucesso.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\IdiomaEscolhaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdiomaEscolhaServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $idiomas = ['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español'];

    public function test_envia_botoes_pro_canal_uazapi(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $contato = Contato::factory()->create(['telefone' => '5511900003333']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $resultado = app(IdiomaEscolhaService::class)->enviarEscolha($ticket, $this->idiomas);

        $this->assertTrue($resultado);
        $this->assertTrue($ticket->fresh()->idioma_aguardando_escolha);
    }

    public function test_envia_texto_numerado_pro_canal_covercut(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.x'], 200)]);
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900004444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $resultado = app(IdiomaEscolhaService::class)->enviarEscolha($ticket, $this->idiomas);

        $this->assertTrue($resultado);
        $this->assertTrue($ticket->fresh()->idioma_aguardando_escolha);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send')
            && str_contains($req['text']['body'] ?? '', '1) Português')
            && str_contains($req['text']['body'] ?? '', '2) English')
            && str_contains($req['text']['body'] ?? '', '3) Español'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=IdiomaEscolhaServiceTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Log;

/**
 * Camada 2 de detecção de idioma (ver
 * docs/superpowers/specs/2026-08-21-idioma-multilingue-atendimento-design.md):
 * pergunta explicitamente ao cliente qual idioma prefere, quando o DDI
 * diverge do locale do tenant. Uazapi manda botão interativo de verdade
 * (`KanbanBotaoActionService::enviarBotoes()`, infra já existente); Covercut
 * não tem suporte a botão interativo hoje (limitação já documentada em
 * docs/paridade-canais-whatsapp.md, não nova), então recebe um fallback de
 * texto numerado com o mesmo efeito.
 */
class IdiomaEscolhaService
{
    /**
     * @param array<string,string> $idiomasDisponiveis ['pt-BR' => 'Português', ...]
     */
    public function enviarEscolha(TicketAtendimento $ticket, array $idiomasDisponiveis): bool
    {
        $canal = $ticket->canal;
        if (! $canal) {
            return false;
        }

        $texto = '🌍 Notamos que seu número é de outro país. Em qual idioma você prefere ser atendido?';

        $enviado = $canal->provider === 'covercut'
            ? $this->enviarTextoNumerado($ticket, $texto, $idiomasDisponiveis)
            : $this->enviarBotoes($ticket, $texto, $idiomasDisponiveis);

        if ($enviado) {
            $ticket->update(['idioma_aguardando_escolha' => true]);
        }

        return $enviado;
    }

    private function enviarBotoes(TicketAtendimento $ticket, string $texto, array $idiomasDisponiveis): bool
    {
        $botoes = [];
        $i = 0;
        foreach ($idiomasDisponiveis as $codigo => $label) {
            $botoes[] = ['text' => $label, 'action' => 'idioma', 'target' => $codigo];
            $i++;
        }

        return app(KanbanBotaoActionService::class)->enviarBotoes($ticket, $texto, $botoes);
    }

    private function enviarTextoNumerado(TicketAtendimento $ticket, string $texto, array $idiomasDisponiveis): bool
    {
        $opcoes = [];
        $i = 1;
        foreach ($idiomasDisponiveis as $label) {
            $opcoes[] = "{$i}) {$label}";
            $i++;
        }

        $mensagemCompleta = $texto . "\n\nResponda com o número:\n" . implode("\n", $opcoes);

        $telefone = $ticket->contato?->telefone;
        if (! $telefone) {
            return false;
        }

        return $ticket->canal->servico()->enviarTextoDireto($ticket->canal, $telefone, $mensagemCompleta);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=IdiomaEscolhaServiceTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/IdiomaEscolhaService.php tests/Feature/IdiomaEscolhaServiceTest.php
git commit -m "feat(idioma): IdiomaEscolhaService — Camada 2, botão (Uazapi) e texto numerado (Covercut)"
```

---

### Task 8: Disparo automático da Camada 2 na criação do ticket, quando o DDI diverge

**Files:**
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php` (mesmo bloco da Task 4, ramo "não bate")
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php` (idem)
- Test: `tests/Feature/CamadaDoisDisparoAutomaticoTest.php`

**Interfaces:**
- Consumes: `IdiomaEscolhaService::enviarEscolha()` (Task 7)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CamadaDoisDisparoAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddi_divergente_dispara_a_escolha_de_idioma_por_botao(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant = Tenant::factory()->create(['locale' => 'pt-BR']);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-camada2-1', 'config' => ['instance_token' => 'inst-camada2-1'],
        ]);

        $this->postJson('/api/webhook/uazapi/wh-camada2-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe' => false, 'isGroup' => false,
                'chatid' => '351912345678@s.whatsapp.net',
                'messageid' => 'msg-camada2-1',
                'text' => 'Olá!',
            ],
        ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'menu') || str_contains($req->url(), 'button'));
    }
}
```

> Ajustar a asserção `Http::assertSent` pra bater com a URL real que
> `UazapiService::enviarMenuBotoes()` chama — conferir o método antes de
> escrever a asserção final, em vez de adivinhar o padrão da URL.

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=CamadaDoisDisparoAutomaticoTest`
Expected: FAIL — nenhuma chamada de botão é feita ainda.

- [ ] **Step 3: Write the implementation**

No mesmo bloco da Task 4 (criação de ticket), no ramo em que `$idiomaBate` é
`false` (idioma sugerido pelo DDI existe mas diverge do tenant), disparar a
Camada 2 depois de criar o ticket:

```php
                        if ($idiomaSugerido && ! $idiomaBate) {
                            app(\App\Services\IdiomaEscolhaService::class)->enviarEscolha(
                                $ticket, ['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español']
                            );
                        }
```

Chamar isso logo depois do `$ticket = TicketAtendimento::create([...])` em
ambos os controllers (Uazapi e Covercut), dentro do mesmo bloco de criação
de ticket novo.

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=CamadaDoisDisparoAutomaticoTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Webhook/UazapiWebhookController.php \
        app/Http/Controllers/Webhook/CovercutWebhookController.php \
        tests/Feature/CamadaDoisDisparoAutomaticoTest.php
git commit -m "feat(idioma): dispara Camada 2 automaticamente quando DDI diverge do tenant"
```

---

### Task 9: Processar a resposta da escolha — clique de botão (Uazapi) e texto numerado (Covercut)

**Files:**
- Modify: `app/Services/KanbanBotaoActionService.php:20-37` (adicionar case `'idioma'` no `match`)
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php` (checar `idioma_aguardando_escolha` antes do fluxo normal de mensagem)
- Test: `tests/Feature/ProcessarEscolhaIdiomaTest.php`

**Interfaces:**
- Consumes: nada novo de outras tasks — usa campos já criados na Task 1.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\KanbanBotaoActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessarEscolhaIdiomaTest extends TestCase
{
    use RefreshDatabase;

    public function test_clique_no_botao_de_idioma_atualiza_o_ticket(): void
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'idioma_pais_ddi' => 'es-ES', 'idioma_aguardando_escolha' => true,
            'botoes_ativos' => [
                ['text' => 'Português', 'action' => 'idioma', 'target' => 'pt-BR'],
                ['text' => 'English', 'action' => 'idioma', 'target' => 'en-US'],
                ['text' => 'Español', 'action' => 'idioma', 'target' => 'es-ES'],
            ],
        ]);

        $executou = app(KanbanBotaoActionService::class)->executar($ticket, 'idioma:1');

        $this->assertTrue($executou);
        $fresh = $ticket->fresh();
        $this->assertSame('en-US', substr($fresh->idioma_lead, 0, 5) === 'en-US' ? 'en-US' : $fresh->idioma_lead);
        $this->assertSame('en', $fresh->idioma_lead);
        $this->assertSame('botao', $fresh->idioma_origem);
        $this->assertFalse($fresh->idioma_aguardando_escolha);
    }

    public function test_resposta_numerica_no_covercut_atualiza_o_ticket_quando_aguardando_escolha(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*/messages/send' => \Illuminate\Support\Facades\Http::response(['id' => 'wamid.x'], 200)]);
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'], 'webhook_token' => 'wh-escolha-cc-1',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900005555']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'idioma_aguardando_escolha' => true, 'janela_expira_em' => now()->addHours(10),
        ]);

        $body       = json_encode([
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '123456',
            'contact' => ['wa_id' => '5511900005555', 'name' => 'Cliente'],
            'message' => ['id' => 'wamid.esc-1', 'type' => 'text', 'text' => '2'],
        ]);
        $assinatura = hash_hmac('sha256', $body, 'segredo-esc');
        $canal->update(['config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-esc']]);

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertSame('en', $ticket->idioma_lead);
        $this->assertFalse($ticket->idioma_aguardando_escolha);
    }
}
```

> Mesmo formato de payload confirmado nas notas das Tasks 4/5.

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=ProcessarEscolhaIdiomaTest`
Expected: FAIL — `'idioma'` não é uma action reconhecida ainda; Covercut não
checa `idioma_aguardando_escolha`.

- [ ] **Step 3: Write the implementation**

Em `app/Services/KanbanBotaoActionService.php`, no `match` de `executar()`,
adicionar o case:

```php
            'idioma'      => $this->definirIdioma($ticket, $botao['target'] ?? null),
```

E o método privado correspondente:

```php
    private function definirIdioma(TicketAtendimento $ticket, ?string $localeEscolhido): bool
    {
        if (! $localeEscolhido) {
            return false;
        }

        $ticket->update([
            'idioma_lead'               => substr($localeEscolhido, 0, 2),
            'idioma_origem'             => 'botao',
            'idioma_atualizado_em'      => now(),
            'idioma_aguardando_escolha' => false,
        ]);

        return true;
    }
```

Em `CovercutWebhookController.php`, antes do fluxo normal de criação de
mensagem (mesma região da Task 5), adicionar uma checagem: se
`$ticket->idioma_aguardando_escolha` for `true` e o conteúdo recebido for
um número simples (`1`, `2`, `3`), resolver contra a mesma lista de idiomas
usada na Task 8 (`['pt-BR', 'en-US', 'es-ES']`, nessa ordem) e chamar
`KanbanBotaoActionService::definirIdioma()` via reflection não é necessário
— extrair a lógica pro mesmo padrão do botão:

```php
        if ($ticket->idioma_aguardando_escolha && $conteudo && preg_match('/^\s*([123])\s*$/', $conteudo, $m)) {
            $idiomasOrdem = ['pt-BR', 'en-US', 'es-ES'];
            $escolhido    = $idiomasOrdem[((int) $m[1]) - 1] ?? null;
            if ($escolhido) {
                $ticket->update([
                    'idioma_lead' => substr($escolhido, 0, 2), 'idioma_origem' => 'botao',
                    'idioma_atualizado_em' => now(), 'idioma_aguardando_escolha' => false,
                ]);
                return; // resposta de escolha tratada — não cai no fluxo normal de mensagem
            }
        }
```

Posicionar esse bloco checando ANTES do bloco de detecção de idioma da Task 5
(pra não detectar/traduzir "2" como se fosse uma mensagem de conteúdo real).

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=ProcessarEscolhaIdiomaTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Services/KanbanBotaoActionService.php app/Http/Controllers/Webhook/CovercutWebhookController.php \
        tests/Feature/ProcessarEscolhaIdiomaTest.php
git commit -m "feat(idioma): processa a resposta da Camada 2 — botão (Uazapi) e texto numerado (Covercut)"
```

---

### Task 10: Camada 4 — comando `/idioma` no composer do Kanban

**Files:**
- Modify: `resources/views/kanban/index.blade.php` (dropdown `/` do composer, ~linha 1061)
- Create: `app/Http/Controllers/Painel/IdiomaEscolhaController.php`
- Modify: `routes/web.php` (nova rota)
- Test: `tests/Feature/IdiomaEscolhaControllerTest.php`

**Interfaces:**
- Consumes: `IdiomaEscolhaService::enviarEscolha()` (Task 7)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdiomaEscolhaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_atendente_reenvia_a_escolha_de_idioma_pelo_comando_manual(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'status' => 'aberto', 'aberto_em' => now(),
            // sem divergência de DDI nenhuma — comando manual funciona mesmo assim
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/idioma/solicitar-escolha");

        $response->assertOk();
        $this->assertTrue($ticket->fresh()->idioma_aguardando_escolha);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=IdiomaEscolhaControllerTest`
Expected: FAIL — rota 404.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\TicketAtendimento;
use App\Services\IdiomaEscolhaService;
use Illuminate\Http\JsonResponse;

class IdiomaEscolhaController extends Controller
{
    public function solicitarEscolha(int $ticket, IdiomaEscolhaService $service): JsonResponse
    {
        $model = TicketAtendimento::findOrFail($ticket);

        $enviado = $service->enviarEscolha(
            $model, ['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español']
        );

        if (! $enviado) {
            return response()->json(['message' => 'Não foi possível enviar a escolha de idioma.'], 502);
        }

        return response()->json(['ok' => true]);
    }
}
```

Em `routes/web.php`, dentro do mesmo grupo `api/painel` dos outros endpoints
de ticket (`Route::post('/kanban/ticket/{ticket}/mensagem', ...)`):

```php
        Route::post('/kanban/ticket/{ticket}/idioma/solicitar-escolha', [\App\Http\Controllers\Painel\IdiomaEscolhaController::class, 'solicitarEscolha']);
```

Em `resources/views/kanban/index.blade.php`, o método `onInputMensagem()`
(linhas 1059-1072) busca respostas prontas do servidor sempre que o texto
começa com `/`. Adicionar `/idioma` como uma entrada especial que não vem do
banco — injetada no início da lista quando o texto bater:

```js
        async onInputMensagem() {
            const val = this.novaMensagem;
            if (val.startsWith('/') && val.length > 1) {
                const q   = val.slice(1);
                const res = await this.api(`/api/painel/respostas-prontas/buscar?q=${encodeURIComponent(q)}`);
                const sugestoesServidor = res.ok ? (await res.json()).data : [];

                // Entrada especial, não vem do banco — dispara reenvio da escolha
                // de idioma (Camada 4) em vez de preencher o texto do composer.
                const sugestoesEspeciais = 'idioma'.startsWith(q.toLowerCase())
                    ? [{ codigo_curto: 'idioma', conteudo: null, especial: 'idioma' }]
                    : [];

                this.sugestoes = [...sugestoesEspeciais, ...sugestoesServidor];
                this.sugestaoSelecionada = 0;
            } else {
                this.sugestoes = [];
            }
        },
```

E o método `aplicarResposta()` (linhas 1074-1078) ganha um desvio no início:

```js
        async aplicarResposta(s) {
            if (!s) return;
            if (s.especial === 'idioma') {
                this.novaMensagem = '';
                this.sugestoes    = [];
                const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/idioma/solicitar-escolha`, 'POST');
                this.mostrarToast(res.ok ? 'Escolha de idioma reenviada ao cliente!' : 'Não foi possível reenviar agora.', res.ok ? 'sucesso' : 'erro');
                return;
            }
            this.novaMensagem = s.conteudo;
            this.sugestoes   = [];
        },
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=IdiomaEscolhaControllerTest`
Expected: PASS

- [ ] **Step 5: Confirmar que o Blade continua compilando**

Run: `php.bat artisan test --filter=KanbanConfigViewTest` (ou o smoke test
de compilação de Blade equivalente já usado neste projeto pra
`kanban/index.blade.php`, se houver um nomeado especificamente pra essa
view — conferir antes de assumir o nome)

- [ ] **Step 6: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/IdiomaEscolhaController.php routes/web.php \
        resources/views/kanban/index.blade.php tests/Feature/IdiomaEscolhaControllerTest.php
git commit -m "feat(idioma): Camada 4 — comando /idioma no composer do Kanban"
```

---

### Task 11: Idioma do atendente — seletor no menu lateral

**Files:**
- Create: `app/Http/Controllers/Painel/MeuPerfilController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php:394-406`
- Test: `tests/Feature/MeuPerfilControllerTest.php`

**Interfaces:**
- Produces: `MeuPerfilController::atualizarIdioma(Request): JsonResponse` — endpoint novo, nenhum lugar do sistema hoje tem "editar meu próprio perfil" (achado ao explorar: `AgenteController` é só pra admin/dono editar OUTROS agentes — convite, perfil, ativo/inativo — nunca o próprio usuário logado, e não tem gate de admin necessariamente adequado pra esse novo campo, que qualquer atendente de qualquer perfil precisa poder editar).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeuPerfilControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_atendente_atualiza_o_proprio_idioma(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true, 'idioma' => 'pt-BR']);

        $response = $this->actingAs($user)->postJson('/api/painel/perfil/idioma', ['idioma' => 'en-US']);

        $response->assertOk();
        $this->assertSame('en-US', $user->fresh()->idioma);
    }

    public function test_rejeita_idioma_fora_da_lista_suportada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true, 'idioma' => 'pt-BR']);

        $response = $this->actingAs($user)->postJson('/api/painel/perfil/idioma', ['idioma' => 'fr-FR']);

        $response->assertStatus(422);
        $this->assertSame('pt-BR', $user->fresh()->idioma);
    }

    public function test_qualquer_perfil_de_usuario_pode_editar_o_proprio_idioma_nao_so_dono_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/perfil/idioma', ['idioma' => 'es-ES']);

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=MeuPerfilControllerTest`
Expected: FAIL — rota 404.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeuPerfilController extends Controller
{
    private const IDIOMAS_SUPORTADOS = ['pt-BR', 'pt-PT', 'es-ES', 'en-US'];

    public function atualizarIdioma(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idioma' => 'required|string|in:' . implode(',', self::IDIOMAS_SUPORTADOS),
        ]);

        $request->user()->update(['idioma' => $validated['idioma']]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Add the route**

Em `routes/web.php`, dentro do grupo `api/painel` (`Route::prefix('api/painel')->middleware(['auth', 'tenant'])`), sem role específico — qualquer usuário autenticado edita o próprio idioma:

```php
    Route::post('/perfil/idioma', [\App\Http\Controllers\Painel\MeuPerfilController::class, 'atualizarIdioma']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php.bat artisan test --filter=MeuPerfilControllerTest`
Expected: PASS (3 testes)

- [ ] **Step 6: Add the selector to the sidebar**

Em `resources/views/layouts/app.blade.php`, no bloco que já mostra o nome
do usuário antes do botão "Sair" (linhas 394-406), adicionar um seletor:

```blade
        <div class="px-3 py-4 border-t border-gray-700">
            <div class="px-3 mb-1 text-xs text-gray-400 truncate">{{ $user?->nome }}</div>
            <div class="px-3 mb-2" x-data="{ idioma: '{{ $user?->idioma ?? 'pt-BR' }}', salvando: false }">
                <select x-model="idioma"
                        @change="salvando = true; fetch('{{ route('perfil.idioma') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({ idioma })
                        }).finally(() => salvando = false)"
                        class="w-full text-xs bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-gray-300">
                    <option value="pt-BR">🇧🇷 Português (Brasil)</option>
                    <option value="pt-PT">🇵🇹 Português (Portugal)</option>
                    <option value="es-ES">🇪🇸 Español</option>
                    <option value="en-US">🇺🇸 English</option>
                </select>
            </div>
            <form method="POST" action="{{ route('logout') }}">
```

E dar nome à rota adicionada no Step 4 (`->name('perfil.idioma')`), já que o
Blade acima usa `route('perfil.idioma')`.

- [ ] **Step 7: Confirmar que o layout compartilhado continua compilando**

Run: `php.bat artisan test --filter=KanbanConfigViewTest` (esse teste
renderiza uma view que estende `layouts.app`, então um erro de sintaxe
Blade no layout compartilhado também derruba esse teste — é o smoke test
disponível mais próximo sem precisar criar um novo).

- [ ] **Step 8: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest`)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Painel/MeuPerfilController.php routes/web.php \
        resources/views/layouts/app.blade.php tests/Feature/MeuPerfilControllerTest.php
git commit -m "feat(idioma): atendente escolhe o próprio idioma no menu lateral"
```

---

## Depois de todas as tasks

- [ ] Rodar `php.bat artisan test` completo uma última vez (suíte inteira).
- [ ] Deploy: a partir de `leadcerto-app/`, `bash ./deploy.sh`.
- [ ] Verificar `php artisan migrate:status` na VPS pós-deploy (confirmar as 2 migrations novas rodaram).
- [ ] Backfill manual (fora de migration, mesmo padrão usado pro catálogo de Cargo/Nathanel nesta sessão): confirmar que usuários e tenants existentes têm `idioma`/`locale` = `'pt-BR'` (já é o default da migration, então não deveria precisar de nada — só conferir).
- [ ] Atualizar `TAREFAS.md` registrando a conclusão desta feature.
