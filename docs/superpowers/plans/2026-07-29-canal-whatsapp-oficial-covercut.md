# Canal WhatsApp Oficial via Covercut — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar suporte ao canal WhatsApp Oficial (Meta Cloud API via Covercut) — receber mensagem real de lead e responder dentro da janela de conversa de 24h/72h, coexistindo com os números não-oficiais (Uazapi) já em produção.

**Architecture:** Reaproveita 100% do modelo de dados `whatsapp_canais`/`kanban_whatsapp_canais` já em produção (`tipo='oficial'`, `provider='covercut'` já são valores válidos, sem migration nova nessas tabelas). Nova interface pequena `CanalWhatsappInterface` (implementada por `UazapiChannelService`, que só embrulha o `HumanizacaoService` existente sem mudar seu comportamento, e `CovercutChannelService`, novo) permite que os pontos de envio já migrados (`SdrResponderService`, `KanbanController::enviarMensagem`) resolvam a implementação certa a partir de `$ticket->canal` em vez de assumir Uazapi. **Decisão de escopo deliberada (MVP, não é o desenho final):** o `CovercutWebhookController` é autocontido — cria/atualiza contato e ticket, salva mensagem, dispara resposta do bot — sem extrair lógica compartilhada do `UazapiWebhookController` (arquivo de maior risco do sistema, mexido em duas entregas seguidas já). MVP cobre só texto: sem mídia, sem botão, sem chamada de voz no canal oficial — o `UazapiWebhookController` continua intocado nesta entrega. Extrair um serviço compartilhado de verdade fica para depois de validar o canal oficial em produção.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8, Alpine.js v3, Tailwind CSS, PHPUnit (estilo clássico `test_*`, sem Pest).

## Global Constraints

- Nunca fazer deploy manual via SSH — sempre `git commit` local + `./deploy.sh` (regra do `CLAUDE.md` do projeto). `deploy.sh` já tem modo de manutenção (`php artisan down`/`up`) desde a entrega anterior.
- Toda migration de dados deve ser idempotente.
- Models de tenant usam `TenantScope` como global scope (`app/Scopes/TenantScope.php`) — sempre seguir essa convenção nos models/queries novos. `WhatsappCanal` já tem esse scope; consultas em contexto de webhook (sem sessão autenticada) usam `withoutGlobalScopes()`, igual ao padrão já estabelecido em `UazapiWebhookController`.
- Convenção de nome de migration: `YYYY_MM_DD_NNNNNN_verbo_descricao.php`, sufixo numérico de 6 dígitos manual.
- Testes em `tests/Feature/*.php`, PHPUnit clássico, métodos `test_descricao_em_snake_case()`, `use RefreshDatabase;`, factories via `Model::factory()->create([...])`.
- **Não usar templates pagos da Meta.** Fora da janela de conversa, o envio é bloqueado — sem fallback, sem retry automático.
- **Canal oficial nunca dispara mensagem proativamente** — só responde quem já escreveu.
- Credenciais da Covercut são globais da Lead Certo (uma conta só, `.env`), nunca por tenant — mesmo padrão de `UAZAPI_BASE_URL`/`UAZAPI_KEY` já existente em `config/services.php`.
- **Fora de escopo nesta entrega** (não implementar, não aparecer como TODO no código — só citado aqui para quem revisar não estranhar a ausência): Webhook de Alertas da Covercut, busca automática de números via API deles, envio de mídia/botões pelo canal oficial, extração de lógica compartilhada do `UazapiWebhookController`.

---

### Task 1: Migration — janela de conversa em `tickets_atendimento` + model

**Files:**
- Create: `database/migrations/2026_07_29_000001_add_janela_conversa_a_tickets_atendimento.php`
- Modify: `app/Models/TicketAtendimento.php`
- Test: `tests/Feature/TicketAtendimentoJanelaConversaTest.php`

**Interfaces:**
- Produces: `tickets_atendimento.janela_expira_em` (timestamp nullable), `tickets_atendimento.janela_origem_anuncio` (bool, default false) — consumidos pela Task 5 (`CovercutChannelService`, checa a janela antes de enviar) e Task 8 (`CovercutWebhookController`, atualiza a janela a cada mensagem inbound).

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
            $table->timestamp('janela_expira_em')->nullable()->after('whatsapp_canal_id');
            $table->boolean('janela_origem_anuncio')->default(false)->after('janela_expira_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['janela_expira_em', 'janela_origem_anuncio']);
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `..._add_janela_conversa_a_tickets_atendimento ... DONE`

- [ ] **Step 3: Atualizar `TicketAtendimento`**

Em `app/Models/TicketAtendimento.php`, adicionar ao `$fillable` (logo após `'whatsapp_canal_id',`):

```php
        'janela_expira_em',
        'janela_origem_anuncio',
```

E ao array `$casts` (procure o array `protected $casts = [...]` já existente e adicione estas duas linhas):

```php
        'janela_expira_em'      => 'datetime',
        'janela_origem_anuncio' => 'boolean',
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

class TicketAtendimentoJanelaConversaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_guarda_janela_de_conversa(): void
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut']);
        $contato = Contato::factory()->create();

        $expiraEm = now()->addHours(72);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => $expiraEm,
            'janela_origem_anuncio' => true,
        ]);

        $ticket->refresh();

        $this->assertTrue($ticket->janela_origem_anuncio);
        $this->assertEqualsWithDelta($expiraEm->timestamp, $ticket->janela_expira_em->timestamp, 2);
    }
}
```

- [ ] **Step 5: Rodar o teste**

Run: `php artisan test --filter=TicketAtendimentoJanelaConversaTest`
Expected: 1 passed

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_29_000001_add_janela_conversa_a_tickets_atendimento.php app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoJanelaConversaTest.php
git commit -m "feat: adiciona janela de conversa (24h/72h) em tickets_atendimento"
```

---

### Task 2: Migration — `mensagens.uazapi_message_id` vira `provider_message_id`

**Files:**
- Create: `database/migrations/2026_07_29_000002_renomeia_uazapi_message_id_para_provider_message_id.php`
- Modify: `app/Models/Mensagem.php:20-27` (`$fillable`)
- Modify: `app/Http/Controllers/Webhook/UazapiWebhookController.php` (3 usos do nome antigo)
- Test: `tests/Feature/MensagemProviderMessageIdTest.php`

**Interfaces:**
- Produces: `mensagens.provider_message_id` (substitui `uazapi_message_id`) — consumido pela Task 8 (`CovercutWebhookController` usa o mesmo campo para deduplicar eventos vindos da Covercut).

- [ ] **Step 1: Escrever a migration de rename**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->renameColumn('uazapi_message_id', 'provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->renameColumn('provider_message_id', 'uazapi_message_id');
        });
    }
};
```

Nota: `renameColumn` no MySQL via Doctrine DBAL preserva o índice unique existente na coluna automaticamente — não precisa recriar.

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `..._renomeia_uazapi_message_id_para_provider_message_id ... DONE`

- [ ] **Step 3: Atualizar `$fillable` em `Mensagem.php`**

Em `app/Models/Mensagem.php:27`, o array `$fillable` tem a entrada `'uazapi_message_id',` — trocar por `'provider_message_id',`. Sem esse passo, `Mensagem::create(['provider_message_id' => ...])` (usado pela Task 2 e pela Task 8) é silenciosamente descartado pela proteção de mass assignment, e a coluna renomeada nunca é preenchida — confirme com `grep -n "provider_message_id\|uazapi_message_id" app/Models/Mensagem.php` que sobrou só o nome novo.

- [ ] **Step 4: Atualizar as 3 referências em `UazapiWebhookController.php`**

Em `app/Http/Controllers/Webhook/UazapiWebhookController.php`, substituir as 3 ocorrências de `'uazapi_message_id'` por `'provider_message_id'`:
- Linha ~103 (dedup check): `Mensagem::withoutGlobalScopes()->where('uazapi_message_id', $messageId)->exists()` → `where('provider_message_id', $messageId)`
- Linha ~328 (create em `processarMensagemLead`): `'uazapi_message_id' => $msg['messageid'] ?? null,` → `'provider_message_id' => $msg['messageid'] ?? null,`
- Linha ~699 (create em `transferirParaHumano`): mesma troca.

Confira com `grep -n "uazapi_message_id" app/Http/Controllers/Webhook/UazapiWebhookController.php` antes e depois — deve dar 3 ocorrências antes, 0 depois.

- [ ] **Step 5: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MensagemProviderMessageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_grava_provider_message_id(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'provider_message_id' => 'wamid.ABC123',
            'enviado_em' => now(),
        ]);

        $this->assertDatabaseHas('mensagens', ['id' => $mensagem->id, 'provider_message_id' => 'wamid.ABC123']);
    }
}
```

- [ ] **Step 6: Rodar os testes**

Run: `php artisan test --filter=MensagemProviderMessageIdTest`
Expected: 1 passed

Run também: `php artisan test --filter=UazapiWebhook` (regressão — confirma que o rename não quebrou nenhum teste existente do webhook Uazapi)
Expected: todos os testes que já passavam antes continuam passando.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_29_000002_renomeia_uazapi_message_id_para_provider_message_id.php app/Models/Mensagem.php app/Http/Controllers/Webhook/UazapiWebhookController.php tests/Feature/MensagemProviderMessageIdTest.php
git commit -m "refactor: generaliza uazapi_message_id para provider_message_id"
```

---

### Task 3: Credenciais da Covercut em `config/services.php`

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example` (se existir; se não existir no repo, pular esta parte e só documentar no report)
- Test: nenhum (configuração pura, sem lógica) — validado indiretamente pelas Tasks 5/8/9.

**Interfaces:**
- Produces: `config('services.covercut.base_url')`, `config('services.covercut.api_key')`, `config('services.covercut.api_secret')` — consumidos pela Task 5 (`CovercutChannelService`) e Task 6 (endpoint de conectar número oficial).

- [ ] **Step 1: Adicionar o bloco `covercut` em `config/services.php`**

Abra `config/services.php`, localize o bloco existente:

```php
    'uazapi' => [
        'base_url' => env('UAZAPI_BASE_URL'),
        'key'      => env('UAZAPI_KEY'),
    ],
```

E adicione logo depois:

```php

    'covercut' => [
        'base_url'   => env('COVERCUT_BASE_URL', 'https://api.covercut.com.br/api/v1'),
        'api_key'    => env('COVERCUT_API_KEY'),
        'api_secret' => env('COVERCUT_API_SECRET'),
    ],
```

- [ ] **Step 2: Adicionar as variáveis em `.env.example`, se o arquivo existir**

Run: `test -f .env.example && grep -n "UAZAPI" .env.example`

Se o comando encontrar linhas: adicionar logo depois, seguindo o mesmo estilo:

```
COVERCUT_BASE_URL=https://api.covercut.com.br/api/v1
COVERCUT_API_KEY=
COVERCUT_API_SECRET=
```

Se `.env.example` não existir no repositório (confirme com `ls .env.example`), pular este passo — não criar o arquivo do zero, isso é fora do escopo desta task.

- [ ] **Step 3: Confirmar que o config carrega sem erro**

Run: `php artisan config:clear && php artisan tinker --execute="echo config('services.covercut.base_url');"`
Expected: imprime `https://api.covercut.com.br/api/v1` (valor default, já que `.env` local não tem a chave preenchida ainda — isso é esperado e correto, as chaves reais só são preenchidas em produção).

- [ ] **Step 4: Commit**

```bash
git add config/services.php
git add .env.example 2>/dev/null || true
git commit -m "feat: adiciona credenciais globais da Covercut em config/services.php"
```

---

### Task 4: `CanalWhatsappInterface` + `UazapiChannelService` (embrulha o `HumanizacaoService` existente)

**Files:**
- Create: `app/Services/Canais/CanalWhatsappInterface.php`
- Create: `app/Services/Canais/UazapiChannelService.php`
- Modify: `app/Models/WhatsappCanal.php`
- Test: `tests/Feature/UazapiChannelServiceTest.php`

**Interfaces:**
- Produces: `CanalWhatsappInterface::enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool`, `WhatsappCanal::servico(): CanalWhatsappInterface` — consumidos pela Task 7 (`SdrResponderService`, `KanbanController::enviarMensagem` passam a chamar `$ticket->canal->servico()->enviarTexto(...)` em vez de resolver `UazapiService`/`HumanizacaoService` direto).
- Consumes: `HumanizacaoService::processar(string $instanceToken, string $numero, string $texto): void` (já existe, `app/Services/HumanizacaoService.php` — não mexer nele, só embrulhar).

- [ ] **Step 1: Escrever a interface**

```php
<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;

interface CanalWhatsappInterface
{
    /**
     * Envia uma mensagem de texto para o telefone informado através do canal dado.
     * Retorna false (sem lançar exceção) em qualquer falha de envio — quem chama
     * decide como reagir (log, retry, etc), igual ao padrão já usado em UazapiService.
     */
    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool;
}
```

- [ ] **Step 2: Escrever `UazapiChannelService`**

```php
<?php

namespace App\Services\Canais;

use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens por um canal não-oficial (Uazapi), preservando exatamente o
 * comportamento já em produção: divide em balões, simula digitação, aplica delay.
 * Não muda nada do HumanizacaoService — só resolve o token do canal e delega.
 */
class UazapiChannelService implements CanalWhatsappInterface
{
    public function __construct(private HumanizacaoService $humanizacao) {}

    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        $this->humanizacao->processar($token, $telefone, $texto);

        return true;
    }
}
```

Nota: `HumanizacaoService::processar()` já retorna `void` (não indica sucesso/falha por balão — só loga warning internamente, comportamento pré-existente que não muda aqui). `enviarTexto()` retorna `true` sempre que consegue chamar o processamento com um token válido, mesmo que algum balão individual falhe — mesmo grau de garantia que o código atual já oferece hoje através do `HumanizacaoService`.

- [ ] **Step 3: Adicionar `servico()` em `WhatsappCanal`**

Em `app/Models/WhatsappCanal.php`, adicionar o método logo após `tokenUazapi()`:

```php
    public function servico(): \App\Services\Canais\CanalWhatsappInterface
    {
        return match ($this->provider) {
            'covercut' => app(\App\Services\Canais\CovercutChannelService::class),
            default    => app(\App\Services\Canais\UazapiChannelService::class),
        };
    }
```

(A classe `CovercutChannelService` referenciada aqui é criada na Task 5 — o `match` já a referencia agora porque o autoload do PHP só resolve a classe em tempo de execução, quando `servico()` for chamado; até lá, o arquivo compila normalmente mesmo sem a classe existir ainda, mas rode a suíte completa só depois da Task 5 estar pronta.)

- [ ] **Step 4: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use App\Services\Canais\UazapiChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_texto_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request->hasHeader('token', 'token-canal-uazapi'));
    }

    public function test_retorna_false_quando_canal_sem_token(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => []]);

        $enviado = app(UazapiChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertFalse($enviado);
    }

    public function test_whatsapp_canal_servico_resolve_uazapi_channel_service_para_provider_uazapi(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'provider' => 'uazapi']);

        $this->assertInstanceOf(UazapiChannelService::class, $canal->servico());
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=UazapiChannelServiceTest`
Expected: 3 passed

- [ ] **Step 6: Commit**

```bash
git add app/Services/Canais/CanalWhatsappInterface.php app/Services/Canais/UazapiChannelService.php app/Models/WhatsappCanal.php tests/Feature/UazapiChannelServiceTest.php
git commit -m "feat: adiciona CanalWhatsappInterface + UazapiChannelService (embrulha HumanizacaoService)"
```

---

### Task 5: `CovercutChannelService` (envio real + checagem de janela)

**Files:**
- Create: `app/Services/Canais/CovercutChannelService.php`
- Test: `tests/Feature/CovercutChannelServiceTest.php`

**Interfaces:**
- Consumes: `CanalWhatsappInterface` (Task 4), `config('services.covercut.*')` (Task 3), `WhatsappCanal.config['phone_number_id']` (populado pela Task 6).
- Produces: `CovercutChannelService::enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool` — bloqueia e loga se a janela do ticket já expirou; quem chama (Task 7) é responsável por resolver o ticket e checar `janela_expira_em` **antes** de chamar este método (ver Step 3 — a checagem de janela mora aqui dentro, recebendo o ticket).

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\Canais\CovercutChannelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CovercutChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    private function canalOficial(int $tenantId): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id' => $tenantId, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
    }

    public function test_envia_texto_via_covercut_dentro_da_janela(): void
    {
        Http::fake(['*/messages' => Http::response(['id' => 'wamid.xyz'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511999999999', 'Oi!');

        $this->assertTrue($enviado);
        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', config('services.covercut.api_key') ?? '')
                && $request['to'] === '5511999999999'
                && $request['text']['body'] === 'Oi!';
        });
    }

    public function test_bloqueia_envio_fora_da_janela(): void
    {
        Http::fake(); // nenhuma chamada HTTP deve acontecer
        Log::spy();

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subHour(), // já expirou
        ]);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511988888888', 'Oi!');

        $this->assertFalse($enviado);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_envia_normalmente_quando_nao_ha_ticket_para_o_telefone(): void
    {
        // Sem ticket em aberto para este telefone neste canal: não há janela pra checar
        // (ex: primeiro contato antes de qualquer ticket existir) — não bloqueia,
        // deixa a Covercut aceitar ou rejeitar (ela também respeita a janela do lado dela).
        Http::fake(['*/messages' => Http::response(['id' => 'wamid.abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarTexto($canal, '5511977777777', 'Oi!');

        $this->assertTrue($enviado);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=CovercutChannelServiceTest`
Expected: FAIL — `Class "App\Services\Canais\CovercutChannelService" not found`

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services\Canais;

use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens de texto pelo canal oficial (Meta Cloud API, via Covercut).
 * Nunca dispara proativamente — só responde dentro da janela de conversa (seção 4
 * do design, docs/superpowers/specs/2026-07-27-canal-whatsapp-oficial-covercut-design.md).
 * Sem templates pagos: fora da janela, o envio é bloqueado, sem fallback.
 */
class CovercutChannelService implements CanalWhatsappInterface
{
    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $canal->tenant_id)
            ->where('whatsapp_canal_id', $canal->id)
            ->whereHas('contato', fn ($q) => $q->where('telefone', $telefone))
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if ($ticket && $ticket->janela_expira_em && now()->greaterThan($ticket->janela_expira_em)) {
            Log::warning('CovercutChannelService: envio bloqueado, janela de conversa expirada', [
                'canal_id'  => $canal->id,
                'ticket_id' => $ticket->id,
                'expirou_em' => $ticket->janela_expira_em->toIso8601String(),
            ]);
            return false;
        }

        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('CovercutChannelService: canal sem phone_number_id configurado', ['canal_id' => $canal->id]);
            return false;
        }

        $baseUrl = config('services.covercut.base_url');

        $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])
            ->post("{$baseUrl}/messages", [
                'from' => $phoneNumberId,
                'to'   => $telefone,
                'type' => 'text',
                'text' => ['body' => $texto],
            ]);

        if (! $response->successful()) {
            Log::warning('CovercutChannelService: falha ao enviar texto', [
                'canal_id' => $canal->id,
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        }

        return $response->successful();
    }
}
```

Nota: o formato exato do payload de envio (`{"from", "to", "type", "text": {"body"}}`) segue a convenção mais comum de BSPs que espelham a Cloud API da Meta — **confirmar contra `api.covercut.com.br/docs/#configuracao` na hora de testar com um número real** (Task 6 já vai exercitar isso em produção); se o formato real divergir, ajustar aqui é uma mudança pequena e isolada, sem impacto no resto do sistema.

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=CovercutChannelServiceTest`
Expected: 3 passed

- [ ] **Step 5: Rodar a suíte completa (a referência circular da Task 4 fecha agora)**

Run: `php artisan test`
Expected: mesma contagem de antes + os testes novos desta e da Task 4, nenhuma regressão.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Canais/CovercutChannelService.php tests/Feature/CovercutChannelServiceTest.php
git commit -m "feat: adiciona CovercutChannelService com checagem de janela de conversa"
```

---

### Task 6: `WhatsappCanalOficialController` — adotar número já cadastrado na Covercut + rotas

**Files:**
- Create: `app/Http/Controllers/Painel/WhatsappCanalOficialController.php`
- Modify: `routes/web.php` (grupo `api/painel`, perto das rotas de `/whatsapp/canais` existentes)
- Test: `tests/Feature/WhatsappCanalOficialControllerTest.php`

**Interfaces:**
- Consumes: `config('services.covercut.*')` (Task 3).
- Produces: `GET/POST /api/painel/whatsapp/canais-oficiais`, `DELETE /api/painel/whatsapp/canais-oficiais/{canal}` — consumidos pela Task 9 (UI).

- [ ] **Step 1: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsappCanalOficialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $canais = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'oficial')
            ->orderBy('id')
            ->get(['id', 'status', 'phone', 'connected_since', 'config']);

        // phone_number_id não é segredo, mas o resto de config (webhook_secret) sim —
        // devolve só o phone_number_id pro frontend saber mostrar, nunca o segredo.
        $canais->transform(function ($canal) {
            $canal->phone_number_id = $canal->config['phone_number_id'] ?? null;
            unset($canal->config);
            return $canal;
        });

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number_id' => 'required|string|max:100',
            'telefone'        => 'required|string|max:20',
            'apelido'         => 'nullable|string|max:100',
        ]);

        $tenantId = $request->user()->tenant_id;

        $jaExiste = WhatsappCanal::where('tenant_id', $tenantId)
            ->where('tipo', 'oficial')
            ->whereJsonContains('config->phone_number_id', $validated['phone_number_id'])
            ->exists();

        if ($jaExiste) {
            return response()->json(['message' => 'Este número já está conectado neste tenant.'], 422);
        }

        $webhookUrl = config('app.url') . '/api/webhook/covercut';
        $baseUrl    = config('services.covercut.base_url');

        $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])
            ->post("{$baseUrl}/numbers/webhook", [
                'from'        => $validated['phone_number_id'],
                'webhook_url' => $webhookUrl,
                'enabled'     => true,
            ]);

        if (! $response->successful()) {
            return response()->json(['message' => 'Erro ao registrar o webhook na Covercut. Confira o phone_number_id.'], 502);
        }

        $webhookSecret = $response->json('webhook_secret');

        $canal = WhatsappCanal::create([
            'tenant_id' => $tenantId,
            'tipo'      => 'oficial',
            'provider'  => 'covercut',
            'status'    => 'connected',
            'phone'     => $validated['telefone'],
            'connected_since' => now(),
            'config'    => [
                'phone_number_id' => $validated['phone_number_id'],
                'webhook_secret'  => $webhookSecret,
                'apelido'         => $validated['apelido'] ?? null,
            ],
        ]);

        // Mesmo padrão já usado para número não-oficial (WhatsappCanalController::store):
        // vincula a todos os Kanbans do tenant, pra rotear mensagem inbound sem passo manual.
        $kanbanIds = Kanban::where('tenant_id', $tenantId)->pluck('id');
        $canal->kanbans()->syncWithoutDetaching($kanbanIds);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);
        abort_if($canal->tipo !== 'oficial', 404);

        $baseUrl = config('services.covercut.base_url');
        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if ($phoneNumberId) {
            Http::withHeaders([
                    'X-API-Key'    => config('services.covercut.api_key'),
                    'X-API-Secret' => config('services.covercut.api_secret'),
                ])
                ->post("{$baseUrl}/numbers/webhook", ['from' => $phoneNumberId, 'action' => 'delete']);
        }

        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
```

- [ ] **Step 2: Adicionar as rotas em `routes/web.php`**

No grupo `Route::prefix('api/painel')->middleware(['auth', 'tenant'])`, logo depois das rotas de `/whatsapp/canais` existentes (depois da linha do `DELETE /whatsapp/canais/{canal}`):

```php
    Route::get('/whatsapp/canais-oficiais', [\App\Http\Controllers\Painel\WhatsappCanalOficialController::class, 'index'])
        ->middleware('role:admin,dono');
    Route::post('/whatsapp/canais-oficiais', [\App\Http\Controllers\Painel\WhatsappCanalOficialController::class, 'store'])
        ->middleware('role:admin,dono');
    Route::delete('/whatsapp/canais-oficiais/{canal}', [\App\Http\Controllers\Painel\WhatsappCanalOficialController::class, 'destroy'])
        ->middleware('role:admin,dono');
```

- [ ] **Step 3: Escrever os testes**

```php
<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappCanalOficialControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_adota_numero_oficial_e_registra_webhook_na_covercut(): void
    {
        Http::fake([
            '*/numbers/webhook' => Http::response(['webhook_secret' => 'segredo-gerado'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
            'apelido'         => 'Principal',
        ]);

        $response->assertCreated();

        $canal = WhatsappCanal::where('tenant_id', $tenant->id)->where('tipo', 'oficial')->firstOrFail();
        $this->assertSame('covercut', $canal->provider);
        $this->assertSame('123456789', $canal->config['phone_number_id']);
        $this->assertSame('segredo-gerado', $canal->config['webhook_secret']);
        $this->assertTrue($kanban->canais->contains($canal));

        Http::assertSent(fn ($request) =>
            $request['from'] === '123456789' &&
            str_contains($request['webhook_url'], '/api/webhook/covercut')
        );
    }

    public function test_nao_adota_o_mesmo_numero_duas_vezes(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['webhook_secret' => 'x'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456789'],
        ]);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '123456789',
            'telefone'        => '5521981813106',
        ]);

        $response->assertStatus(422);
    }

    public function test_vendedor_nao_acessa_rotas_de_canal_oficial(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/whatsapp/canais-oficiais', [
            'phone_number_id' => '1', 'telefone' => '5511999999999',
        ]);

        $response->assertForbidden();
    }

    public function test_remove_numero_oficial_e_desregistra_webhook(): void
    {
        Http::fake(['*/numbers/webhook' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = $this->usuarioDono($tenant);
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999'],
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/painel/whatsapp/canais-oficiais/{$canal->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('whatsapp_canais', ['id' => $canal->id]);
        Http::assertSent(fn ($request) => $request['from'] === '999' && $request['action'] === 'delete');
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=WhatsappCanalOficialControllerTest`
Expected: 4 passed

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Painel/WhatsappCanalOficialController.php routes/web.php tests/Feature/WhatsappCanalOficialControllerTest.php
git commit -m "feat: WhatsappCanalOficialController permite adotar número já cadastrado na Covercut"
```

---

### Task 7: Consumidores de envio resolvem `$ticket->canal->servico()` em vez de assumir Uazapi

**Files:**
- Modify: `app/Services/SdrResponderService.php:86-98`
- Modify: `app/Http/Controllers/Painel/KanbanController.php:232-264` (`enviarMensagem`)
- Test: `tests/Feature/EnvioResolveServicoPorProviderTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal::servico(): CanalWhatsappInterface` (Task 4), `CanalWhatsappInterface::enviarTexto()` (Tasks 4/5).

- [ ] **Step 1: Atualizar `SdrResponderService`**

Em `app/Services/SdrResponderService.php`, substituir o bloco (linhas ~86-98):

```php
        // ── 5. Enviar via WhatsApp com humanização ───────────────────────────
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

por:

```php
        // ── 5. Enviar pelo canal certo (Uazapi ou Covercut, resolvido pelo ticket) ──
        $telefone = $ticket->contato?->telefone;
        $canal    = $ticket->canal;

        if ($telefone && $canal) {
            $enviado = $canal->servico()->enviarTexto($canal, $telefone, $resposta);
            if (! $enviado) {
                Log::warning('SdrResponder: envio não confirmado pelo canal', [
                    'ticket_id' => $ticket->id, 'canal_id' => $canal->id,
                ]);
            }
        } else {
            Log::warning('SdrResponder: sem canal ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_canal' => (bool) $canal,
            ]);
        }
```

Confirme que `use App\Services\HumanizacaoService;` (se existir como import direto) e a injeção de `HumanizacaoService` no construtor não ficam órfãs — se `$this->humanizacao` não for mais usado em nenhum outro lugar do arquivo depois desta troca, é seguro remover o parâmetro do construtor e o `use`; se for usado em outro método, deixar como está. Confira com `grep -n "humanizacao" app/Services/SdrResponderService.php` antes de decidir.

- [ ] **Step 2: Atualizar `KanbanController::enviarMensagem`**

Em `app/Http/Controllers/Painel/KanbanController.php`, substituir o método inteiro (linhas ~232-264):

```php
    public function enviarMensagem(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['conteudo' => 'required|string|min:1']);

        $model = TicketAtendimento::with(['contato', 'tenant', 'canal'])->findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $telefone = $model->contato->telefone;
        $canal    = $model->canal;

        if (! $canal) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        $enviado = $canal->servico()->enviarTexto($canal, $telefone, $request->conteudo);

        if (! $enviado) {
            return response()->json(['message' => 'Falha ao enviar pelo WhatsApp.'], 502);
        }

        $mensagem = Mensagem::create([
            'ticket_id'  => $ticket,
            'tenant_id'  => $model->tenant_id,
            'remetente'  => 'humano',
            'tipo'       => 'texto',
            'conteudo'   => $request->conteudo,
        ]);

        return response()->json(['mensagem_id' => $mensagem->id, 'enviado' => true], 201);
    }
```

Note que `enviarMidia` **não muda nesta task** — mídia pelo canal oficial está fora de escopo (seção 8 do design); `enviarMidia` continua chamando `UazapiService` direto, o que já é correto porque só canais não-oficiais devem chegar lá até esta entrega ser expandida.

- [ ] **Step 3: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvioResolveServicoPorProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_controller_envia_por_covercut_quando_canal_e_oficial(): void
    {
        Http::fake(['*/messages' => Http::response(['id' => 'wamid.1'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(5),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Olá, tudo bem?',
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['to'] === '5511999999999');
    }

    public function test_sdr_responder_envia_por_covercut_quando_canal_e_oficial(): void
    {
        Http::fake(['*/messages' => Http::response(['id' => 'wamid.2'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(5),
        ]);

        SdrResponderJob::dispatchSync($ticket->id, 'Preciso de ajuda', false, false, 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['to'] === '5511988888888');
    }
}
```

Rota confirmada em `routes/web.php:210`: `Route::post('/kanban/ticket/{ticket}/mensagem', [KanbanController::class, 'enviarMensagem'])`.

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=EnvioResolveServicoPorProviderTest`
Expected: 2 passed

Run também os testes de regressão dos dois arquivos tocados:
Run: `php artisan test --filter=KanbanEnviarMensagem`
Run: `php artisan test --filter=SdrResponder`
Expected: nenhuma regressão — os testes que já cobriam o caminho Uazapi continuam passando, já que `UazapiChannelService` (Task 4) preserva o comportamento antigo.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SdrResponderService.php app/Http/Controllers/Painel/KanbanController.php tests/Feature/EnvioResolveServicoPorProviderTest.php
git commit -m "refactor: SdrResponderService e KanbanController::enviarMensagem resolvem canal via servico()"
```

---

### Task 8: `CovercutWebhookController` — receber mensagem real (MVP: só texto)

**Files:**
- Create: `app/Http/Controllers/Webhook/CovercutWebhookController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/CovercutWebhookControllerTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal` (busca por `config->phone_number_id`), `TicketAtendimento`, `Contato`, `Mensagem`, `SequenciaService::iniciarParaTicket()`, `SdrResponderJob`, `KanbanColuna::chaveDeEntrada()` — mesmos modelos/serviços já usados por `UazapiWebhookController`, mas **sem reusar código dele** (decisão de escopo do cabeçalho do plano).
- Produces: `POST /api/webhook/covercut` (URL fixa, sem token na rota — diferente do padrão Uazapi).

- [ ] **Step 1: Adicionar a rota em `routes/api.php`**

Logo depois da linha `Route::post('/webhook/uazapi/{webhookToken}', ...)`:

```php
Route::post('/webhook/covercut', [\App\Http\Controllers\Webhook\CovercutWebhookController::class, 'handle']);
```

- [ ] **Step 2: Escrever o controller**

```php
<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\SequenciaService;
use App\Services\TelefoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do canal oficial (Covercut/Meta Cloud API). MVP: só texto — sem mídia,
 * sem botão, sem chamada de voz (fora de escopo, ver seção 8 do design técnico).
 * Deliberadamente autocontido (não reusa UazapiWebhookController) — ver Architecture
 * no cabeçalho do plano.
 */
class CovercutWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $phoneNumberId = $payload['to'] ?? $payload['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('Covercut webhook: payload sem phone_number_id/to identificável');
            abort(400);
        }

        $canal = WhatsappCanal::withoutGlobalScopes()
            ->where('provider', 'covercut')
            ->whereJsonContains('config->phone_number_id', $phoneNumberId)
            ->first();

        if (! $canal) {
            Log::warning('Covercut webhook: nenhum canal encontrado para phone_number_id', ['phone_number_id' => $phoneNumberId]);
            abort(404);
        }

        $assinaturaValida = $this->validarAssinatura($request, $canal);
        if (! $assinaturaValida) {
            Log::warning('Covercut webhook: assinatura inválida', ['canal_id' => $canal->id]);
            abort(401);
        }

        if (($payload['event'] ?? null) !== 'message' || ($payload['direction'] ?? null) !== 'inbound') {
            return response()->json(['ok' => true]); // evento que não é mensagem de entrada — ignora silenciosamente
        }

        $this->processarMensagem($payload, $canal);

        return response()->json(['ok' => true]);
    }

    private function validarAssinatura(Request $request, WhatsappCanal $canal): bool
    {
        $segredo = $canal->config['webhook_secret'] ?? null;
        if (! $segredo) {
            return false;
        }

        $assinaturaRecebida = $request->header('X-BSP-Signature', '');
        $assinaturaCalculada = hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($assinaturaCalculada, $assinaturaRecebida);
    }

    private function processarMensagem(array $payload, WhatsappCanal $canal): void
    {
        $tenant = $canal->tenant;

        $messageId = $payload['message']['id'] ?? null;

        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('Covercut webhook: mensagem duplicada ignorada', ['id' => $messageId]);
            return;
        }

        $telefoneRaw = $payload['contact']['wa_id'] ?? null;
        if (! $telefoneRaw) {
            return;
        }
        $telefone = $this->normalizarTelefone($telefoneRaw);

        $conteudo = $payload['message']['text']['body'] ?? ($payload['message']['text'] ?? null);
        $pushName = $payload['contact']['name'] ?? null;

        $temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
        $janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

        $contato = Contato::firstOrCreate(['telefone' => $telefone], ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']);

        VinculoContatoTenant::firstOrCreate(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        $ticketNovo = false;

        if ($ticket) {
            $ticket->update([
                'whatsapp_canal_id'     => $canal->id,
                'janela_expira_em'      => $janelaExpiraEm,
                'janela_origem_anuncio' => $temReferralAnuncio,
            ]);
        } else {
            $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

            $ticket = TicketAtendimento::create([
                'tenant_id'             => $tenant->id,
                'contato_id'            => $contato->id,
                'whatsapp_canal_id'     => $canal->id,
                'coluna_kanban'         => KanbanColuna::chaveDeEntrada($tenant->id),
                'agente_responsavel'    => 'bot',
                'sdr_persona_id'        => $persona?->id,
                'status'                => 'aberto',
                'origem'                => $temReferralAnuncio ? 'anuncio_meta' : 'whatsapp',
                'aberto_em'             => now(),
                'janela_expira_em'      => $janelaExpiraEm,
                'janela_origem_anuncio' => $temReferralAnuncio,
            ]);
            $ticketNovo = true;
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'            => $ticket->id,
                'tenant_id'            => $tenant->id,
                'remetente'            => 'lead',
                'tipo'                 => 'texto',
                'conteudo'             => $conteudo,
                'provider_message_id'  => $messageId,
                'enviado_em'           => now(),
            ]);
        }

        if ($ticket->followup_estagio_enviado !== 0) {
            $ticket->update(['followup_estagio_enviado' => 0]);
        }

        if ($ticketNovo) {
            app(SequenciaService::class)->iniciarParaTicket($ticket);
        } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
            dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, 0));
        }
    }

    private function normalizarTelefone(string $telefone): string
    {
        $normalizado = app(TelefoneService::class)->normalizar($telefone);
        if ($normalizado) {
            return $normalizado;
        }
        $digits = preg_replace('/\D/', '', $telefone);
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        return $digits;
    }
}
```

- [ ] **Step 3: Escrever os testes**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CovercutWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payloadAssinado(array $payload, string $segredo): array
    {
        $body = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);
        return [$payload, $assinatura];
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    public function test_mensagem_inbound_cria_ticket_novo_com_janela_de_24h(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'to' => '123456',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Fulano'],
            'message' => ['id' => 'wamid.001', 'type' => 'text', 'text' => ['body' => 'Olá, quero um orçamento']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();

        $contato = Contato::where('telefone', '5521988887777')->firstOrFail();
        $ticket  = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->firstOrFail();

        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
        $this->assertFalse($ticket->janela_origem_anuncio);
        $this->assertTrue($ticket->janela_expira_em->between(now()->addHours(23), now()->addHours(25)));
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Olá, quero um orçamento', 'provider_message_id' => 'wamid.001']);
    }

    public function test_mensagem_com_referral_de_anuncio_usa_janela_de_72h(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'to' => '123456',
            'contact' => ['wa_id' => '5521977776666', 'name' => 'Ciclana'],
            'message' => ['id' => 'wamid.002', 'type' => 'text', 'text' => ['body' => 'Vim do anúncio'], 'referral' => ['source_id' => 'ad123']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', Contato::where('telefone', '5521977776666')->value('id'))->firstOrFail();

        $this->assertTrue($ticket->janela_origem_anuncio);
        $this->assertTrue($ticket->janela_expira_em->between(now()->addHours(71), now()->addHours(73)));
    }

    public function test_rejeita_assinatura_invalida(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999', 'webhook_secret' => 'segredo-certo'],
        ]);

        $payload = ['event' => 'message', 'direction' => 'inbound', 'to' => '999', 'contact' => ['wa_id' => '5511999999999'], 'message' => ['id' => 'x', 'text' => ['body' => 'oi']]];
        $response = $this->postComAssinatura($payload, 'segredo-errado');

        $response->assertStatus(401);
    }

    public function test_ignora_mensagem_duplicada(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'to' => '123456',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Fulano'],
            'message' => ['id' => 'wamid.dup', 'type' => 'text', 'text' => ['body' => 'primeira']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();
        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertSame(1, Mensagem::withoutGlobalScopes()->where('provider_message_id', 'wamid.dup')->count());
    }

    public function test_ticket_ja_aberto_recebe_atualizacao_da_janela_sem_criar_ticket_novo(): void
    {
        Bus::fake();

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521988887777']);
        $ticketExistente = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subMinutes(5),
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'to' => '123456',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.reabre', 'type' => 'text', 'text' => ['body' => 'ainda estou aqui']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertSame(1, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
        $ticketExistente->refresh();
        $this->assertTrue($ticketExistente->janela_expira_em->isFuture());
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=CovercutWebhookControllerTest`
Expected: 5 passed

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Webhook/CovercutWebhookController.php routes/api.php tests/Feature/CovercutWebhookControllerTest.php
git commit -m "feat: CovercutWebhookController recebe mensagem real do canal oficial (MVP texto)"
```

---

### Task 9: UI — seção "WhatsApp Oficial" em Configurações

**Files:**
- Modify: `resources/views/configuracoes/whatsapp.blade.php`
- Test: `tests/Feature/ConfiguracoesWhatsappOficialViewTest.php`

**Interfaces:**
- Consumes: endpoints da Task 6 (`GET/POST /api/painel/whatsapp/canais-oficiais`, `DELETE .../{canal}`).

- [ ] **Step 1: Adicionar a seção "WhatsApp Oficial" no template**

Em `resources/views/configuracoes/whatsapp.blade.php`, logo depois do bloco `</div>` que fecha `<div x-data="whatsappCanais()" x-init="carregar()">` (linha 180, antes do `</div>` externo da linha 182), inserir:

```blade
    <div class="mt-10" x-data="whatsappCanaisOficiais()" x-init="carregar()">

    <div class="flex items-center justify-between mb-2">
        <h1 class="text-xl font-bold text-gray-800">WhatsApp Oficial (Business API)</h1>
        <button @click="mostrarFormulario = true" x-show="!mostrarFormulario"
                class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors">
            + Conectar número oficial
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-6">
        API oficial da Meta, via Covercut. Cadastre o número primeiro no painel da Covercut, depois cole os dados aqui.
        Só responde quem já escreveu — nunca envia mensagem por conta própria.
    </p>

    <template x-if="erro">
        <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200">
            <p class="text-sm text-red-600" x-text="erro"></p>
        </div>
    </template>

    <template x-if="mostrarFormulario">
        <div class="bg-white rounded-2xl shadow-sm p-5 mb-4 space-y-3">
            <div>
                <label class="text-xs font-medium text-gray-600">Phone Number ID (painel da Covercut)</label>
                <input x-model="novo.phone_number_id" type="text" placeholder="Ex: 123456789012345"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Telefone</label>
                <input x-model="novo.telefone" type="text" placeholder="Ex: 5521981813106"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div>
                <label class="text-xs font-medium text-gray-600">Apelido (opcional)</label>
                <input x-model="novo.apelido" type="text" placeholder="Ex: Principal"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-500 focus:ring-1 focus:ring-green-500 focus:outline-none">
            </div>
            <div class="flex gap-2 pt-1">
                <button @click="conectar()" :disabled="conectando"
                        class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-colors disabled:opacity-50">
                    <span x-show="!conectando">Conectar</span>
                    <span x-show="conectando">Conectando...</span>
                </button>
                <button @click="mostrarFormulario = false" class="px-4 py-2 rounded-lg text-gray-500 hover:text-gray-700 text-sm">
                    Cancelar
                </button>
            </div>
        </div>
    </template>

    <div class="space-y-4">
        <template x-for="canal in canais" :key="canal.id">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                        <span class="text-sm font-medium text-gray-800" x-text="canal.phone"></span>
                        <span class="text-xs text-gray-400" x-text="'ID: ' + canal.phone_number_id"></span>
                    </div>
                    <button @click="excluirCanal(canal)" class="text-red-300 hover:text-red-500 text-xs">Remover</button>
                </div>
            </div>
        </template>

        <template x-if="canais.length === 0 && !mostrarFormulario">
            <div class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-xl">
                Nenhum número oficial conectado ainda.
            </div>
        </template>
    </div>

    </div>
```

- [ ] **Step 2: Adicionar o componente Alpine `whatsappCanaisOficiais()` no `<script>` existente**

No mesmo arquivo, dentro da tag `<script>` já existente, logo depois da função `function whatsappCanais() { ... }`, adicionar:

```javascript
function whatsappCanaisOficiais() {
    return {
        canais: [],
        mostrarFormulario: false,
        conectando: false,
        erro: null,
        novo: { phone_number_id: '', telefone: '', apelido: '' },

        async carregar() {
            const res = await fetch('/api/painel/whatsapp/canais-oficiais', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
            });
            if (res.ok) this.canais = await res.json();
        },

        async conectar() {
            this.conectando = true;
            this.erro = null;
            const res = await fetch('/api/painel/whatsapp/canais-oficiais', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(this.novo),
            });
            this.conectando = false;
            if (res.ok) {
                this.novo = { phone_number_id: '', telefone: '', apelido: '' };
                this.mostrarFormulario = false;
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao conectar número oficial. Confira os dados.';
                } catch {
                    this.erro = 'Erro ao conectar número oficial. Confira os dados.';
                }
            }
        },

        async excluirCanal(canal) {
            if (!confirm('Remover este número oficial? Essa ação não pode ser desfeita — o webhook será desregistrado na Covercut.')) return;
            this.erro = null;
            const res = await fetch(`/api/painel/whatsapp/canais-oficiais/${canal.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (res.ok) {
                await this.carregar();
            } else {
                try {
                    const err = await res.json();
                    this.erro = err.message || 'Erro ao remover número. Tente novamente.';
                } catch {
                    this.erro = 'Erro ao remover número. Tente novamente.';
                }
            }
        },
    };
}
```

- [ ] **Step 3: Escrever um teste de smoke da view**

```php
<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracoesWhatsappOficialViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_de_configuracoes_whatsapp_mostra_secao_oficial(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get(route('configuracoes'));

        $response->assertOk();
        $response->assertSee('WhatsApp Oficial');
        $response->assertSee('Conectar número oficial');
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=ConfiguracoesWhatsappOficialViewTest`
Expected: 1 passed

- [ ] **Step 5: Testar manualmente no navegador**

Acesse `/configuracoes` logado como dono de um tenant de teste, confirme que a seção "WhatsApp Oficial" aparece abaixo da seção não-oficial, clique em "+ Conectar número oficial" e confirme que o formulário abre.

- [ ] **Step 6: Commit**

```bash
git add resources/views/configuracoes/whatsapp.blade.php tests/Feature/ConfiguracoesWhatsappOficialViewTest.php
git commit -m "feat: tela de configurações ganha seção WhatsApp Oficial (Covercut)"
```

---

## Notas para a revisão final de branch inteira

- Confirmar que nenhum código novo desta entrega toca em `UazapiWebhookController.php` além da Task 2 (rename de coluna) — é a garantia de que o canal não-oficial em produção não foi tocado.
- Confirmar que `enviarMidia` (`KanbanController`) continua intocado e que não há nenhum caminho que tente enviar mídia por um canal `provider='covercut'` sem um guard explícito — se algum consumidor além de `enviarMensagem`/`SdrResponderService` resolver `$ticket->canal` para decidir token, ele também precisa ser migrado ou ficar comprovadamente restrito a canais não-oficiais.
- `CovercutChannelServiceTest::test_envia_texto_via_covercut_dentro_da_janela` e o payload de `CovercutWebhookController` assumem um formato de request/response que **ainda não foi validado contra a Covercut de verdade** — antes de considerar esta entrega pronta para uso real (não só para merge), testar manualmente com um número real conectado via Task 6 e confirmar que o payload recebido bate com o parser da Task 8; ajustar os nomes de campo se divergirem (mudança pequena, isolada nessas duas classes).
- Testar deploy com `php artisan migrate` incluindo as duas migrations novas (Tasks 1 e 2) — nenhuma delas mexe em dado de produção existente além do rename de coluna (Task 2), que é reversível via `down()`.
