# Guardrails de Resposta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao agente de IA a capacidade de pausar e pedir orientação humana quando está fora do seu escopo/base de conhecimento (Regra 2), com autovalidação antes de responder (Regra 7), sem nunca repetir o lead (Regra 6), sem re-perguntar o que já foi respondido (Regra 5), e sem repetir a mensagem de espera se o lead insistir durante a pausa (Regra 9).

**Architecture:** `SdrResponderService::responder()` ganha detecção do token `[DUVIDA:...]` (emitido pelo próprio modelo quando a autovalidação do prompt falha) — ao detectar, pausa o ticket e cria um alerta (Bloco 1) em vez de responder. Um endpoint novo dedicado (`KanbanController::orientar()`) recebe a orientação do humano, limpa o estado de pausa, e redispara o agente via `SdrResponderJob` (mesmo padrão já usado por `liberarEAcionarIA()`) — com a orientação injetada no prompt só daquela chamada. `SdrResponderJob` ganha o guard da Regra 9 (mensagem única de espera).

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (produção) / SQLite (testes), Alpine.js v3, Tailwind CSS.

## Global Constraints

- O humano nunca responde pelo chat normal do card pra orientar uma dúvida — isso assumiria a conversa inteira (`KanbanController::enviarMensagem()` → `assumirAutomaticamente()`). A orientação usa um endpoint e painel dedicados; o agente continua no controle (`agente_responsavel` nunca muda pra `'humano'` neste fluxo).
- `AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno` já existe (Bloco 1) — usar exatamente essa assinatura.
- Regra 7 é autovalidação **numa chamada de IA só** (decisão já fechada, YAGNI) — a instrução vai no mesmo prompt, não há segunda chamada de validação.
- `SdrResponderService::responder()` é chamado de 4 lugares (`SdrResponderJob`, `FollowupConversas` 2x, `Internal\TicketController`) — qualquer guard novo dentro de `responder()` protege os 4 automaticamente; qualquer lógica que deva rodar só quando o *lead* escreve (não o `FollowupConversas`) vai em `SdrResponderJob`, não em `responder()`.
- Especificação completa: `docs/superpowers/specs/2026-08-07-guardrails-resposta-design.md`.

---

### Task 1: Schema — campos novos e hook de reset

**Files:**
- Create: `database/migrations/2026_08_07_000001_add_orientacao_to_tickets_atendimento.php`
- Create: `database/migrations/2026_08_07_000002_add_resposta_to_alertas_internos.php`
- Create: `database/migrations/2026_08_07_000003_add_aguardando_orientacao_mensagem_to_kanban_coluna_configs.php`
- Modify: `app/Models/TicketAtendimento.php`
- Modify: `app/Models/AlertaInterno.php`
- Modify: `app/Models/KanbanColunaConfig.php`
- Test: `tests/Feature/TicketAtendimentoOrientacaoResetTest.php`

**Interfaces:**
- Produces: `TicketAtendimento::$aguardando_orientacao_em` (datetime|null), `::$mensagem_espera_enviada` (bool); `AlertaInterno::$resposta` (string|null), `::$respondido_em` (datetime|null); `KanbanColunaConfig::$aguardando_orientacao_mensagem` (string|null) — usados pelas Tasks 2-7.

- [ ] **Step 1: Migration de `tickets_atendimento`**

```php
<?php
// database/migrations/2026_08_07_000001_add_orientacao_to_tickets_atendimento.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Regra 2 (dúvida do agente) — não-nulo = agente pausado esperando
            // orientação humana. Ver docs/superpowers/specs/2026-08-07-guardrails-resposta-design.md.
            $table->timestamp('aguardando_orientacao_em')->nullable()->after('objetivos_cumpridos');
            // Regra 9 — evita repetir a mensagem de espera a cada mensagem nova
            // do lead durante a mesma pausa.
            $table->boolean('mensagem_espera_enviada')->default(false)->after('aguardando_orientacao_em');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn(['aguardando_orientacao_em', 'mensagem_espera_enviada']);
        });
    }
};
```

- [ ] **Step 2: Migration de `alertas_internos`**

```php
<?php
// database/migrations/2026_08_07_000002_add_resposta_to_alertas_internos.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alertas_internos', function (Blueprint $table) {
            // Só usados por alertas tipo 'duvida_ia' (Regra 2) — os demais tipos
            // (já existentes desde o Bloco 1/2) nunca preenchem esses campos.
            $table->text('resposta')->nullable()->after('conteudo');
            $table->timestamp('respondido_em')->nullable()->after('resposta');
        });
    }

    public function down(): void
    {
        Schema::table('alertas_internos', function (Blueprint $table) {
            $table->dropColumn(['resposta', 'respondido_em']);
        });
    }
};
```

- [ ] **Step 3: Migration de `kanban_coluna_configs`**

```php
<?php
// database/migrations/2026_08_07_000003_add_aguardando_orientacao_mensagem_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Regra 9 — mensagem padrão configurável por coluna, mandada uma
            // única vez se o lead insistir enquanto o agente aguarda orientação.
            $table->text('aguardando_orientacao_mensagem')->nullable()->after('timeout_reassuncao_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn('aguardando_orientacao_mensagem');
        });
    }
};
```

- [ ] **Step 4: Atualizar `TicketAtendimento`**

Em `app/Models/TicketAtendimento.php`, no `$fillable`, adicione logo depois de `'objetivos_cumpridos',`:

```php
        'aguardando_orientacao_em',
        'mensagem_espera_enviada',
```

No método `casts()`, adicione ao array retornado:

```php
            'aguardando_orientacao_em' => 'datetime',
            'mensagem_espera_enviada'  => 'boolean',
```

No hook `static::updating()` já existente (o que zera `objetivos_cumpridos` quando `coluna_kanban` muda), estenda pra também limpar o estado de orientação — troque:

```php
        static::updating(function (TicketAtendimento $ticket) {
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('objetivos_cumpridos')) {
                $ticket->objetivos_cumpridos = [];
            }
        });
```

por:

```php
        static::updating(function (TicketAtendimento $ticket) {
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('objetivos_cumpridos')) {
                $ticket->objetivos_cumpridos = [];
            }
            // Regra 2 (Bloco 3): uma dúvida pausada é específica do contexto da
            // coluna atual — se o ticket muda de coluna enquanto aguarda
            // orientação (manual ou automático), a pausa não faz mais sentido.
            // Mesmo raciocínio do reset de objetivos_cumpridos acima.
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('aguardando_orientacao_em')) {
                $ticket->aguardando_orientacao_em = null;
                $ticket->mensagem_espera_enviada  = false;
            }
        });
```

- [ ] **Step 5: Atualizar `AlertaInterno`**

Em `app/Models/AlertaInterno.php`, no `$fillable`, adicione logo depois de `'conteudo',`:

```php
        'resposta',
        'respondido_em',
```

No `$casts`, adicione:

```php
        'respondido_em' => 'datetime',
```

- [ ] **Step 6: Atualizar `KanbanColunaConfig`**

Em `app/Models/KanbanColunaConfig.php`, no `$fillable`, adicione logo depois de `'timeout_reassuncao_segundos',`:

```php
        'aguardando_orientacao_mensagem',
```

- [ ] **Step 7: Escrever o teste do hook de reset**

```php
<?php
// tests/Feature/TicketAtendimentoOrientacaoResetTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoOrientacaoResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_campos_de_orientacao_sao_mass_assignable(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
        $this->assertTrue($ticket->fresh()->mensagem_espera_enviada);
    }

    public function test_mudar_de_coluna_zera_o_estado_de_orientacao(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $ticket->update(['coluna_kanban' => 'aguardando_orcamento']);

        $ticketFresco = $ticket->fresh();
        $this->assertNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);
    }

    public function test_atualizar_outro_campo_sem_mudar_coluna_nao_zera_orientacao(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);

        $ticket->update(['resumo_ia' => 'nota qualquer']);

        $ticketFresco = $ticket->fresh();
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
        $this->assertTrue($ticketFresco->mensagem_espera_enviada);
    }
}
```

- [ ] **Step 8: Rodar as migrations e o teste**

Run: `php artisan test --filter=TicketAtendimentoOrientacaoResetTest`
Expected: PASS (3 testes) — `RefreshDatabase` roda as 3 migrations novas automaticamente.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_07_000001_add_orientacao_to_tickets_atendimento.php \
        database/migrations/2026_08_07_000002_add_resposta_to_alertas_internos.php \
        database/migrations/2026_08_07_000003_add_aguardando_orientacao_mensagem_to_kanban_coluna_configs.php \
        app/Models/TicketAtendimento.php app/Models/AlertaInterno.php app/Models/KanbanColunaConfig.php \
        tests/Feature/TicketAtendimentoOrientacaoResetTest.php
git commit -m "feat: schema da pausa de orientação do agente (Regra 2/9)"
```

---

### Task 2: Prompt — Regras 5, 6 e 7 (reforço, sem mudança de lógica)

**Files:**
- Modify: `app/Services/SdrResponderService.php:247-356` (`montarHistorico()`)
- Test: `tests/Feature/SdrResponderServicePromptGuardrailsTest.php`

**Interfaces:**
- Consumes: nenhuma interface nova — só adiciona texto ao `content` do system message já produzido por `montarHistorico()`.
- Produces: nenhuma interface nova.

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/SdrResponderServicePromptGuardrailsTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServicePromptGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComPersona(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    private function capturarPrompt(TicketAtendimento $ticket): string
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        return $mensagensCapturadas[0]['content'];
    }

    public function test_prompt_contem_instrucao_anti_eco(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('Nunca repita literalmente', $prompt);
    }

    public function test_prompt_contem_instrucao_de_nao_re_perguntar(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('já foi dito', $prompt);
    }

    public function test_prompt_contem_instrucao_de_autovalidacao_com_token_duvida(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('[DUVIDA:', $prompt);
    }

    public function test_objetivo_cumprido_aparece_marcado_junto_com_a_instrucao_de_nao_repetir(): void
    {
        // Regra 5 na prática: o bloco de objetivos (já existente) e a instrução
        // nova de "não repita perguntas" precisam aparecer juntos no mesmo
        // prompt, pra IA conseguir ligar um ao outro.
        $ticket = $this->criarTicketComPersona();
        $objetivo = \App\Models\KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => $ticket->coluna_kanban,
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$objetivo->id]]);

        $prompt = $this->capturarPrompt($ticket);

        $this->assertStringContainsString('✅ [id:' . $objetivo->id . '] Endereço de origem confirmado', $prompt);
        $this->assertStringContainsString('já foi dito', $prompt);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=SdrResponderServicePromptGuardrailsTest`
Expected: FAIL nos 4 — as instruções ainda não existem no prompt.

- [ ] **Step 3: Adicionar as instruções em `montarHistorico()`**

Em `app/Services/SdrResponderService.php`, dentro de `montarHistorico()`, logo depois do bloco que fecha `=== SOBRE O HISTÓRICO DA CONVERSA ===` (linhas ~324-329) e antes de `$contextoHistorico = $this->contextoHistoricoCliente($ticket);` (linha ~331), adicione:

```php
        // Regra 6 — proibição de eco.
        $iaContexto .= "\n\n=== REGRA DE ESTILO ===\n"
            . "Nunca repita literalmente o que o lead acabou de escrever como se fosse sua "
            . "própria fala. Reformular com valor agregado (confirmar entendimento, avançar "
            . "a conversa) é permitido — cópia pura da mensagem do lead é proibida."
            . "\n===";

        // Regra 5 — não perguntar de novo o que já foi respondido.
        $iaContexto .= "\n\n=== NÃO REPITA PERGUNTAS ===\n"
            . "Antes de pedir qualquer informação ao lead, releia todo o histórico da conversa "
            . "(incluindo transcrições de áudio/imagem) — se já foi dito, use o que já foi "
            . "informado em vez de perguntar de novo. Os itens marcados ✅ no bloco de "
            . "objetivos abaixo (se houver) já foram confirmados — nunca pergunte de novo "
            . "sobre eles."
            . "\n===";

        // Regra 7 — autovalidação antes de responder (1 chamada só, sem chamada
        // dupla — decisão fechada). Regra 2 é o efeito prático desta validação:
        // é este bloco que ensina o modelo a emitir [DUVIDA:...] quando não tem
        // certeza — a detecção do token acontece em responder(), não aqui.
        $iaContexto .= "\n\n=== VALIDAÇÃO ANTES DE RESPONDER ===\n"
            . "Antes de finalizar sua resposta, confira: (1) ela é relevante pro que o lead "
            . "perguntou agora? (2) está dentro do escopo deste atendimento? (3) não "
            . "contradiz nada dito antes nesta conversa? Se qualquer resposta for não, ou se "
            . "você não tiver certeza ou informação suficiente pra responder com segurança, "
            . "NÃO responda normalmente — em vez disso, responda SOMENTE com o token "
            . "[DUVIDA: <resuma em uma frase o que você não sabe responder>]. NUNCA invente "
            . "informação que não está no seu contexto."
            . "\n===";
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SdrResponderServicePromptGuardrailsTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Rodar toda a suíte de `SdrResponderService` pra checar regressão**

Run: `php artisan test --filter=SdrResponderService`
Expected: PASS em tudo — as instruções novas não mudam o `resposta` retornado por nenhum teste existente (todos mockam `OpenRouterService::chat()`, então o prompt maior não afeta o valor de retorno mockado).

- [ ] **Step 6: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServicePromptGuardrailsTest.php
git commit -m "feat: reforço de prompt pras Regras 5, 6 e 7 (anti-eco, anti-repergunta, autovalidação)"
```

---

### Task 3: Regra 2 — detecção de dúvida, pausa e alerta

**Files:**
- Modify: `app/Services/SdrResponderService.php` (`responder()`, `montarHistorico()`)
- Test: `tests/Feature/SdrResponderServiceDuvidaTest.php`

**Interfaces:**
- Consumes: `AlertaInternoService::criar()` (Bloco 1, já existe), `TicketAtendimento::$aguardando_orientacao_em`/`$mensagem_espera_enviada` (Task 1).
- Produces: `SdrResponderService::responder(TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null, ?string $orientacaoHumana = null): ?string` — o novo 4º parâmetro `$orientacaoHumana` é o que a Task 5 vai usar (via `SdrResponderJob`, ver Task 4). Assinatura antiga continua funcionando (parâmetro novo é opcional, no final).

- [ ] **Step 1: Escrever os testes**

```php
<?php
// tests/Feature/SdrResponderServiceDuvidaTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceDuvidaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComCanal(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_token_duvida_pausa_o_ticket_sem_enviar_mensagem_e_cria_alerta(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()
                ->andReturn('[DUVIDA: O lead perguntou o preço de um serviço que não está na tabela.]');
        });

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id, 'remetente' => 'bot']);

        $ticketFresco = $ticket->fresh();
        $this->assertNotNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id, 'tipo' => 'duvida_ia',
            'conteudo'  => 'O lead perguntou o preço de um serviço que não está na tabela.',
        ]);
    }

    public function test_ticket_aguardando_orientacao_suprime_qualquer_resposta_do_agente(): void
    {
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['aguardando_orientacao_em' => now()]);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
    }

    public function test_orientacao_humana_e_injetada_no_prompt_e_gera_resposta_real_ao_lead(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();
        $ticket->update(['aguardando_orientacao_em' => null]); // já foi limpo antes do redisparo (Task 5)

        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('O preço desse serviço é R$ 250.');
        });

        $resposta = app(SdrResponderService::class)->responder(
            $ticket, orientacaoHumana: 'O preço desse serviço específico é R$ 250, pode confirmar.'
        );

        $this->assertSame('O preço desse serviço é R$ 250.', $resposta);
        $this->assertStringContainsString('O preço desse serviço específico é R$ 250', $mensagensCapturadas[0]['content']);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'O preço desse serviço é R$ 250.']);
    }

    public function test_falha_ao_criar_alerta_nao_impede_a_pausa(): void
    {
        $ticket = $this->criarTicketComCanal();

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[DUVIDA: teste]');
        });
        $this->mock(\App\Services\AlertaInternoService::class, function ($mock) {
            $mock->shouldReceive('criar')->once()->andThrow(new \Exception('falha simulada'));
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=SdrResponderServiceDuvidaTest`
Expected: FAIL em todos — nem o guard nem a detecção de token existem ainda, e `responder()` não aceita `orientacaoHumana`.

- [ ] **Step 3: Adicionar o guard no topo de `responder()`**

Em `app/Services/SdrResponderService.php`, troque a assinatura do método (linha 25):

```php
    public function responder(TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null): ?string
    {
        $ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant', 'canal']);
```

por:

```php
    public function responder(TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null, ?string $orientacaoHumana = null): ?string
    {
        $ticket->loadMissing(['contato', 'persona', 'mensagens', 'tenant', 'canal']);

        // Regra 2/4: enquanto o ticket aguarda orientação humana sobre uma
        // dúvida, o agente não gera nenhuma resposta — nem pra este chamador
        // nem pros outros 3 (FollowupConversas x2, Internal\TicketController).
        // Quem redispara com a orientação (Task 5, via SdrResponderJob) já
        // limpa aguardando_orientacao_em ANTES de chamar responder() de novo,
        // então esse guard nunca bloqueia o redisparo legítimo.
        if ($ticket->aguardando_orientacao_em) {
            Log::info('SdrResponder: ticket aguardando orientação humana, resposta suprimida', ['ticket_id' => $ticket->id]);
            return null;
        }
```

- [ ] **Step 4: Passar `$orientacaoHumana` pra `montarHistorico()`**

Na mesma classe, troque a linha (~44):

```php
        $messages = $this->montarHistorico($persona, $ticket, $origemLigacao, $gatilho);
```

por:

```php
        $messages = $this->montarHistorico($persona, $ticket, $origemLigacao, $gatilho, $orientacaoHumana);
```

- [ ] **Step 5: Detectar o token `[DUVIDA:...]` logo após a chamada ao OpenRouter**

Na mesma classe, logo depois do bloco:

```php
        if (! $resposta) {
            Log::error('SdrResponder: OpenRouter sem resposta', ['ticket_id' => $ticket->id]);
            return null;
        }
```

e ANTES do comentário `// ── 4. Detectar token de movimento de coluna e aplicar ──────────────`, adicione:

```php
        // ── 3.5. Detectar dúvida (Regra 2) ───────────────────────────────────
        // Se o agente decidiu pausar (instrução de autovalidação da Regra 7,
        // ver montarHistorico()), a resposta inteira é só esse token — nenhum
        // outro processamento (movimento de coluna, objetivos, envio) roda.
        if (preg_match('/\[DUVIDA:\s*(.+?)\]/s', $resposta, $matchDuvida)) {
            $resumo = trim($matchDuvida[1]);

            $ticket->update([
                'aguardando_orientacao_em' => now(),
                'mensagem_espera_enviada'  => false,
            ]);

            try {
                app(\App\Services\AlertaInternoService::class)->criar(
                    $ticket->tenant_id,
                    'duvida_ia',
                    'Agente pediu orientação',
                    $resumo,
                    $ticket->id,
                );
            } catch (\Exception $e) {
                Log::warning('SdrResponder: falha ao criar alerta de dúvida', [
                    'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                ]);
            }

            Log::info('SdrResponder: pausado aguardando orientação', ['ticket_id' => $ticket->id, 'resumo' => $resumo]);

            return null;
        }
```

- [ ] **Step 6: Injetar a orientação humana em `montarHistorico()`**

Na mesma classe, troque a assinatura do método (~linha 247):

```php
    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null): array
```

por:

```php
    private function montarHistorico(SdrPersona $persona, TicketAtendimento $ticket, bool $origemLigacao = false, ?string $gatilho = null, ?string $orientacaoHumana = null): array
```

Logo depois do bloco `$contextoGatilho = match ($gatilho) { ... };` (linhas ~335-341), adicione:

```php
        $contextoOrientacao = $orientacaoHumana
            ? "[ORIENTAÇÃO DO ATENDENTE — use isso pra responder ao lead agora, "
              . "NUNCA mencione que recebeu orientação interna]: {$orientacaoHumana}"
            : null;
```

E no array `array_filter([...])` que monta o `content` do system message (linhas ~345-355), adicione `$contextoOrientacao` como último item, logo depois de `$contextoGatilho`:

```php
            'content' => implode("\n\n", array_filter([
                $persona->system_prompt,
                $iaContexto,
                $etapaInstrucao,
                $contextoContato,
                $contextoHistorico,
                $checklistState,
                $primeiroContato,
                $contextoLigacao,
                $contextoGatilho,
                $contextoOrientacao,
            ])),
```

- [ ] **Step 7: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SdrResponderServiceDuvidaTest`
Expected: PASS (4 testes)

- [ ] **Step 8: Rodar toda a suíte de `SdrResponderService` pra checar regressão**

Run: `php artisan test --filter=SdrResponderService`
Expected: PASS em tudo — nenhum teste existente seta `aguardando_orientacao_em`, então o guard novo nunca dispara nos testes já existentes; nenhum mock de `OpenRouterService::chat()` já existente retorna um texto que bate com o padrão `[DUVIDA:...]`.

- [ ] **Step 9: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceDuvidaTest.php
git commit -m "feat: detecção de dúvida, pausa e alerta (Regra 2)"
```

---

### Task 4: Regra 9 — mensagem única de espera

**Files:**
- Modify: `app/Jobs/SdrResponderJob.php`
- Test: `tests/Feature/SdrResponderJobAguardandoOrientacaoTest.php`

**Interfaces:**
- Consumes: `TicketAtendimento::$aguardando_orientacao_em`/`$mensagem_espera_enviada` (Task 1), `KanbanColunaConfig::$aguardando_orientacao_mensagem` (Task 1), `SdrResponderService::responder(..., ?string $orientacaoHumana = null)` (Task 3).
- Produces: `SdrResponderJob` ganha um 6º parâmetro de construtor `?string $orientacaoHumana = null` (posicional, no final — não quebra nenhum dos 3 call sites existentes que já usam menos argumentos) — usado pela Task 5.

- [ ] **Step 1: Escrever os testes**

```php
<?php
// tests/Feature/SdrResponderJobAguardandoOrientacaoTest.php
namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderJobAguardandoOrientacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketAguardandoOrientacao(bool $mensagemJaEnviada = false): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
            'aguardando_orientacao_mensagem' => 'Estou verificando, já te retorno!',
        ]);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => $mensagemJaEnviada,
        ]);
    }

    public function test_lead_escreve_durante_pausa_recebe_mensagem_de_espera_uma_vez(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        (new SdrResponderJob($ticket->id, 'oi, ainda esperando', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $this->assertTrue($ticket->fresh()->mensagem_espera_enviada);
        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id, 'remetente' => 'bot', 'conteudo' => 'Estou verificando, já te retorno!',
        ]);
    }

    public function test_lead_insiste_de_novo_nao_repete_a_mensagem_de_espera(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: true);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        (new SdrResponderJob($ticket->id, 'e aí?', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $this->assertSame(0, Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->count());
    }

    public function test_sem_mensagem_configurada_usa_fallback_generico(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->update(['aguardando_orientacao_mensagem' => null]);

        (new SdrResponderJob($ticket->id, 'oi', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->first();
        $this->assertNotNull($mensagem);
        $this->assertNotEmpty($mensagem->conteudo);
    }

    public function test_orientacao_humana_passa_direto_pro_service_sem_o_guard_de_espera(): void
    {
        // Simula o redisparo da Task 5: quem chama já limpou aguardando_orientacao_em
        // ANTES de despachar o job — o job não precisa saber sobre orientação
        // pra decidir se bloqueia; só repassa o parâmetro pro service.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);
        $ticket->update(['aguardando_orientacao_em' => null, 'mensagem_espera_enviada' => false]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Resposta com base na orientação.');
        });

        (new SdrResponderJob($ticket->id, '', false, true, 0, 'preço é R$ 250'))
            ->handle(app(\App\Services\SdrResponderService::class));

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Resposta com base na orientação.']);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=SdrResponderJobAguardandoOrientacaoTest`
Expected: FAIL em todos — o guard e o parâmetro `orientacaoHumana` ainda não existem no job.

- [ ] **Step 3: Adicionar o parâmetro no construtor**

Em `app/Jobs/SdrResponderJob.php`, troque o construtor:

```php
    public function __construct(
        private int    $ticketId,
        private string $ultimaMensagem  = '',
        private bool   $origemLigacao   = false,
        private bool   $imediato        = false,
        private int    $debounceSegundos = self::DEBOUNCE_SEGUNDOS,
    ) {}
```

por:

```php
    public function __construct(
        private int     $ticketId,
        private string  $ultimaMensagem  = '',
        private bool    $origemLigacao   = false,
        private bool    $imediato        = false,
        private int     $debounceSegundos = self::DEBOUNCE_SEGUNDOS,
        private ?string $orientacaoHumana = null,
    ) {}
```

- [ ] **Step 4: Adicionar o guard da Regra 9 em `handle()`**

Na mesma classe, logo depois do bloco:

```php
        // Só responde se o bot ainda é responsável
        if ($ticket->agente_responsavel !== 'bot') {
            Log::info("SdrResponderJob: ticket #{$this->ticketId} já foi assumido por humano, ignorando");
            return;
        }
```

e ANTES do bloco que checa `$colunaConfig?->ia_ativo`, adicione:

```php
        // Regra 9: enquanto o ticket aguarda orientação humana sobre uma
        // dúvida, o agente não responde normalmente ao lead — manda a
        // mensagem de espera uma única vez (se ainda não mandou) e para por
        // aqui. Não se aplica quando $orientacaoHumana está preenchido: isso
        // só acontece no redisparo da Task 5, que já limpa
        // aguardando_orientacao_em ANTES de despachar este job.
        if ($ticket->aguardando_orientacao_em && ! $this->orientacaoHumana) {
            if (! $ticket->mensagem_espera_enviada) {
                $config = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $ticket->tenant_id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->first();

                $texto = $config?->aguardando_orientacao_mensagem
                    ?: 'Estou verificando mais detalhes sobre isso pra te dar a melhor resposta. Em breve retorno!';

                $telefone = $ticket->contato?->telefone;
                $canal    = $ticket->canal;

                if ($telefone && $canal) {
                    $enviado = $canal->servico()->enviarTexto($canal, $telefone, $texto);
                    if ($enviado) {
                        Mensagem::create([
                            'ticket_id'  => $ticket->id,
                            'tenant_id'  => $ticket->tenant_id,
                            'remetente'  => 'bot',
                            'tipo'       => 'texto',
                            'conteudo'   => $texto,
                            'enviado_em' => now(),
                        ]);
                        $ticket->update(['mensagem_espera_enviada' => true]);
                    } else {
                        Log::warning("SdrResponderJob: falha ao enviar mensagem de espera, ticket #{$this->ticketId}");
                    }
                }
            }

            Log::info("SdrResponderJob: ticket #{$this->ticketId} aguardando orientação, resposta normal suprimida");
            return;
        }
```

- [ ] **Step 5: Repassar `$orientacaoHumana` pro service**

Na mesma classe, troque a última linha do método:

```php
        $service->responder($ticket, $this->origemLigacao);
```

por:

```php
        $service->responder($ticket, $this->origemLigacao, orientacaoHumana: $this->orientacaoHumana);
```

- [ ] **Step 6: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=SdrResponderJobAguardandoOrientacaoTest`
Expected: PASS (4 testes)

- [ ] **Step 7: Rodar toda a suíte pra checar regressão**

Run: `php artisan test`
Expected: PASS em tudo, exceto a falha pré-existente e conhecida de `ExampleTest`.

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/SdrResponderJob.php tests/Feature/SdrResponderJobAguardandoOrientacaoTest.php
git commit -m "feat: mensagem única de espera durante pausa de orientação (Regra 9)"
```

---

### Task 5: Endpoint de orientação

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php`
- Modify: `routes/web.php:207-225` (adicionar a rota, dentro do grupo já existente de `/kanban/ticket/{ticket}/...`)
- Test: `tests/Feature/KanbanOrientarTest.php`

**Interfaces:**
- Consumes: `AlertaInterno` (Bloco 1), `SdrResponderJob` com o parâmetro `orientacaoHumana` (Task 4).
- Produces: `POST /api/painel/kanban/ticket/{ticket}/orientar` — usado pela Task 6 (UI).

- [ ] **Step 1: Escrever o teste**

```php
<?php
// tests/Feature/KanbanOrientarTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class KanbanOrientarTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketAguardandoOrientacao(Tenant $tenant): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true,
        ]);
    }

    public function test_orienta_limpa_estado_de_espera_e_redispara_o_agente(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Agente pediu orientação', 'conteudo' => 'Dúvida sobre preço',
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'O preço desse serviço é R$ 250.',
        ]);

        $response->assertOk();

        $ticketFresco = $ticket->fresh();
        $this->assertNull($ticketFresco->aguardando_orientacao_em);
        $this->assertFalse($ticketFresco->mensagem_espera_enviada);

        $alertaFresco = $alerta->fresh();
        $this->assertSame('O preço desse serviço é R$ 250.', $alertaFresco->resposta);
        $this->assertNotNull($alertaFresco->respondido_em);

        Bus::assertDispatched(\App\Jobs\SdrResponderJob::class);
    }

    public function test_orientar_ticket_que_nao_esta_aguardando_retorna_erro(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => 'Qualquer coisa',
        ]);

        $response->assertStatus(422);
    }

    public function test_orientacao_vazia_e_rejeitada(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $ticket = $this->criarTicketAguardandoOrientacao($tenant);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/orientar", [
            'orientacao' => '',
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=KanbanOrientarTest`
Expected: FAIL em todos — rota `/orientar` não existe (404).

- [ ] **Step 3: Criar o método no controller**

Em `app/Http/Controllers/Painel/KanbanController.php`, logo depois do método `liberarEAcionarIA()` (ver linhas 294-306), adicione:

```php
    /**
     * Regra 2 (Bloco 3): o humano orienta uma dúvida do agente por aqui — não
     * pelo chat normal (que assumiria a conversa inteira). Limpa o estado de
     * espera, registra a resposta no alerta correspondente, e redispara o
     * agente com a orientação injetada só nessa chamada — o agente continua
     * no controle da conversa.
     */
    public function orientar(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['orientacao' => 'required|string|min:1|max:2000']);

        $model = TicketAtendimento::findOrFail($ticket);

        if (! $model->aguardando_orientacao_em) {
            return response()->json(['message' => 'Este ticket não está aguardando orientação.'], 422);
        }

        $alerta = \App\Models\AlertaInterno::where('tenant_id', $model->tenant_id)
            ->where('ticket_id', $ticket)
            ->where('tipo', 'duvida_ia')
            ->whereNull('resposta')
            ->latest('id')
            ->first();

        $alerta?->update([
            'resposta'      => $request->orientacao,
            'respondido_em' => now(),
        ]);

        $model->update([
            'aguardando_orientacao_em' => null,
            'mensagem_espera_enviada'  => false,
        ]);

        dispatch(new \App\Jobs\SdrResponderJob($ticket, '', false, true, 0, $request->orientacao));

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 4: Adicionar a rota**

Em `routes/web.php`, dentro do grupo `Route::middleware('role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda')->group(function () {` que já contém `/kanban/ticket/{ticket}/liberar-ia` (linha ~218), adicione logo abaixo:

```php
        Route::post('/kanban/ticket/{ticket}/liberar-ia',      [KanbanController::class, 'liberarEAcionarIA']);
        Route::post('/kanban/ticket/{ticket}/orientar',        [KanbanController::class, 'orientar']);
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=KanbanOrientarTest`
Expected: PASS (3 testes)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php routes/web.php tests/Feature/KanbanOrientarTest.php
git commit -m "feat: endpoint de orientação do agente (Regra 2)"
```

---

### Task 6: UI — painel de orientação no card

**Files:**
- Modify: `resources/views/kanban/index.blade.php`

**Interfaces:**
- Consumes: `POST /api/painel/kanban/ticket/{ticket}/orientar` (Task 5). `ticketAtivo.aguardando_orientacao_em` já vem automaticamente no JSON do ticket (o model não restringe campos serializados, mesmo caso já observado no Bloco 1 pra `objetivos_cumpridos`).

- [ ] **Step 1: Adicionar o estado Alpine**

Em `resources/views/kanban/index.blade.php`, junto de `objetivosColuna: [],` (linha ~663), adicione:

```php
        orientacaoTexto:    '',
        orientacaoEnviando: false,
```

- [ ] **Step 2: Resetar o campo ao trocar de ticket**

No método `abrirTicket(ticket)` (linha ~860), logo depois de `this.objetivosColuna = [];` (linha ~869), adicione:

```php
            this.orientacaoTexto    = '';
            this.orientacaoEnviando = false;
```

- [ ] **Step 3: Adicionar o método de envio**

Perto de `carregarObjetivosColuna` (mesmo bloco de métodos relacionados ao ticket ativo), adicione:

```php
        async enviarOrientacao() {
            const texto = (this.orientacaoTexto || '').trim();
            if (!texto || this.orientacaoEnviando) return;

            this.orientacaoEnviando = true;
            const res = await this.api(`/api/painel/kanban/ticket/${this.ticketAtivo.id}/orientar`, 'POST', {
                orientacao: texto,
            });
            this.orientacaoEnviando = false;

            if (res.ok) {
                this.orientacaoTexto = '';
                this.ticketAtivo.aguardando_orientacao_em = null;
                await this.carregar(); // recarrega o board pra refletir o estado atualizado
            }
        },
```

- [ ] **Step 4: Adicionar o painel no template**

Logo depois do bloco `{{-- Itens identificados nas imagens --}}` (linhas ~343-365) e antes do bloco `{{-- Progresso do checklist de objetivos da coluna --}}` (linha ~367), adicione:

```html
                {{-- Painel de orientação — Regra 2, agente pausado com dúvida --}}
                <template x-if="ticketAtivo.aguardando_orientacao_em">
                    <div class="border-b bg-amber-50 px-5 py-3">
                        <div class="flex items-center gap-1.5 mb-2">
                            <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-xs font-semibold text-amber-800">Agente aguardando orientação</span>
                        </div>
                        <p class="text-xs text-amber-700 mb-2">
                            O agente pausou essa conversa porque não tinha certeza de como responder.
                            Escreva a orientação abaixo — o agente usa isso pra montar a resposta e mandar pro lead sozinho.
                        </p>
                        <textarea x-model="orientacaoTexto"
                                  rows="3"
                                  placeholder="Ex: O preço desse serviço é R$ 250."
                                  class="w-full text-xs border border-amber-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none bg-white"></textarea>
                        <div class="flex justify-end mt-2">
                            <button @click="enviarOrientacao()"
                                    :disabled="!orientacaoTexto.trim() || orientacaoEnviando"
                                    class="text-xs bg-amber-600 hover:bg-amber-700 disabled:opacity-40 text-white px-4 py-1.5 rounded-lg transition-colors">
                                <span x-show="!orientacaoEnviando">Enviar orientação</span>
                                <span x-show="orientacaoEnviando">Enviando...</span>
                            </button>
                        </div>
                    </div>
                </template>
```

- [ ] **Step 5: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 6: Commit**

```bash
git add resources/views/kanban/index.blade.php
git commit -m "feat: painel de orientação no card do ticket (Regra 2)"
```

---

### Task 7: UI e configuração da mensagem de espera por coluna

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php`
- Modify: `resources/views/kanban/config.blade.php`
- Test: `tests/Feature/KanbanColunaConfigAutoMoverTest.php` (adicionar caso — mesmo arquivo já usado pra outros campos de config por coluna, apesar do nome)

**Interfaces:**
- Consumes: `KanbanColunaConfig::$aguardando_orientacao_mensagem` (Task 1).
- Produces: `GET/PUT /api/painel/kanban/coluna-config/{coluna}` ganha `aguardando_orientacao_mensagem` — rota já existe, sem mudança nela.

- [ ] **Step 1: Escrever o teste**

Adicione ao final de `tests/Feature/KanbanColunaConfigAutoMoverTest.php` (antes do `}` final da classe):

```php
    public function test_persiste_mensagem_de_espera_de_orientacao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento', [
            'aguardando_orientacao_mensagem' => 'Só um instante, já te retorno!',
        ]);

        $response->assertOk();

        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'em_atendimento')->first();
        $this->assertSame('Só um instante, já te retorno!', $config->aguardando_orientacao_mensagem);
    }

    public function test_show_retorna_default_vazio_de_mensagem_de_espera(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/em_atendimento');

        $response->assertOk();
        $response->assertJson(['aguardando_orientacao_mensagem' => '']);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=KanbanColunaConfigAutoMoverTest`
Expected: FAIL nos 2 novos — o campo ainda não é exposto pelo controller.

- [ ] **Step 3: Atualizar `show()` e `update()`**

Em `app/Http/Controllers/Painel/KanbanColunaConfigController.php`, no método `show()`, adicione logo depois da linha `'timeout_reassuncao_segundos' => ...`:

```php
            'aguardando_orientacao_mensagem' => $config?->aguardando_orientacao_mensagem ?? '',
```

No método `update()`, dentro do array de `$request->validate([...])`, adicione logo depois de `'timeout_reassuncao_segundos' => 'sometimes|integer|min:60|max:604800',`:

```php
            'aguardando_orientacao_mensagem' => 'nullable|string|max:1000',
```

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=KanbanColunaConfigAutoMoverTest`
Expected: PASS (todos — os já existentes + os 2 novos)

- [ ] **Step 5: Adicionar o campo na tela de config**

Em `resources/views/kanban/config.blade.php`, adicione o estado Alpine junto de `timeoutReassuncaoAtivo: {}` (bloco "Reassunção automática do agente"):

```php
        aguardandoOrientacaoMensagem: {},
```

Em `carregarIa(key)`, logo depois do bloco que popula `this.timeoutReassuncaoDelayUnidade[key]`, adicione:

```php
                this.aguardandoOrientacaoMensagem[key] = json.aguardando_orientacao_mensagem ?? '';
```

Em `salvarIa(key)`, no payload do `PUT`, adicione logo depois de `timeout_reassuncao_segundos: ...`:

```php
                aguardando_orientacao_mensagem: this.aguardandoOrientacaoMensagem[key] ?? '',
```

No template, logo depois do bloco de "Reassumir automaticamente após silêncio do atendente" (mesma seção "Agente de IA" da coluna, ver Bloco 2 Task 4), adicione:

```html
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <label class="text-xs font-semibold text-gray-500 mb-1 block">Mensagem de espera durante orientação (Regra 2)</label>
                            <p class="text-xs text-gray-400 mb-2">
                                Se o agente pausar aguardando sua orientação e o lead escrever de novo nesse meio tempo,
                                essa mensagem é mandada uma única vez. Deixe em branco pra usar a mensagem padrão do sistema.
                            </p>
                            <textarea :value="aguardandoOrientacaoMensagem[col.key] || ''"
                                      @input="aguardandoOrientacaoMensagem[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                      rows="2"
                                      placeholder="Estou verificando mais detalhes sobre isso pra te dar a melhor resposta. Em breve retorno!"
                                      class="w-full text-xs border border-gray-300 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"></textarea>
                        </div>
```

- [ ] **Step 6: Compilar as views e checar erro de sintaxe Blade**

Run: `php artisan view:clear && php artisan view:cache`
Expected: `Blade templates cached successfully.` sem erro.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php resources/views/kanban/config.blade.php \
        tests/Feature/KanbanColunaConfigAutoMoverTest.php
git commit -m "feat: config da mensagem de espera de orientação por coluna"
```

---

## Depois de todas as tasks

- [ ] Rodar a suíte inteira uma última vez: `php artisan test` — esperado: só a falha pré-existente e conhecida de `ExampleTest`.
- [ ] `./deploy.sh` — migrations rodam automaticamente em produção.
- [ ] Testar manualmente em produção: forçar uma dúvida (ou simular via tinker), confirmar que o alerta aparece, o card mostra o painel de orientação, e responder gera uma mensagem real ao lead.
- [ ] Atualizar `TAREFAS.md`: marcar o Bloco 3 de `T-REGRAS-ATENDIMENTO-IA-HUMANO` como concluído e deployado.
