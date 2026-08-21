# Leitura de E-mail da Adriana — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer a Adriana ler e-mail (não só enviar) — e-mail recebido em `adrianaaviag@gmail.com` vira ticket no mesmo Kanban que ela já usa pro WhatsApp, na coluna de entrada, e ela responde pelo mesmo painel de sempre.

**Architecture:** Canal novo (`CanalEmail`, mesmo padrão do `WhatsappCanal`) ligado ao `GoogleToken` que já existe (escopo Gmail completo já concedido). Um command agendado a cada 5 min consulta a Gmail History API (delta desde o último cursor), cria `Contato`/`TicketAtendimento`/`Mensagem` pra cada e-mail novo do INBOX, e o `KanbanController::enviarMensagem` ganha um branch que manda a resposta dela de volta pela Gmail API em vez do WhatsApp quando o ticket é de canal e-mail.

**Tech Stack:** Laravel 13 / PHP 8.4 / MySQL 8, Gmail API via `Http` facade (mesmo padrão do `GoogleService` existente), PHPUnit clássico + `RefreshDatabase` + `Http::fake()`.

**Spec:** `docs/superpowers/specs/2026-08-21-leitura-email-adriana-design.md`

**Desvio da spec, decidido durante o mapeamento de arquivos (não muda a arquitetura aprovada):** a spec previa colunas novas `mensagens.email_message_id_externo` e `mensagens.email_assunto`. Achado ao ler `app/Models/Mensagem.php`: já existe `provider_message_id` (usado hoje pelos webhooks Uazapi/Covercut exatamente pra isso — guardar o id da mensagem no provedor e checar duplicata antes de criar) — reaproveitado aqui pro `Message-ID` do Gmail, sem coluna nova. `email_assunto` muda de lugar: é uma propriedade da THREAD (o ticket), não de cada mensagem individual, então vai em `tickets_atendimento.email_assunto` em vez de em `mensagens`. Resultado: uma migration a menos do que a spec previa.

## Global Constraints

- Multi-tenant: todo model de tenant usa `TenantScope` como global scope — nunca query global sem considerar isso (exceção documentada: `Contato`, global, isolado por `VinculoContatoTenant`).
- Toda criação de `Contato` precisa também criar/garantir o `VinculoContatoTenant` (`contato_id` + `tenant_id`) — sem isso o contato existe mas não aparece pro tenant.
- Nunca guardar senha/credencial em arquivo — só o token OAuth já persistido em `google_tokens` (padrão já existente, não muda aqui).
- Deploy sempre via `./deploy.sh` a partir de `leadcerto-app/` — nunca editar a VPS direto.
- Testes: PHPUnit clássico, `RefreshDatabase`, SQLite em memória, `Http::fake()`/Mockery pra chamadas externas. Rodar com `php.bat artisan test` (Herd PHP).
- Regra de paridade entre canais (CLAUDE.md) não se aplica aqui no sentido de "espelhar comportamento" — e-mail é canal novo, não duplicando um existente — mas aplica-se no sentido de "documentar no código quando algo do WhatsApp deliberadamente não existe pro e-mail" (ex.: sem janela 24h/72h, sem botões interativos).

---

### Task 1: Schema — migrations + model `CanalEmail`

**Files:**
- Create: `database/migrations/2026_08_21_000001_torna_telefone_opcional_em_contatos.php`
- Create: `database/migrations/2026_08_21_000002_create_canais_email_table.php`
- Create: `database/migrations/2026_08_21_000003_add_canal_email_a_tickets_atendimento.php`
- Create: `app/Models/CanalEmail.php`
- Modify: `app/Models/TicketAtendimento.php:219-253` (fillable) e `:255-274` (casts) e `:286-289` (relação `canal()`)
- Test: `tests/Feature/CanalEmailSchemaTest.php`

**Interfaces:**
- Produces: `CanalEmail` model (`tenant_id`, `google_token_id`, `kanban_id`, `status` enum `ativo`/`inativo`, `gmail_history_id` nullable string, `ultimo_poll_em` nullable datetime) com `tenant()`, `googleToken()`, `kanban()` (`BelongsTo`); `TicketAtendimento` ganha `canal_tipo` (enum `whatsapp`/`email`, default `whatsapp`), `canal_email_id` (nullable FK), `email_thread_id` (nullable string, indexado), `email_assunto` (nullable string), e relação `canalEmail(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\CanalEmail;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanalEmailSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_contato_pode_ser_criado_sem_telefone(): void
    {
        $contato = Contato::create(['email' => 'cliente@exemplo.com', 'origem' => 'email']);

        $this->assertNull($contato->telefone);
        $this->assertSame('cliente@exemplo.com', $contato->fresh()->email);
    }

    public function test_canal_email_pertence_a_tenant_google_token_e_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@gmail.com',
            'access_token' => 'x', 'refresh_token' => 'y', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);
        $kanban = Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);

        $canal = CanalEmail::create([
            'tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'kanban_id' => $kanban->id,
            'status' => 'ativo',
        ]);

        $this->assertTrue($canal->tenant->is($tenant));
        $this->assertTrue($canal->googleToken->is($token));
        $this->assertTrue($canal->kanban->is($kanban));
    }

    public function test_ticket_atendimento_aceita_canal_tipo_email_sem_whatsapp_canal_id(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::create(['email' => 'lead@exemplo.com', 'origem' => 'email']);
        $canal   = CanalEmail::create([
            'tenant_id' => $tenant->id,
            'google_token_id' => GoogleToken::create([
                'tenant_id' => $tenant->id, 'google_email' => 'a@gmail.com',
                'access_token' => 'x', 'refresh_token' => 'y', 'token_type' => 'Bearer',
                'expires_at' => now()->addHour(), 'scopes' => [],
            ])->id,
            'status' => 'ativo',
        ]);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'canal_tipo' => 'email', 'canal_email_id' => $canal->id,
            'email_thread_id' => 'thread-abc', 'email_assunto' => 'Dúvida sobre o serviço',
            'coluna_kanban' => 'novo',
        ]);

        $this->assertNull($ticket->whatsapp_canal_id);
        $this->assertSame('email', $ticket->fresh()->canal_tipo);
        $this->assertSame('thread-abc', $ticket->fresh()->email_thread_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=CanalEmailSchemaTest`
Expected: FAIL — classe `CanalEmail` não existe / colunas não existem.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_08_21_000001_torna_telefone_opcional_em_contatos.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->string('telefone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->string('telefone', 20)->nullable(false)->change();
        });
    }
};
```

`database/migrations/2026_08_21_000002_create_canais_email_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canais_email', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('google_token_id')->constrained('google_tokens')->cascadeOnDelete();
            $table->foreignId('kanban_id')->nullable()->constrained('kanbans')->nullOnDelete();
            $table->enum('status', ['ativo', 'inativo'])->default('inativo');
            $table->string('gmail_history_id', 100)->nullable();
            $table->timestamp('ultimo_poll_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canais_email');
    }
};
```

`database/migrations/2026_08_21_000003_add_canal_email_a_tickets_atendimento.php`:

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
            $table->enum('canal_tipo', ['whatsapp', 'email'])->default('whatsapp')->after('whatsapp_canal_id');
            $table->foreignId('canal_email_id')->nullable()->after('canal_tipo')
                ->constrained('canais_email')->nullOnDelete();
            $table->string('email_thread_id', 100)->nullable()->after('canal_email_id');
            $table->string('email_assunto', 500)->nullable()->after('email_thread_id');

            $table->index(['tenant_id', 'email_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canal_email_id');
            $table->dropColumn(['canal_tipo', 'email_thread_id', 'email_assunto']);
        });
    }
};
```

> Nota: `whatsapp_canal_id` já é `nullable()` desde `2026_07_27_000003_add_whatsapp_canal_id_to_tickets_atendimento.php` — nenhuma mudança necessária nele.

- [ ] **Step 4: Write `app/Models/CanalEmail.php`**

```php
<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanalEmail extends Model
{
    protected $table = 'canais_email';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'google_token_id',
        'kanban_id',
        'status',
        'gmail_history_id',
        'ultimo_poll_em',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_poll_em' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function googleToken(): BelongsTo
    {
        return $this->belongsTo(GoogleToken::class);
    }

    public function kanban(): BelongsTo
    {
        return $this->belongsTo(Kanban::class);
    }
}
```

- [ ] **Step 5: Modify `app/Models/TicketAtendimento.php`**

Adicionar ao array `$fillable` (depois de `'whatsapp_canal_id',` na linha 222):

```php
        'canal_tipo',
        'canal_email_id',
        'email_thread_id',
        'email_assunto',
```

Adicionar relação depois do método `canal()` (linha 286-289):

```php
    public function canalEmail(): BelongsTo
    {
        return $this->belongsTo(CanalEmail::class, 'canal_email_id');
    }
```

Adicionar `use App\Models\CanalEmail;` não é necessário (mesmo namespace `App\Models`).

- [ ] **Step 6: Run test to verify it passes**

Run: `php.bat artisan test --filter=CanalEmailSchemaTest`
Expected: PASS (3 testes)

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `php.bat artisan test`
Expected: PASS (exceto o `ExampleTest` conhecido, 302 vs 200 — falha pré-existente, ignorar)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_21_000001_torna_telefone_opcional_em_contatos.php \
        database/migrations/2026_08_21_000002_create_canais_email_table.php \
        database/migrations/2026_08_21_000003_add_canal_email_a_tickets_atendimento.php \
        app/Models/CanalEmail.php app/Models/TicketAtendimento.php \
        tests/Feature/CanalEmailSchemaTest.php
git commit -m "feat(email-adriana): schema — telefone opcional, canais_email, canal_tipo em tickets"
```

---

### Task 2: `GoogleService::listarHistoricoEmail()`

**Files:**
- Modify: `app/Services/GoogleService.php` (adicionar ao final da seção `// ── Gmail API ──`, depois de `enviarEmail()`, linha ~529)
- Test: `tests/Feature/GoogleServiceEmailTest.php` (novo arquivo — as 3 tasks de Gmail API usam o mesmo arquivo de teste)

**Interfaces:**
- Consumes: `GoogleToken` (existente), `tokenValido()` (existente, `GoogleService.php:108`)
- Produces: `listarHistoricoEmail(GoogleToken $token, ?string $historyId): array` — retorna `['messageIds' => string[], 'historyId' => ?string]`. Quando `$historyId` é `null`, faz busca inicial (não histórico morto) e devolve o `historyId` atual pra virar o cursor.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleServiceEmailTest extends TestCase
{
    use RefreshDatabase;

    private function tokenValido(): GoogleToken
    {
        $tenant = Tenant::factory()->create();

        return GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'adrianaaviag@gmail.com',
            'access_token' => 'token-valido', 'refresh_token' => 'refresh-x', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);
    }

    public function test_listar_historico_email_com_cursor_existente_retorna_ids_novos(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'msg-1', 'labelIds' => ['INBOX']]]]],
                    ['messagesAdded' => [['message' => ['id' => 'msg-2', 'labelIds' => ['INBOX', 'UNREAD']]]]],
                ],
                'historyId' => '99999',
            ], 200),
        ]);

        $resultado = app(GoogleService::class)->listarHistoricoEmail($this->tokenValido(), '88888');

        $this->assertSame(['msg-1', 'msg-2'], $resultado['messageIds']);
        $this->assertSame('99999', $resultado['historyId']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'startHistoryId=88888'));
    }

    public function test_listar_historico_email_ignora_mensagens_sem_label_inbox(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'msg-enviado', 'labelIds' => ['SENT']]]]],
                ],
                'historyId' => '100',
            ], 200),
        ]);

        $resultado = app(GoogleService::class)->listarHistoricoEmail($this->tokenValido(), '88888');

        $this->assertSame([], $resultado['messageIds']);
    }

    public function test_listar_historico_email_sem_cursor_faz_busca_inicial_e_devolve_history_id_atual(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/profile*' => Http::response(['historyId' => '50000'], 200),
        ]);

        $resultado = app(GoogleService::class)->listarHistoricoEmail($this->tokenValido(), null);

        $this->assertSame([], $resultado['messageIds']);
        $this->assertSame('50000', $resultado['historyId']);
    }

    public function test_listar_historico_email_com_token_invalido_retorna_vazio(): void
    {
        $token = $this->tokenValido();
        $token->update(['expires_at' => now()->subHour()]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $resultado = app(GoogleService::class)->listarHistoricoEmail($token, '88888');

        $this->assertSame(['messageIds' => [], 'historyId' => null], $resultado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: FAIL — método `listarHistoricoEmail` não existe.

- [ ] **Step 3: Write the implementation**

Adicionar em `app/Services/GoogleService.php`, logo depois do método `enviarEmail()` (antes do fechamento da classe, linha 529):

```php
    /**
     * Delta de e-mails novos desde o último cursor (Gmail History API).
     * Sem cursor (canal novo): não importa histórico morto — só estabelece
     * o cursor atual a partir do profile, sem listar nada.
     */
    public function listarHistoricoEmail(GoogleToken $token, ?string $historyId): array
    {
        $token = $this->tokenValido($token);
        if (! $token) return ['messageIds' => [], 'historyId' => null];

        if (! $historyId) {
            $res = Http::withToken($token->access_token)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');

            return [
                'messageIds' => [],
                'historyId'  => $res->successful() ? (string) $res->json('historyId') : null,
            ];
        }

        try {
            $res = Http::withToken($token->access_token)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/history', [
                    'startHistoryId' => $historyId,
                    'historyTypes'   => 'messageAdded',
                ]);

            if (! $res->successful()) {
                Log::warning('Gmail listarHistoricoEmail falhou', ['status' => $res->status(), 'body' => $res->body()]);
                return ['messageIds' => [], 'historyId' => $historyId];
            }

            $data       = $res->json();
            $messageIds = [];

            foreach ($data['history'] ?? [] as $evento) {
                foreach ($evento['messagesAdded'] ?? [] as $adicionada) {
                    $labels = $adicionada['message']['labelIds'] ?? [];
                    if (in_array('INBOX', $labels, true)) {
                        $messageIds[] = $adicionada['message']['id'];
                    }
                }
            }

            return [
                'messageIds' => $messageIds,
                'historyId'  => (string) ($data['historyId'] ?? $historyId),
            ];
        } catch (\Exception $e) {
            Log::error('Gmail listarHistoricoEmail exceção', ['erro' => $e->getMessage()]);
            return ['messageIds' => [], 'historyId' => $historyId];
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GoogleService.php tests/Feature/GoogleServiceEmailTest.php
git commit -m "feat(email-adriana): GoogleService::listarHistoricoEmail via Gmail History API"
```

---

### Task 3: `GoogleService::obterEmail()`

**Files:**
- Modify: `app/Services/GoogleService.php` (depois de `listarHistoricoEmail()`)
- Modify: `tests/Feature/GoogleServiceEmailTest.php` (mesmo arquivo da Task 2)

**Interfaces:**
- Produces: `obterEmail(GoogleToken $token, string $messageId): ?array` — retorna `['id', 'threadId', 'from', 'fromEmail', 'subject', 'messageIdHeader', 'corpo']` ou `null` se a mensagem não existir/token inválido. `corpo` é `''` quando não há `text/plain` nem `text/html` decodificável (quem chama decide o marcador de fallback).

- [ ] **Step 1: Write the failing test**

Adicionar em `tests/Feature/GoogleServiceEmailTest.php`:

```php
    public function test_obter_email_extrai_headers_e_corpo_text_plain(): void
    {
        $corpo = base64_encode('Olá, preciso de ajuda com o pedido 123.');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::response([
                'id' => 'msg-1', 'threadId' => 'thread-abc',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Cliente Teste <cliente@exemplo.com>'],
                        ['name' => 'Subject', 'value' => 'Dúvida sobre o pedido'],
                        ['name' => 'Message-ID', 'value' => '<abc123@mail.gmail.com>'],
                    ],
                    'mimeType' => 'text/plain',
                    'body'     => ['data' => strtr($corpo, '+/', '-_')],
                ],
            ], 200),
        ]);

        $resultado = app(GoogleService::class)->obterEmail($this->tokenValido(), 'msg-1');

        $this->assertSame('thread-abc', $resultado['threadId']);
        $this->assertSame('Cliente Teste <cliente@exemplo.com>', $resultado['from']);
        $this->assertSame('cliente@exemplo.com', $resultado['fromEmail']);
        $this->assertSame('Dúvida sobre o pedido', $resultado['subject']);
        $this->assertSame('<abc123@mail.gmail.com>', $resultado['messageIdHeader']);
        $this->assertSame('Olá, preciso de ajuda com o pedido 123.', $resultado['corpo']);
    }

    public function test_obter_email_usa_text_html_quando_nao_ha_text_plain(): void
    {
        $corpo = base64_encode('<p>Olá <b>mundo</b></p>');
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-2*' => Http::response([
                'id' => 'msg-2', 'threadId' => 'thread-xyz',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'outro@exemplo.com'],
                        ['name' => 'Subject', 'value' => 'Assunto'],
                        ['name' => 'Message-ID', 'value' => '<xyz@mail.gmail.com>'],
                    ],
                    'mimeType' => 'text/html',
                    'body'     => ['data' => strtr($corpo, '+/', '-_')],
                ],
            ], 200),
        ]);

        $resultado = app(GoogleService::class)->obterEmail($this->tokenValido(), 'msg-2');

        $this->assertSame('Olá mundo', $resultado['corpo']);
    }

    public function test_obter_email_sem_corpo_reconhecivel_retorna_corpo_vazio(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-3*' => Http::response([
                'id' => 'msg-3', 'threadId' => 'thread-3',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'x@exemplo.com'],
                        ['name' => 'Subject', 'value' => 'Sem texto'],
                    ],
                    'mimeType' => 'application/octet-stream',
                    'body'     => [],
                ],
            ], 200),
        ]);

        $resultado = app(GoogleService::class)->obterEmail($this->tokenValido(), 'msg-3');

        $this->assertSame('', $resultado['corpo']);
    }

    public function test_obter_email_mensagem_inexistente_retorna_null(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/msg-404*' => Http::response([], 404),
        ]);

        $resultado = app(GoogleService::class)->obterEmail($this->tokenValido(), 'msg-404');

        $this->assertNull($resultado);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: FAIL nos 4 testes novos — método `obterEmail` não existe.

- [ ] **Step 3: Write the implementation**

Adicionar em `app/Services/GoogleService.php`, depois de `listarHistoricoEmail()`:

```php
    public function obterEmail(GoogleToken $token, string $messageId): ?array
    {
        $token = $this->tokenValido($token);
        if (! $token) return null;

        try {
            $res = Http::withToken($token->access_token)
                ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", [
                    'format' => 'full',
                ]);

            if (! $res->successful()) {
                return null;
            }

            $data    = $res->json();
            $headers = collect($data['payload']['headers'] ?? []);
            $from    = $headers->firstWhere('name', 'From')['value'] ?? '';
            $subject = $headers->firstWhere('name', 'Subject')['value'] ?? '';
            $msgId   = $headers->firstWhere('name', 'Message-ID')['value'] ?? null;

            preg_match('/<?([\w.+\-]+@[\w\-]+\.[\w.\-]+)>?/', $from, $m);
            $fromEmail = $m[1] ?? $from;

            return [
                'id'              => $data['id'] ?? $messageId,
                'threadId'        => $data['threadId'] ?? null,
                'from'            => $from,
                'fromEmail'       => $fromEmail,
                'subject'         => $subject,
                'messageIdHeader' => $msgId,
                'corpo'           => $this->extrairCorpoEmail($data['payload'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::error('Gmail obterEmail exceção', ['messageId' => $messageId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Prioriza text/plain; sem isso, tenta text/html convertido pra texto
     * simples; sem nenhuma das duas, corpo vazio (quem chama decide o
     * marcador de fallback).
     */
    private function extrairCorpoEmail(array $payload): string
    {
        $partesPlain = $this->buscarParte($payload, 'text/plain');
        if ($partesPlain !== null) {
            return trim($this->decodificarBase64Url($partesPlain));
        }

        $partesHtml = $this->buscarParte($payload, 'text/html');
        if ($partesHtml !== null) {
            $texto = strip_tags($this->decodificarBase64Url($partesHtml));
            return trim(preg_replace('/\s+/', ' ', $texto));
        }

        return '';
    }

    private function buscarParte(array $payload, string $mimeType): ?string
    {
        if (($payload['mimeType'] ?? null) === $mimeType && ! empty($payload['body']['data'])) {
            return $payload['body']['data'];
        }

        foreach ($payload['parts'] ?? [] as $parte) {
            $encontrado = $this->buscarParte($parte, $mimeType);
            if ($encontrado !== null) {
                return $encontrado;
            }
        }

        return null;
    }

    private function decodificarBase64Url(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: PASS (8 testes no total do arquivo)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GoogleService.php tests/Feature/GoogleServiceEmailTest.php
git commit -m "feat(email-adriana): GoogleService::obterEmail — parse de headers e corpo"
```

---

### Task 4: `GoogleService::enviarRespostaEmail()`

**Files:**
- Modify: `app/Services/GoogleService.php` (depois de `obterEmail()` e seus helpers privados)
- Modify: `tests/Feature/GoogleServiceEmailTest.php`

**Interfaces:**
- Produces: `enviarRespostaEmail(GoogleToken $token, string $threadId, string $para, string $assunto, string $corpo, ?string $inReplyTo): bool`

- [ ] **Step 1: Write the failing test**

Adicionar em `tests/Feature/GoogleServiceEmailTest.php`:

```php
    public function test_enviar_resposta_email_manda_com_thread_id_e_headers_de_resposta(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent-1'], 200),
        ]);

        $enviado = app(GoogleService::class)->enviarRespostaEmail(
            $this->tokenValido(), 'thread-abc', 'cliente@exemplo.com', 'Dúvida sobre o pedido',
            'Claro, já te ajudo!', '<abc123@mail.gmail.com>'
        );

        $this->assertTrue($enviado);
        Http::assertSent(function ($req) {
            $body = $req->data();
            $this->assertSame('thread-abc', $body['threadId']);
            $raw = base64_decode(strtr($body['raw'], '-_', '+/'));
            $this->assertStringContainsString('To: cliente@exemplo.com', $raw);
            $this->assertStringContainsString('Subject: Re: Dúvida sobre o pedido', $raw);
            $this->assertStringContainsString('In-Reply-To: <abc123@mail.gmail.com>', $raw);
            $this->assertStringContainsString('References: <abc123@mail.gmail.com>', $raw);
            return true;
        });
    }

    public function test_enviar_resposta_email_nao_duplica_prefixo_re(): void
    {
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent-2'], 200)]);

        app(GoogleService::class)->enviarRespostaEmail(
            $this->tokenValido(), 'thread-abc', 'cliente@exemplo.com', 'Re: Dúvida', 'Ok!', null
        );

        Http::assertSent(function ($req) {
            $raw = base64_decode(strtr($req->data()['raw'], '-_', '+/'));
            $this->assertStringContainsString('Subject: Re: Dúvida', $raw);
            $this->assertStringNotContainsString('Re: Re:', $raw);
            return true;
        });
    }

    public function test_enviar_resposta_email_falha_http_retorna_false(): void
    {
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([], 500)]);

        $enviado = app(GoogleService::class)->enviarRespostaEmail(
            $this->tokenValido(), 'thread-abc', 'cliente@exemplo.com', 'Assunto', 'Corpo', null
        );

        $this->assertFalse($enviado);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: FAIL nos 3 testes novos — método não existe.

- [ ] **Step 3: Write the implementation**

Adicionar em `app/Services/GoogleService.php`, depois dos helpers privados de `obterEmail()`:

```php
    public function enviarRespostaEmail(
        GoogleToken $token,
        string $threadId,
        string $para,
        string $assunto,
        string $corpo,
        ?string $inReplyTo
    ): bool {
        $token = $this->tokenValido($token);
        if (! $token) return false;

        $assuntoResposta = str_starts_with(trim($assunto), 'Re:') ? $assunto : "Re: {$assunto}";

        $headers = ["To: {$para}", "Subject: {$assuntoResposta}", 'Content-Type: text/plain; charset=UTF-8'];
        if ($inReplyTo) {
            $headers[] = "In-Reply-To: {$inReplyTo}";
            $headers[] = "References: {$inReplyTo}";
        }

        $mensagem = implode("\r\n", $headers) . "\r\n\r\n{$corpo}";
        $raw      = rtrim(strtr(base64_encode($mensagem), '+/', '-_'), '=');

        try {
            $res = Http::withToken($token->access_token)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'raw'      => $raw,
                    'threadId' => $threadId,
                ]);

            if (! $res->successful()) {
                Log::warning('Gmail enviarRespostaEmail falhou', ['status' => $res->status(), 'body' => $res->body()]);
            }

            return $res->successful();
        } catch (\Exception $e) {
            Log::error('Gmail enviarRespostaEmail exceção', ['erro' => $e->getMessage()]);
            return false;
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=GoogleServiceEmailTest`
Expected: PASS (11 testes no total do arquivo)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GoogleService.php tests/Feature/GoogleServiceEmailTest.php
git commit -m "feat(email-adriana): GoogleService::enviarRespostaEmail mantém thread no cliente"
```

---

### Task 5: `EmailAtendimentoService::sincronizar()`

**Files:**
- Create: `app/Services/EmailAtendimentoService.php`
- Test: `tests/Feature/EmailAtendimentoServiceSincronizarTest.php`

**Interfaces:**
- Consumes: `GoogleService::listarHistoricoEmail()`, `GoogleService::obterEmail()` (Task 2/3); `CanalEmail`, `GoogleToken`, `Contato`, `TicketAtendimento`, `Mensagem`, `VinculoContatoTenant` (existentes); `KanbanColuna::chaveDeEntrada()`, `KanbanColuna::chavesComPapel()`, `PapelColunaKanban` (existentes).
- Produces: `sincronizar(CanalEmail $canal): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\CanalEmail;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\EmailAtendimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailAtendimentoServiceSincronizarTest extends TestCase
{
    use RefreshDatabase;

    private function canalDeTeste(): CanalEmail
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id, 'chave' => 'novo',
            'label' => 'Novo', 'papel' => PapelColunaKanban::Entrada, 'ordem' => 1,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id, 'chave' => 'aguardando',
            'label' => 'Aguardando', 'papel' => PapelColunaKanban::EmAndamento, 'ordem' => 2,
        ]);
        KanbanColuna::create([
            'tenant_id' => $tenant->id, 'kanban_id' => $kanban->id, 'chave' => 'resolvido',
            'label' => 'Resolvido', 'papel' => PapelColunaKanban::Encerramento, 'ordem' => 3,
        ]);
        $token = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'adrianaaviag@gmail.com',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);

        return CanalEmail::create([
            'tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'kanban_id' => $kanban->id,
            'status' => 'ativo', 'gmail_history_id' => '1000',
        ]);
    }

    public function test_mensagem_nova_cria_contato_sem_telefone_e_ticket_na_coluna_de_entrada(): void
    {
        $canal = $this->canalDeTeste();

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [['messagesAdded' => [['message' => ['id' => 'm1', 'labelIds' => ['INBOX']]]]]],
                'historyId' => '1001',
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'id' => 'm1', 'threadId' => 'thread-1',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'Cliente Novo <cliente@exemplo.com>'],
                        ['name' => 'Subject', 'value' => 'Preciso de ajuda'],
                        ['name' => 'Message-ID', 'value' => '<m1@mail.gmail.com>'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => strtr(base64_encode('Bom dia, tudo bem?'), '+/', '-_')],
                ],
            ], 200),
        ]);

        app(EmailAtendimentoService::class)->sincronizar($canal);

        $contato = Contato::where('email', 'cliente@exemplo.com')->first();
        $this->assertNotNull($contato);
        $this->assertNull($contato->telefone);
        $this->assertTrue(VinculoContatoTenant::where('contato_id', $contato->id)->where('tenant_id', $canal->tenant_id)->exists());

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame('email', $ticket->canal_tipo);
        $this->assertSame('thread-1', $ticket->email_thread_id);
        $this->assertSame('Preciso de ajuda', $ticket->email_assunto);
        $this->assertSame('novo', $ticket->coluna_kanban);

        $mensagem = Mensagem::withoutGlobalScopes()->where('ticket_id', $ticket->id)->first();
        $this->assertSame('lead', $mensagem->remetente);
        $this->assertSame('Bom dia, tudo bem?', $mensagem->conteudo);
        $this->assertSame('<m1@mail.gmail.com>', $mensagem->provider_message_id);

        $this->assertSame('1001', $canal->fresh()->gmail_history_id);
    }

    public function test_mensagem_em_thread_existente_vira_mensagem_no_ticket_certo(): void
    {
        $canal   = $this->canalDeTeste();
        $contato = Contato::create(['email' => 'ja@exemplo.com', 'origem' => 'email']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $canal->tenant_id]);
        $ticketExistente = TicketAtendimento::create([
            'tenant_id' => $canal->tenant_id, 'contato_id' => $contato->id,
            'canal_tipo' => 'email', 'canal_email_id' => $canal->id,
            'email_thread_id' => 'thread-ja', 'email_assunto' => 'Assunto original',
            'coluna_kanban' => 'aguardando', 'status' => 'aberto',
        ]);

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [['messagesAdded' => [['message' => ['id' => 'm2', 'labelIds' => ['INBOX']]]]]],
                'historyId' => '1002',
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m2*' => Http::response([
                'id' => 'm2', 'threadId' => 'thread-ja',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'ja@exemplo.com'],
                        ['name' => 'Subject', 'value' => 'Re: Assunto original'],
                        ['name' => 'Message-ID', 'value' => '<m2@mail.gmail.com>'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => strtr(base64_encode('Mais uma dúvida'), '+/', '-_')],
                ],
            ], 200),
        ]);

        app(EmailAtendimentoService::class)->sincronizar($canal);

        $this->assertSame(1, TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count());
        $mensagem = Mensagem::withoutGlobalScopes()->where('ticket_id', $ticketExistente->id)->first();
        $this->assertSame('Mais uma dúvida', $mensagem->conteudo);
    }

    public function test_ticket_encerrado_reabre_na_coluna_em_andamento(): void
    {
        $canal   = $this->canalDeTeste();
        $contato = Contato::create(['email' => 'volta@exemplo.com', 'origem' => 'email']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $canal->tenant_id]);
        $ticketEncerrado = TicketAtendimento::create([
            'tenant_id' => $canal->tenant_id, 'contato_id' => $contato->id,
            'canal_tipo' => 'email', 'canal_email_id' => $canal->id,
            'email_thread_id' => 'thread-volta', 'email_assunto' => 'Pedido',
            'coluna_kanban' => 'resolvido', 'status' => 'encerrado', 'encerrado_em' => now()->subDays(2),
        ]);

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [['messagesAdded' => [['message' => ['id' => 'm3', 'labelIds' => ['INBOX']]]]]],
                'historyId' => '1003',
            ], 200),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m3*' => Http::response([
                'id' => 'm3', 'threadId' => 'thread-volta',
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'volta@exemplo.com'],
                        ['name' => 'Subject', 'value' => 'Re: Pedido'],
                        ['name' => 'Message-ID', 'value' => '<m3@mail.gmail.com>'],
                    ],
                    'mimeType' => 'text/plain',
                    'body' => ['data' => strtr(base64_encode('Voltei com outra dúvida'), '+/', '-_')],
                ],
            ], 200),
        ]);

        app(EmailAtendimentoService::class)->sincronizar($canal);

        $ticketEncerrado->refresh();
        $this->assertSame('aberto', $ticketEncerrado->status);
        $this->assertSame('aguardando', $ticketEncerrado->coluna_kanban);
    }

    public function test_mensagem_enviada_pela_propria_adriana_e_ignorada(): void
    {
        $canal = $this->canalDeTeste();

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [['messagesAdded' => [['message' => ['id' => 'm-sent', 'labelIds' => ['SENT']]]]]],
                'historyId' => '1004',
            ], 200),
        ]);

        app(EmailAtendimentoService::class)->sincronizar($canal);

        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->count());
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/messages/m-sent'));
    }

    public function test_token_invalido_nao_quebra_e_so_loga(): void
    {
        $canal = $this->canalDeTeste();
        $canal->googleToken->update(['expires_at' => now()->subHour()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        app(EmailAtendimentoService::class)->sincronizar($canal);

        $this->assertSame(0, TicketAtendimento::withoutGlobalScopes()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=EmailAtendimentoServiceSincronizarTest`
Expected: FAIL — classe `EmailAtendimentoService` não existe.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Enums\PapelColunaKanban;
use App\Models\CanalEmail;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Canal de e-mail unificado no mesmo Kanban do WhatsApp — ver
 * docs/superpowers/specs/2026-08-21-leitura-email-adriana-design.md.
 * Cada thread de e-mail (Gmail threadId) vira um ticket próprio, sempre
 * separado do que o mesmo contato manda por WhatsApp (decisão do Leonardo).
 */
class EmailAtendimentoService
{
    public function __construct(private GoogleService $google) {}

    public function sincronizar(CanalEmail $canal): void
    {
        Cache::lock("email-sincronizar:{$canal->id}", 60)->block(5, function () use ($canal) {
            $this->sincronizarInterno($canal);
        });
    }

    private function sincronizarInterno(CanalEmail $canal): void
    {
        $token = $canal->googleToken;

        $resultado = $this->google->listarHistoricoEmail($token, $canal->gmail_history_id);

        if ($resultado['historyId'] === null && empty($resultado['messageIds'])) {
            // Token inválido (tokenValido() já logou o motivo dentro do GoogleService)
            // ou falha de rede — nada a fazer, o cursor não avança.
            return;
        }

        foreach ($resultado['messageIds'] as $messageId) {
            $this->processarMensagem($canal, $messageId);
        }

        $canal->update([
            'gmail_history_id' => $resultado['historyId'] ?? $canal->gmail_history_id,
            'ultimo_poll_em'   => now(),
        ]);
    }

    private function processarMensagem(CanalEmail $canal, string $messageId): void
    {
        $email = $this->google->obterEmail($canal->googleToken, $messageId);
        if (! $email || ! $email['threadId']) {
            return;
        }

        // Já existe pelo provider_message_id — evita duplicar se o cursor
        // se sobrepuser entre duas execuções (mesmo padrão de dedup já usado
        // pelos webhooks Uazapi/Covercut).
        if ($email['messageIdHeader'] && Mensagem::withoutGlobalScopes()
                ->where('provider_message_id', $email['messageIdHeader'])->exists()) {
            return;
        }

        $contato = $this->resolverContato($canal->tenant_id, $email['fromEmail'], $email['from']);
        $ticket  = $this->resolverTicket($canal, $contato, $email['threadId'], $email['subject']);

        Mensagem::create([
            'ticket_id'            => $ticket->id,
            'tenant_id'            => $canal->tenant_id,
            'remetente'            => 'lead',
            'tipo'                 => 'texto',
            'conteudo'             => $email['corpo'] !== '' ? $email['corpo'] : '[E-mail sem conteúdo legível]',
            'provider_message_id'  => $email['messageIdHeader'],
        ]);
    }

    private function resolverContato(int $tenantId, string $email, string $nomeExibicao): Contato
    {
        $contato = Contato::withTrashed()->where('email', $email)->first();

        if ($contato) {
            if ($contato->trashed()) {
                $contato->restore();
            }
        } else {
            $nome    = trim(preg_replace('/<.*?>/', '', $nomeExibicao)) ?: null;
            $contato = Contato::create(['email' => $email, 'nome' => $nome, 'origem' => 'email']);
        }

        VinculoContatoTenant::firstOrCreate(['contato_id' => $contato->id, 'tenant_id' => $tenantId]);

        return $contato;
    }

    private function resolverTicket(CanalEmail $canal, Contato $contato, string $threadId, string $assunto): TicketAtendimento
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $canal->tenant_id)
            ->where('email_thread_id', $threadId)
            ->first();

        if ($ticket) {
            if ($ticket->status === 'encerrado') {
                $colunaDestino = KanbanColuna::primeiraChaveComPapel($canal->tenant_id, PapelColunaKanban::EmAndamento)
                    ?? KanbanColuna::chaveDeEntrada($canal->tenant_id);

                $ticket->update(['status' => 'aberto', 'coluna_kanban' => $colunaDestino]);

                Log::info("EmailAtendimentoService: ticket #{$ticket->id} reaberto (thread {$threadId})");
            }

            return $ticket;
        }

        return TicketAtendimento::create([
            'tenant_id'        => $canal->tenant_id,
            'contato_id'       => $contato->id,
            'canal_tipo'       => 'email',
            'canal_email_id'   => $canal->id,
            'email_thread_id'  => $threadId,
            'email_assunto'    => $assunto,
            'coluna_kanban'    => KanbanColuna::chaveDeEntrada($canal->tenant_id),
            'agente_responsavel' => 'humano',
            'status'           => 'aberto',
            'aberto_em'        => now(),
            'origem'           => 'email',
        ]);
    }
}
```

> Nota de design (decidida ao implementar, dentro do escopo já aprovado na spec): o "reabrir" de e-mail **não** reusa `TicketReaberturaService` — aquele serviço identifica o ticket encerrado por `contato_id` (correto pro WhatsApp, onde um contato tem uma única conversa contínua) e classifica com IA se a mensagem justifica reabrir. Pra e-mail, o ticket é identificado por `email_thread_id` — como é literalmente a mesma thread continuando, reabrir é sempre a decisão certa, sem precisar de classificação por IA (mais simples e mais barato). Reusar o serviço existente exigiria adaptá-lo pra também aceitar `canal_email_id`, o que misturaria as duas semânticas de busca (por contato vs. por thread) num único método — mais confuso do que os dois caminhos separados.

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=EmailAtendimentoServiceSincronizarTest`
Expected: PASS (5 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/EmailAtendimentoService.php tests/Feature/EmailAtendimentoServiceSincronizarTest.php
git commit -m "feat(email-adriana): EmailAtendimentoService::sincronizar — cria ticket/mensagem por thread"
```

---

### Task 6: `EmailAtendimentoService::responder()`

**Files:**
- Modify: `app/Services/EmailAtendimentoService.php`
- Test: `tests/Feature/EmailAtendimentoServiceResponderTest.php`

**Interfaces:**
- Consumes: `GoogleService::enviarRespostaEmail()` (Task 4)
- Produces: `responder(TicketAtendimento $ticket, string $texto): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\CanalEmail;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\EmailAtendimentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailAtendimentoServiceResponderTest extends TestCase
{
    use RefreshDatabase;

    private function ticketDeTeste(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $token   = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'adrianaaviag@gmail.com',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);
        $canal   = CanalEmail::create(['tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'status' => 'ativo']);
        $contato = Contato::create(['email' => 'cliente@exemplo.com', 'nome' => 'Cliente Teste', 'origem' => 'email']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'canal_tipo' => 'email', 'canal_email_id' => $canal->id,
            'email_thread_id' => 'thread-1', 'email_assunto' => 'Dúvida',
            'coluna_kanban' => 'novo',
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id, 'remetente' => 'lead',
            'tipo' => 'texto', 'conteudo' => 'Oi', 'provider_message_id' => '<original@mail.gmail.com>',
        ]);

        return $ticket;
    }

    public function test_responder_envia_com_thread_e_headers_da_ultima_mensagem_do_lead(): void
    {
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent'], 200)]);

        $resultado = app(EmailAtendimentoService::class)->responder($this->ticketDeTeste(), 'Claro, já te ajudo!');

        $this->assertTrue($resultado);
        Http::assertSent(function ($req) {
            $body = $req->data();
            $this->assertSame('thread-1', $body['threadId']);
            $raw = base64_decode(strtr($body['raw'], '-_', '+/'));
            $this->assertStringContainsString('To: cliente@exemplo.com', $raw);
            $this->assertStringContainsString('In-Reply-To: <original@mail.gmail.com>', $raw);
            return true;
        });
    }

    public function test_responder_com_falha_de_envio_retorna_false(): void
    {
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response([], 500)]);

        $resultado = app(EmailAtendimentoService::class)->responder($this->ticketDeTeste(), 'Texto');

        $this->assertFalse($resultado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=EmailAtendimentoServiceResponderTest`
Expected: FAIL — método `responder` não existe.

- [ ] **Step 3: Write the implementation**

Adicionar em `app/Services/EmailAtendimentoService.php`, dentro da classe:

```php
    public function responder(TicketAtendimento $ticket, string $texto): bool
    {
        $canal = $ticket->canalEmail;
        if (! $canal) {
            Log::warning("EmailAtendimentoService::responder chamado sem canal_email_id — ticket #{$ticket->id}");
            return false;
        }

        $ultimaDoLead = Mensagem::withoutGlobalScopes()
            ->where('ticket_id', $ticket->id)
            ->where('remetente', 'lead')
            ->latest('enviado_em')
            ->first();

        return $this->google->enviarRespostaEmail(
            $canal->googleToken,
            $ticket->email_thread_id,
            $ticket->contato->email,
            $ticket->email_assunto ?? '(sem assunto)',
            $texto,
            $ultimaDoLead?->provider_message_id
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=EmailAtendimentoServiceResponderTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/EmailAtendimentoService.php tests/Feature/EmailAtendimentoServiceResponderTest.php
git commit -m "feat(email-adriana): EmailAtendimentoService::responder"
```

---

### Task 7: Command `email:sincronizar` + agendamento

**Files:**
- Create: `app/Console/Commands/SincronizarEmail.php`
- Modify: `routes/console.php` (adicionar bloco de agendamento, depois do bloco `conversas:reassumir-agente`, linha ~41-45)
- Test: `tests/Feature/SincronizarEmailCommandTest.php`

**Interfaces:**
- Consumes: `EmailAtendimentoService::sincronizar()` (Task 5)
- Produces: comando artisan `email:sincronizar`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\CanalEmail;
use App\Models\GoogleToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarEmailCommandTest extends TestCase
{
    use RefreshDatabase;

    private function canalAtivo(?string $email = 'a@gmail.com'): CanalEmail
    {
        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => $email,
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);

        return CanalEmail::create(['tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'status' => 'ativo']);
    }

    public function test_comando_sincroniza_todos_os_canais_ativos(): void
    {
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/profile*' => Http::response(['historyId' => '1'], 200),
        ]);

        $canal1 = $this->canalAtivo('a@gmail.com');
        $canal2 = $this->canalAtivo('b@gmail.com');
        CanalEmail::create([
            'tenant_id' => \App\Models\Tenant::factory()->create()->id,
            'google_token_id' => GoogleToken::create([
                'tenant_id' => \App\Models\Tenant::factory()->create()->id, 'google_email' => 'c@gmail.com',
                'access_token' => 'x', 'refresh_token' => 'y', 'token_type' => 'Bearer',
                'expires_at' => now()->addHour(), 'scopes' => [],
            ])->id,
            'status' => 'inativo',
        ]);

        $this->artisan('email:sincronizar')->assertExitCode(0);

        $this->assertNotNull($canal1->fresh()->ultimo_poll_em);
        $this->assertNotNull($canal2->fresh()->ultimo_poll_em);
    }

    public function test_falha_num_canal_nao_impede_sincronismo_dos_outros(): void
    {
        $canalComErro = $this->canalAtivo('erro@gmail.com');
        $canalOk      = $this->canalAtivo('ok@gmail.com');

        // Falha de rede na primeira chamada (qualquer canal que rodar
        // primeiro), sucesso na segunda — o objetivo do teste é confirmar
        // que o comando não para no meio do loop, não qual canal
        // especificamente recebeu qual resposta.
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/profile*' => Http::sequence()
                ->push(['error' => 'timeout'], 500)
                ->push(['historyId' => '1'], 200),
        ]);

        $this->artisan('email:sincronizar')->assertExitCode(0);

        // Pelo menos um dos dois canais terminou o poll com sucesso — o que
        // importa é que o loop não abortou inteiro por causa da falha do
        // outro.
        $this->assertTrue($canalComErro->fresh()->ultimo_poll_em !== null || $canalOk->fresh()->ultimo_poll_em !== null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=SincronizarEmailCommandTest`
Expected: FAIL — comando `email:sincronizar` não existe.

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Console\Commands;

use App\Models\CanalEmail;
use App\Services\EmailAtendimentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SincronizarEmail extends Command
{
    protected $signature = 'email:sincronizar';

    protected $description = 'Sincroniza e-mail novo de todos os canais de e-mail ativos (Gmail History API)';

    public function handle(EmailAtendimentoService $service): int
    {
        $canais = CanalEmail::where('status', 'ativo')->get();

        $this->info("Sincronizando {$canais->count()} canal(is) de e-mail ativo(s)...");

        foreach ($canais as $canal) {
            try {
                $service->sincronizar($canal);
            } catch (\Exception $e) {
                Log::error("email:sincronizar falhou pro canal #{$canal->id}", ['erro' => $e->getMessage()]);
                $this->warn("Canal #{$canal->id} falhou: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=SincronizarEmailCommandTest`
Expected: PASS (2 testes). Se o teste de falha ficar instável por causa do `Http::sequence()` global (afeta os dois canais na mesma URL), simplifique a asserção pra só checar que o comando não lança exceção e termina com exit 0 — o objetivo do teste é robustez do loop, não qual canal especificamente falhou.

- [ ] **Step 5: Add scheduling**

Em `routes/console.php`, depois do bloco `Schedule::command('conversas:reassumir-agente')->everyFiveMinutes();` (linha ~40-44), adicionar:

```php
// E-mail da Adriana (e futuros canais de e-mail) — Gmail não empurra evento
// sem infra de Pub/Sub verificada, então polling de 5 em 5 minutos via
// History API (delta, não relista tudo). Ver docs/superpowers/specs/2026-08-21-leitura-email-adriana-design.md.
Schedule::command('email:sincronizar')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SincronizarEmail.php routes/console.php tests/Feature/SincronizarEmailCommandTest.php
git commit -m "feat(email-adriana): command email:sincronizar agendado a cada 5 min"
```

---

### Task 8: `KanbanController::enviarMensagem` — branch por canal

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php:244-295` (método `enviarMensagem`)
- Test: `tests/Feature/KanbanEnviarMensagemCanalEmailTest.php`

**Interfaces:**
- Consumes: `EmailAtendimentoService::responder()` (Task 6)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\CanalEmail;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KanbanEnviarMensagemCanalEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_de_canal_email_manda_resposta_pela_gmail_api(): void
    {
        $tenant  = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        $token   = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'adrianaaviag@gmail.com',
            'access_token' => 'token', 'refresh_token' => 'refresh', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);
        $canal   = CanalEmail::create(['tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'status' => 'ativo']);
        $contato = Contato::create(['email' => 'cliente@exemplo.com', 'origem' => 'email']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'canal_tipo' => 'email', 'canal_email_id' => $canal->id,
            'email_thread_id' => 'thread-9', 'email_assunto' => 'Assunto',
            'coluna_kanban' => 'novo',
        ]);

        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 's1'], 200)]);

        $resposta = $this->actingAs($usuario)
            ->postJson("/painel/kanban/{$ticket->id}/enviar-mensagem", ['conteudo' => 'Olá, tudo bem?']);

        $resposta->assertStatus(201)->assertJson(['enviado' => true]);
        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id, 'remetente' => 'humano', 'conteudo' => 'Olá, tudo bem?',
        ]);
    }

    public function test_ticket_de_canal_whatsapp_continua_no_caminho_de_sempre(): void
    {
        $tenant  = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        $canal   = \App\Models\WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);
        $contato = Contato::create(['telefone' => '5521999998888', 'origem' => 'whatsapp']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'canal_tipo' => 'whatsapp', 'coluna_kanban' => 'novo',
        ]);

        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $resposta = $this->actingAs($usuario)
            ->postJson("/painel/kanban/{$ticket->id}/enviar-mensagem", ['conteudo' => 'Oi']);

        $resposta->assertStatus(201);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'gmail.googleapis.com'));
    }
}
```

> Ajustar a URL do endpoint e os factories (`WhatsappCanal::factory()`, formato de telefone) conforme o que já existe em outros testes de `KanbanController` no repositório (ex.: `KanbanEnviarMensagemTraducaoTest.php`) — usar exatamente o mesmo helper de setup que aquele arquivo já usa pra criar tenant/usuário/ticket de WhatsApp, em vez de reconstruir do zero, se esses helpers existirem.

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=KanbanEnviarMensagemCanalEmailTest`
Expected: FAIL no primeiro teste — hoje `enviarMensagem` sempre tenta canal WhatsApp, retorna 502 ("Nenhum canal de WhatsApp vinculado") pro ticket de e-mail.

- [ ] **Step 3: Modify `enviarMensagem()`**

Em `app/Http/Controllers/Painel/KanbanController.php`, o método `enviarMensagem` (linhas 244-295) — trocar o trecho que resolve canal e envia (linhas 254-282) por um branch. Adicionar `use App\Services\EmailAtendimentoService;` no topo do arquivo, e mudar a assinatura do construtor/injeção conforme o padrão já usado nesse controller (se `TraducaoService` já é resolvido via `app(...)` inline em vez de injeção no construtor, seguir o mesmo estilo pra consistência).

```php
    public function enviarMensagem(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['conteudo' => 'required|string|min:1']);

        $model = TicketAtendimento::with(['contato', 'tenant', 'canal', 'canalEmail'])->findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        // Item 11 do roteiro (2026-08-20): traduz o texto do atendente pro
        // idioma do lead antes de enviar — vale pros dois canais.
        $textoParaEnviar = $request->conteudo;
        $idiomaEnviado    = 'pt';
        $conteudoPt       = null;

        if ($model->idioma_lead && $model->idioma_lead !== 'pt') {
            $traduzido = app(\App\Services\TraducaoService::class)->traduzir($request->conteudo, $model->idioma_lead);
            if ($traduzido) {
                $textoParaEnviar = $traduzido;
                $idiomaEnviado    = $model->idioma_lead;
                $conteudoPt       = $request->conteudo;
            }
        }

        if ($model->canal_tipo === 'email') {
            $enviado = app(EmailAtendimentoService::class)->responder($model, $textoParaEnviar);

            if (! $enviado) {
                return response()->json(['message' => 'Falha ao enviar o e-mail.'], 502);
            }
        } else {
            $telefone = $model->contato->telefone;
            $canal    = $model->canal;

            if (! $canal) {
                return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
            }

            $enviado = $canal->servico()->enviarTextoDireto($canal, $telefone, $textoParaEnviar);

            if (! $enviado) {
                return response()->json(['message' => 'Falha ao enviar pelo WhatsApp.'], 502);
            }
        }

        $mensagem = Mensagem::create([
            'ticket_id'   => $ticket,
            'tenant_id'   => $model->tenant_id,
            'remetente'   => 'humano',
            'tipo'        => 'texto',
            'conteudo'    => $textoParaEnviar,
            'idioma'      => $idiomaEnviado,
            'conteudo_pt' => $conteudoPt,
        ]);

        return response()->json(['mensagem_id' => $mensagem->id, 'enviado' => true], 201);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php.bat artisan test --filter=KanbanEnviarMensagemCanalEmailTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Run the full suite to check for regressions**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest` conhecido) — em especial confirmar que `KanbanEnviarMensagemTraducaoTest.php` (existente) continua passando, já que o método foi reescrito.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanEnviarMensagemCanalEmailTest.php
git commit -m "feat(email-adriana): KanbanController::enviarMensagem responde por Gmail quando canal_tipo=email"
```

---

### Task 9: Ativação do canal (painel)

**Files:**
- Modify: `app/Http/Controllers/Painel/IntegracoesController.php`
- Modify: `routes/web.php:98-100` (depois da rota `google.desconectar`)
- Modify: `resources/views/integracoes/index.blade.php` (adicionar bloco de toggle)
- Test: `tests/Feature/IntegracoesEmailAtivacaoTest.php`

**Interfaces:**
- Produces: `IntegracoesController::emailAtivar()`, `IntegracoesController::emailDesativar()`; rotas `email.ativar`/`email.desativar`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\CanalEmail;
use App\Models\GoogleToken;
use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegracoesEmailAtivacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ativar_email_cria_canal_email_usando_google_token_existente(): void
    {
        $tenant  = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        Kanban::create(['tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas', 'ordem' => 0]);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@gmail.com',
            'access_token' => 'x', 'refresh_token' => 'y', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['https://mail.google.com/'],
        ]);

        $resposta = $this->actingAs($usuario)->post('/painel/email/ativar');

        $resposta->assertRedirect();
        $this->assertDatabaseHas('canais_email', ['tenant_id' => $tenant->id, 'status' => 'ativo']);
    }

    public function test_ativar_email_sem_google_conectado_retorna_erro(): void
    {
        $tenant  = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $resposta = $this->actingAs($usuario)->post('/painel/email/ativar');

        $resposta->assertRedirect()->assertSessionHas('erro');
        $this->assertDatabaseCount('canais_email', 0);
    }

    public function test_desativar_email_marca_canal_como_inativo(): void
    {
        $tenant  = Tenant::factory()->create();
        $usuario = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        $token   = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@gmail.com',
            'access_token' => 'x', 'refresh_token' => 'y', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => [],
        ]);
        $canal = CanalEmail::create(['tenant_id' => $tenant->id, 'google_token_id' => $token->id, 'status' => 'ativo']);

        $this->actingAs($usuario)->post('/painel/email/desativar')->assertRedirect();

        $this->assertSame('inativo', $canal->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat artisan test --filter=IntegracoesEmailAtivacaoTest`
Expected: FAIL — rotas não existem (404).

- [ ] **Step 3: Add controller methods**

Em `app/Http/Controllers/Painel/IntegracoesController.php`, adicionar `use App\Models\CanalEmail;` e `use App\Models\Kanban;` no topo, e os dois métodos no final da classe, antes do `}` de fechamento:

```php
    public function emailAtivar(Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;
        $token    = GoogleToken::where('tenant_id', $tenantId)->first();

        if (! $token) {
            return redirect()->route('integracoes')
                ->with('erro', 'Conecte o Google primeiro antes de ativar a leitura de e-mail.');
        }

        $kanbanId = Kanban::where('tenant_id', $tenantId)->value('id');

        CanalEmail::updateOrCreate(
            ['tenant_id' => $tenantId, 'google_token_id' => $token->id],
            ['kanban_id' => $kanbanId, 'status' => 'ativo']
        );

        return redirect()->route('integracoes')
            ->with('sucesso', 'Leitura de e-mail ativada — e-mails novos vão aparecer no Kanban em até 5 minutos.');
    }

    public function emailDesativar(Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        CanalEmail::where('tenant_id', $tenantId)->update(['status' => 'inativo']);

        return redirect()->route('integracoes')->with('sucesso', 'Leitura de e-mail desativada.');
    }
```

- [ ] **Step 4: Add routes**

Em `routes/web.php`, depois da rota `google.desconectar` (linha 100):

```php
    Route::post('/email/ativar', [IntegracoesController::class, 'emailAtivar'])
        ->name('email.ativar')
        ->middleware('role:admin,dono,growth_manager');
    Route::post('/email/desativar', [IntegracoesController::class, 'emailDesativar'])
        ->name('email.desativar')
        ->middleware('role:admin,dono,growth_manager');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php.bat artisan test --filter=IntegracoesEmailAtivacaoTest`
Expected: PASS (3 testes)

- [ ] **Step 6: Add UI toggle**

Abrir `resources/views/integracoes/index.blade.php`, localizar o bloco que mostra o status do Google conectado (`$google_conectado`), e adicionar logo abaixo (só quando `$google_conectado` for `true`):

```blade
@if($google_conectado)
    <div class="mt-4 pt-4 border-t border-gray-100">
        <p class="text-sm font-medium text-gray-700">Leitura de e-mail no Kanban</p>
        <p class="text-xs text-gray-500 mt-1">E-mails recebidos em {{ $google_email }} viram ticket automaticamente, na mesma fila do WhatsApp.</p>
        @if($email_ativo ?? false)
            <form action="{{ route('email.desativar') }}" method="POST" class="mt-2">
                @csrf
                <button class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg">Desativar leitura de e-mail</button>
            </form>
        @else
            <form action="{{ route('email.ativar') }}" method="POST" class="mt-2">
                @csrf
                <button class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg">Ativar leitura de e-mail</button>
            </form>
        @endif
    </div>
@endif
```

E em `IntegracoesController::view()` (linha 19-30), adicionar `email_ativo` ao array retornado:

```php
        return view('integracoes.index', [
            'google_conectado' => (bool) $token,
            'google_email'     => $token?->google_email,
            'google_expira'    => $token?->expires_at?->format('d/m/Y H:i'),
            'google_scopes'    => $token?->scopes ?? [],
            'email_ativo'      => $token ? CanalEmail::where('tenant_id', $tenantId)->where('status', 'ativo')->exists() : false,
        ]);
```

- [ ] **Step 7: Run the full suite**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest` conhecido)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Painel/IntegracoesController.php routes/web.php \
        resources/views/integracoes/index.blade.php tests/Feature/IntegracoesEmailAtivacaoTest.php
git commit -m "feat(email-adriana): toggle de ativação em Configurações > Integrações"
```

---

### Task 10: Selo de canal na tela do Kanban

**Files:**
- Modify: `resources/views/kanban/index.blade.php:171-176`
- Test: `tests/Feature/KanbanBladeCompileCheckTest.php` (já existe — só precisa continuar passando, é o smoke test de compilação Blade já usado nesta sessão)

**Interfaces:**
- Nenhuma nova — consome `ticketAtivo.canal_tipo`, que já existe automaticamente no JSON do ticket assim que `canal_tipo` entrou no `$fillable` (Task 1).

- [ ] **Step 1: Add the badge**

Em `resources/views/kanban/index.blade.php`, logo depois do bloco do selo de idioma (linhas 171-176), adicionar:

```blade
                            {{-- Canal do ticket — só aparece quando é e-mail, WhatsApp continua
                                 sendo o padrão silencioso (mesma ideia do selo de idioma acima) --}}
                            <span x-show="ticketAtivo.canal_tipo === 'email'"
                                  class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-normal"
                                  title="Este atendimento chegou por e-mail, não WhatsApp">✉️ E-mail</span>
```

- [ ] **Step 2: Run the Blade compile smoke test**

Run: `php.bat artisan test --filter=KanbanBladeCompileCheckTest`
Expected: PASS — confirma que o Blade continua compilando sem erro de sintaxe.

- [ ] **Step 3: Run the full suite one last time**

Run: `php.bat artisan test`
Expected: PASS (exceto `ExampleTest` conhecido)

- [ ] **Step 4: Commit**

```bash
git add resources/views/kanban/index.blade.php
git commit -m "feat(email-adriana): selo de canal e-mail no card do Kanban"
```

---

## Depois de todas as tasks

- [ ] Rodar `php.bat artisan test` completo uma última vez (suíte inteira).
- [ ] Deploy: a partir de `leadcerto-app/`, `bash ./deploy.sh` (push + trava de VPS limpa + pull + migrate + cache).
- [ ] Verificar `php artisan migrate:status` na VPS pós-deploy (confirmar as 3 migrations novas rodaram).
- [ ] **Ação humana, fora do código:** pedir pra Adriana reconectar em `/google/autorizar` (token atual revogado, ver Contexto da spec) — sem isso, `email:sincronizar` roda mas não sincroniza nada de verdade pro tenant dela.
- [ ] Depois que ela reconectar, ativar o canal em Configurações → Integrações (botão da Task 9) e confirmar com um e-mail de teste real que ele aparece no Kanban dentro de 5 minutos.
- [ ] Atualizar `TAREFAS.md` (seção "🔖 PONTO DE RETOMADA — fim de sessão 2026-08-20 (parte 2)") marcando o item 3 do plano aprovado como concluído.
