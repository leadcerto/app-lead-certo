# Avanço Automático de Coluna por Checklist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando a checklist de objetivos de uma coluna do Kanban for completada — pela IA (via token, já existe) ou por um humano escrevendo manualmente (novo) — o ticket avança sozinho pra próxima coluna do funil, sem precisar de movimento manual.

**Architecture:** Um serviço novo (`AvancoAutomaticoKanbanService`) concentra a lógica de marcar objetivo(s) cumprido(s) e avançar de coluna quando a checklist fecha, protegido por uma trava (`Cache::lock`) contra corrida — mesmo padrão já usado nesta sessão em três outros pontos de criação/atualização de ticket. `SdrResponderService` passa a delegar a esse serviço em vez da lógica que já existia inline. Um job novo (`AvaliarObjetivosPorMensagemHumanaJob`), disparado por um hook único no model `Mensagem` (cobre os três canais de mensagem humana de uma vez: WhatsApp Oficial, não-oficial, painel), estende a mesma marcação pro caminho onde é um humano quem conduz a conversa.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8, PHPUnit clássico (`test_*`, sem Pest), `RefreshDatabase`, mock de `OpenRouterService`/`Http::fake()` pra IA, `Queue::fake()` pra jobs.

## Global Constraints

- Nunca fazer deploy manual via SSH — sempre `git commit` local + `./deploy.sh` (regra do `CLAUDE.md`).
- Toda funcionalidade de sincronização entre canais (Uazapi/Covercut) tem que existir e se comportar igual nos dois — regra fundamental do `CLAUDE.md`. Este plano cumpre isso usando um hook único no model `Mensagem`, não lógica duplicada por canal.
- Models de tenant usam `TenantScope` como global scope — em contexto de job/webhook (sem usuário autenticado), sempre usar `withoutGlobalScopes()` + filtro explícito por `tenant_id`, mesmo padrão já usado em `TicketReaberturaService`/`CovercutWebhookController`/`UazapiWebhookController`.
- Corrida entre processos concorrentes (dois jobs, ou job + resposta da IA, mexendo no mesmo ticket ao mesmo tempo) é protegida por `Illuminate\Support\Facades\Cache::lock($chave, $segundos)->block($espera, $callback)` — mesmo padrão já usado em `CovercutWebhookController`, `UazapiWebhookController` e `SecretariaEletronicaController` nesta mesma sessão. Driver de cache é `database` (tabela `cache_locks` já existe).
- Colunas com papel `Encerramento` ou `TransferenciaHumana` nunca são destino do avanço automático — chegar nelas exige sinal explícito (token de movimento da IA, ação manual). Ver `App\Enums\PapelColunaKanban`.
- Testes em `tests/Feature/*.php`, PHPUnit clássico, métodos `test_descricao_em_snake_case()`, `use RefreshDatabase;`.

---

### Task 1: `AvancoAutomaticoKanbanService`

**Files:**
- Create: `app/Services/AvancoAutomaticoKanbanService.php`
- Test: `tests/Feature/AvancoAutomaticoKanbanServiceTest.php`

**Interfaces:**
- Produces: `AvancoAutomaticoKanbanService` com dois métodos públicos:
  - `marcarObjetivos(TicketAtendimento $ticket, array $idsObjetivos): void` — `$idsObjetivos` é um array de inteiros.
  - `avancarSeCompleto(TicketAtendimento $ticket): bool` — retorna `true` se avançou de coluna, `false` caso contrário.

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvancoAutomaticoKanbanServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    private function criarObjetivo(TicketAtendimento $ticket, string $texto, string $coluna = 'em_atendimento'): KanbanColunaObjetivo
    {
        return KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => $coluna,
            'texto' => $texto, 'ordem' => 1, 'ativo' => true,
        ]);
    }

    public function test_marca_objetivo_sem_avancar_se_ainda_faltam_outros(): void
    {
        $ticket = $this->criarTicket();
        $obj1   = $this->criarObjetivo($ticket, 'Endereço de origem');
        $this->criarObjetivo($ticket, 'Lista de itens'); // segundo objetivo, não marcado

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj1->id]);

        $fresco = $ticket->fresh();
        $this->assertSame([$obj1->id], $fresco->objetivos_cumpridos);
        $this->assertSame('em_atendimento', $fresco->coluna_kanban);
    }

    public function test_marca_ultimo_objetivo_avanca_para_proxima_coluna(): void
    {
        $ticket = $this->criarTicket();
        $obj1   = $this->criarObjetivo($ticket, 'Endereço de origem');
        $obj2   = $this->criarObjetivo($ticket, 'Lista de itens');
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj2->id]);

        $fresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $fresco->coluna_kanban);
        // Checklist da nova coluna começa zerada (hook de reset já existente).
        $this->assertSame([], $fresco->objetivos_cumpridos ?? []);
    }

    public function test_nao_avanca_para_coluna_de_papel_encerramento(): void
    {
        $ticket = $this->criarTicket('servico_agendado');
        $obj    = $this->criarObjetivo($ticket, 'Serviço confirmado', 'servico_agendado');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $fresco = $ticket->fresh();
        $this->assertSame('servico_agendado', $fresco->coluna_kanban);
        $this->assertSame([$obj->id], $fresco->objetivos_cumpridos);
    }

    public function test_nao_avanca_para_coluna_de_papel_transferencia_humana(): void
    {
        $ticket = $this->criarTicket('encerrado');
        $obj    = $this->criarObjetivo($ticket, 'Algo', 'encerrado');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame('encerrado', $ticket->fresh()->coluna_kanban);
    }

    public function test_coluna_sem_objetivo_ativo_nunca_avanca(): void
    {
        $ticket = $this->criarTicket();

        $avancou = app(AvancoAutomaticoKanbanService::class)->avancarSeCompleto($ticket);

        $this->assertFalse($avancou);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_ja_na_ultima_coluna_nao_quebra(): void
    {
        $ticket = $this->criarTicket('outros'); // última coluna padrão (ordem 8)
        $obj    = $this->criarObjetivo($ticket, 'Algo', 'outros');

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame('outros', $ticket->fresh()->coluna_kanban);
    }

    public function test_id_invalido_ou_de_outra_coluna_e_ignorado(): void
    {
        $ticket = $this->criarTicket();
        $obj    = $this->criarObjetivo($ticket, 'Endereço', 'aguardando_orcamento'); // outra coluna

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id, 999999]);

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    public function test_objetivo_ja_marcado_nao_duplica_na_lista(): void
    {
        $ticket = $this->criarTicket();
        $obj    = $this->criarObjetivo($ticket, 'Endereço');
        $ticket->update(['objetivos_cumpridos' => [$obj->id]]);

        app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, [$obj->id]);

        $this->assertSame([$obj->id], $ticket->fresh()->objetivos_cumpridos);
    }
}
```

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `php artisan test --filter=AvancoAutomaticoKanbanServiceTest`
Expected: FAIL — `Class "App\Services\AvancoAutomaticoKanbanService" not found`

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services;

use App\Enums\PapelColunaKanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaObjetivo;
use App\Models\TicketAtendimento;
use Illuminate\Support\Facades\Cache;

/**
 * Marca objetivos (checklist) de uma coluna como cumpridos e avança o ticket
 * pra próxima coluna do funil quando a checklist fecha. Reusado tanto pelo
 * caminho onde a IA está respondendo (SdrResponderService, via token
 * [OBJETIVO_CUMPRIDO:<id>]) quanto pelo caminho onde um humano conduz a
 * conversa manualmente (AvaliarObjetivosPorMensagemHumanaJob).
 *
 * Colunas com papel Encerramento ou TransferenciaHumana nunca são destino
 * deste avanço automático — chegar nelas exige um sinal explícito (token de
 * movimento da própria IA, ação manual), porque é uma decisão mais forte do
 * que "terminei a checklist local".
 */
class AvancoAutomaticoKanbanService
{
    public function marcarObjetivos(TicketAtendimento $ticket, array $idsObjetivos): void
    {
        Cache::lock($this->chaveTrava($ticket), 10)->block(5, function () use ($ticket, $idsObjetivos) {
            $atual = $this->recarregar($ticket);
            if (! $atual) {
                return;
            }

            $idsAtivos = $this->objetivosAtivos($atual);
            $cumpridos = $atual->objetivos_cumpridos ?? [];
            $mudou     = false;

            foreach ($idsObjetivos as $id) {
                $id = (int) $id;
                if (in_array($id, $idsAtivos, true) && ! in_array($id, $cumpridos, true)) {
                    $cumpridos[] = $id;
                    $mudou       = true;
                }
            }

            if ($mudou) {
                $atual->update(['objetivos_cumpridos' => $cumpridos]);
            }

            $this->avancarSeCompletoInterno($atual, $idsAtivos);
        });
    }

    public function avancarSeCompleto(TicketAtendimento $ticket): bool
    {
        return Cache::lock($this->chaveTrava($ticket), 10)->block(5, function () use ($ticket) {
            $atual = $this->recarregar($ticket);
            if (! $atual) {
                return false;
            }

            return $this->avancarSeCompletoInterno($atual, $this->objetivosAtivos($atual));
        });
    }

    private function avancarSeCompletoInterno(TicketAtendimento $ticket, array $idsAtivos): bool
    {
        if (empty($idsAtivos)) {
            return false;
        }

        $cumpridos = $ticket->objetivos_cumpridos ?? [];
        foreach ($idsAtivos as $id) {
            if (! in_array($id, $cumpridos, true)) {
                return false;
            }
        }

        $proxima = KanbanColuna::proximaChave($ticket->tenant_id, $ticket->coluna_kanban);
        if (! $proxima) {
            return false;
        }

        $papel = KanbanColuna::papelDe($ticket->tenant_id, $proxima);
        if (in_array($papel, [PapelColunaKanban::Encerramento, PapelColunaKanban::TransferenciaHumana], true)) {
            return false;
        }

        // Mesmo padrão do token de movimento manual da IA (SdrResponderService) —
        // marca origem 'ia' pro guardrail de salto (Regra 13) diferenciar de
        // política automática de sistema (auto-mover por tempo, por exemplo).
        $ticket->origemMudancaColuna = 'ia';
        $ticket->update(['coluna_kanban' => $proxima]);
        // objetivos_cumpridos é zerado automaticamente pelo hook do model
        // (TicketAtendimento::updating) porque este update não o define
        // explicitamente e coluna_kanban está mudando.

        return true;
    }

    private function objetivosAtivos(TicketAtendimento $ticket): array
    {
        return KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->pluck('id')
            ->all();
    }

    private function recarregar(TicketAtendimento $ticket): ?TicketAtendimento
    {
        return TicketAtendimento::withoutGlobalScopes()->find($ticket->id);
    }

    private function chaveTrava(TicketAtendimento $ticket): string
    {
        return "avanco-objetivos:{$ticket->id}";
    }
}
```

- [ ] **Step 4: Rodar os testes pra confirmar que passam**

Run: `php artisan test --filter=AvancoAutomaticoKanbanServiceTest`
Expected: PASS (8 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Services/AvancoAutomaticoKanbanService.php tests/Feature/AvancoAutomaticoKanbanServiceTest.php
git commit -m "feat: AvancoAutomaticoKanbanService — avanca coluna quando checklist fecha"
```

---

### Task 2: `SdrResponderService` delega o avanço automático ao novo serviço

**Files:**
- Modify: `app/Services/SdrResponderService.php:136-173` (seção "4.5")
- Test: `tests/Feature/SdrResponderServiceObjetivoTokenTest.php` (arquivo existente, adicionar testes)

**Interfaces:**
- Consumes: `AvancoAutomaticoKanbanService::marcarObjetivos(TicketAtendimento $ticket, array $idsObjetivos): void` (Task 1).
- Produces: nenhuma interface nova — comportamento observável (o ticket avança de coluna quando a IA completa a checklist via token).

- [ ] **Step 1: Escrever os testes que falham**

Adicionar ao final da classe em `tests/Feature/SdrResponderServiceObjetivoTokenTest.php` (antes do `}` final):

```php
    public function test_marcar_ultimo_objetivo_via_token_avanca_a_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem', 'ordem' => 1, 'ativo' => true,
        ]);
        $obj2 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens', 'ordem' => 2, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Perfeito, anotado!\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        $fresco = $ticket->fresh();
        $this->assertSame('aguardando_orcamento', $fresco->coluna_kanban);
        $this->assertSame([], $fresco->objetivos_cumpridos ?? []);
    }

    /**
     * Se a mesma resposta já incluir um token explícito de movimento de
     * coluna ([NOME_DA_COLUNA], seção "4" — roda antes da seção "4.5"), o
     * avanço automático por checklist não deve ser aplicado por cima —
     * o ticket já mudou de coluna por decisão explícita, e os ids do token
     * de objetivo se referem à coluna de ONDE ele veio, não a de destino.
     */
    public function test_token_de_movimento_explicito_impede_avanco_automatico_por_checklist(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketComCanal();

        $obj1 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem', 'ordem' => 1, 'ativo' => true,
        ]);
        $obj2 = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Lista de itens', 'ordem' => 2, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$obj1->id]]);

        // A IA decide mover explicitamente pra 'pagamento' (pulando
        // aguardando_orcamento/aguardando_lead) E, na mesma resposta,
        // reporta o último objetivo de em_atendimento como cumprido.
        $this->mock(OpenRouterService::class, function ($mock) use ($obj2) {
            $mock->shouldReceive('chat')->once()
                ->andReturn("Combinado!\n[PAGAMENTO]\n[OBJETIVO_CUMPRIDO:{$obj2->id}]");
        });

        app(SdrResponderService::class)->responder($ticket);

        // Foi pra onde a IA mandou explicitamente, não pra próxima coluna
        // da ordem natural (aguardando_orcamento).
        $this->assertSame('pagamento', $ticket->fresh()->coluna_kanban);
    }
```

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `php artisan test --filter=SdrResponderServiceObjetivoTokenTest`
Expected: FAIL — `test_marcar_ultimo_objetivo_via_token_avanca_a_coluna` falha porque o ticket continua em `em_atendimento` (a lógica atual só marca, não avança).

- [ ] **Step 3: Substituir a seção "4.5" em `SdrResponderService::responder()`**

Em `app/Services/SdrResponderService.php`, adicionar o import no topo do arquivo (junto aos outros `use`):

```php
use App\Services\AvancoAutomaticoKanbanService;
```

Substituir todo o bloco (linhas 136-173, da linha `// ── 4.5. Detectar tokens de objetivo cumprido e aplicar ─────────────` até `$resposta = trim(preg_replace('/\[OBJETIVO_CUMPRIDO:\d+\]/', '', $resposta));`) por:

```php
        // ── 4.5. Detectar tokens de objetivo cumprido e aplicar ─────────────
        // Mesmo padrão dos tokens de movimento acima — o agente reporta na
        // própria resposta quais objetivos do checklist da coluna considera
        // cumpridos. Delegado pro AvancoAutomaticoKanbanService, que também
        // avança a coluna sozinho quando a checklist fecha.
        //
        // Só roda se a seção "4" acima NÃO já moveu o ticket explicitamente
        // ($moveu === false) — se a IA já mandou mover pra outra coluna nesta
        // mesma resposta, os ids do token de objetivo se referem à coluna de
        // ONDE ela veio (que já mudou), não faz sentido tentar marcar contra
        // a nova coluna nem tentar avançar de novo por cima.
        preg_match_all('/\[OBJETIVO_CUMPRIDO:(\d+)\]/', $resposta, $matchesObjetivos);
        if (! empty($matchesObjetivos[1]) && ! $moveu) {
            $ids = array_map('intval', $matchesObjetivos[1]);
            app(AvancoAutomaticoKanbanService::class)->marcarObjetivos($ticket, $ids);
            Log::info('SdrResponder: objetivos marcados como cumpridos', [
                'ticket_id' => $ticket->id, 'ids' => $matchesObjetivos[1],
            ]);
        }
        $resposta = trim(preg_replace('/\[OBJETIVO_CUMPRIDO:\d+\]/', '', $resposta));
```

Os imports `KanbanColunaObjetivo` no topo do arquivo (usado só pela lógica antiga) podem ficar — não há problema em um import não usado sobrar por enquanto, mas se o linter do projeto reclamar, remover `use App\Models\KanbanColunaObjetivo;` do topo do arquivo (confirmar antes que não é usado em nenhum outro lugar do arquivo).

- [ ] **Step 4: Rodar os testes pra confirmar que passam**

Run: `php artisan test --filter=SdrResponderServiceObjetivoTokenTest`
Expected: PASS (7 testes — os 5 já existentes + os 2 novos)

Rodar também a suíte completa relacionada a garantir que nada mais quebrou:

Run: `php artisan test --filter=SdrResponderService`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/SdrResponderService.php tests/Feature/SdrResponderServiceObjetivoTokenTest.php
git commit -m "feat: SdrResponderService delega avanco automatico ao AvancoAutomaticoKanbanService"
```

---

### Task 3: `AvaliarObjetivosPorMensagemHumanaJob`

**Files:**
- Create: `app/Jobs/AvaliarObjetivosPorMensagemHumanaJob.php`
- Test: `tests/Feature/AvaliarObjetivosPorMensagemHumanaJobTest.php`

**Interfaces:**
- Consumes: `AvancoAutomaticoKanbanService::marcarObjetivos()` (Task 1); `OpenRouterService::chat(array $messages, string $tier, int $maxTokens, ?string $origem, ?int $tenantId): ?string` (já existe).
- Produces: `AvaliarObjetivosPorMensagemHumanaJob` com construtor `__construct(public int $ticketId)`, despachável via `AvaliarObjetivosPorMensagemHumanaJob::dispatch($ticketId)` — usado pela Task 4.

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliarObjetivosPorMensagemHumanaJobTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComMensagens(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo' => 'Preciso mudar de Valinhos SP pra Nova Iguaçu RJ',
            'enviado_em' => now()->subMinutes(5),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto',
            'conteudo' => 'Show, endereços anotados! E o que vamos transportar?',
            'enviado_em' => now(),
        ]);

        return $ticket;
    }

    public function test_marca_objetivo_identificado_pela_ia_na_conversa(): void
    {
        $ticket = $this->criarTicketComMensagens();
        $obj    = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) use ($obj) {
            $mock->shouldReceive('chat')->once()->andReturn((string) $obj->id);
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([$obj->id], $ticket->fresh()->objetivos_cumpridos);
    }

    public function test_resposta_nenhum_nao_marca_nada(): void
    {
        $ticket = $this->criarTicketComMensagens();
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('NENHUM');
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    public function test_sem_objetivo_pendente_nao_chama_a_ia(): void
    {
        $ticket = $this->criarTicketComMensagens();
        $obj    = KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$obj->id]]); // já completo

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->never();
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));
    }

    public function test_falha_da_ia_nao_quebra_nem_marca_nada(): void
    {
        $ticket = $this->criarTicketComMensagens();
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço de origem e destino', 'ordem' => 1, 'ativo' => true,
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        (new AvaliarObjetivosPorMensagemHumanaJob($ticket->id))->handle(app(OpenRouterService::class), app(\App\Services\AvancoAutomaticoKanbanService::class));

        $this->assertSame([], $ticket->fresh()->objetivos_cumpridos ?? []);
    }
}
```

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `php artisan test --filter=AvaliarObjetivosPorMensagemHumanaJobTest`
Expected: FAIL — `Class "App\Jobs\AvaliarObjetivosPorMensagemHumanaJob" not found`

- [ ] **Step 3: Implementar o job**

```php
<?php

namespace App\Jobs;

use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Estende a marcação de objetivos (ver AvancoAutomaticoKanbanService) pro
 * caminho onde é um humano quem conduz a conversa, não a IA — hoje a
 * marcação só acontecia via token [OBJETIVO_CUMPRIDO:<id>] gerado pela IA
 * respondendo. Despachado pelo hook único em Mensagem::booted() sempre que
 * uma mensagem de humano é criada num ticket com checklist ainda pendente.
 */
class AvaliarObjetivosPorMensagemHumanaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ticketId) {}

    public function handle(OpenRouterService $openRouter, AvancoAutomaticoKanbanService $avanco): void
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        $jaCumpridos = $ticket->objetivos_cumpridos ?? [];

        $pendentes = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->get()
            ->reject(fn (KanbanColunaObjetivo $o) => in_array($o->id, $jaCumpridos, true));

        if ($pendentes->isEmpty()) {
            return;
        }

        $historico = Mensagem::withoutGlobalScopes()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn (Mensagem $m) => "[{$m->remetente}] {$m->conteudo}")
            ->implode("\n");

        $listaObjetivos = $pendentes->map(fn (KanbanColunaObjetivo $o) => "{$o->id}: {$o->texto}")->implode("\n");

        $resposta = $openRouter->chat([
            ['role' => 'system', 'content' =>
                "Você analisa uma conversa de atendimento de frete/mudança e decide quais itens de uma "
                . "checklist já foram resolvidos pelo que já foi dito — mesmo que quem esteja conduzindo a "
                . "conversa seja um atendente humano, não você. Responda SOMENTE com os ids numéricos dos "
                . "itens já cumpridos, um por linha, sem nenhum texto extra. Se nenhum item foi cumprido "
                . "ainda, responda exatamente a palavra NENHUM.\n\nItens da checklist (id: descrição):\n{$listaObjetivos}"],
            ['role' => 'user', 'content' => $historico],
        ], 'simples', 100, 'avaliar_objetivos_mensagem_humana', $ticket->tenant_id);

        if (! $resposta || trim(mb_strtoupper($resposta)) === 'NENHUM') {
            return;
        }

        preg_match_all('/\d+/', $resposta, $matches);
        $ids = array_map('intval', $matches[0]);

        if (empty($ids)) {
            Log::debug('AvaliarObjetivosPorMensagemHumanaJob: resposta da IA sem ids reconhecíveis', [
                'ticket_id' => $ticket->id, 'resposta' => $resposta,
            ]);
            return;
        }

        $avanco->marcarObjetivos($ticket, $ids);
    }
}
```

- [ ] **Step 4: Rodar os testes pra confirmar que passam**

Run: `php artisan test --filter=AvaliarObjetivosPorMensagemHumanaJobTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/AvaliarObjetivosPorMensagemHumanaJob.php tests/Feature/AvaliarObjetivosPorMensagemHumanaJobTest.php
git commit -m "feat: AvaliarObjetivosPorMensagemHumanaJob avalia checklist quando humano conduz a conversa"
```

---

### Task 4: Hook único em `Mensagem` dispara o job pros 3 canais

**Files:**
- Modify: `app/Models/Mensagem.php`
- Test: `tests/Feature/MensagemHumanaDisparaAvaliacaoObjetivosTest.php`

**Interfaces:**
- Consumes: `AvaliarObjetivosPorMensagemHumanaJob::dispatch(int $ticketId)` (Task 3).

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MensagemHumanaDisparaAvaliacaoObjetivosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComObjetivoPendente(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
        ]);

        return $ticket;
    }

    public function test_mensagem_humana_com_objetivo_pendente_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_de_bot_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_mensagem_de_lead_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_checklist_ja_completa_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket  = $this->criarTicketComObjetivoPendente();
        $objId   = KanbanColunaObjetivo::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->value('id');
        $ticket->update(['objetivos_cumpridos' => [$objId]]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Beleza',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_ia_ativo_desligado_na_coluna_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->update(['ia_ativo' => false]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_sem_config_de_coluna_nao_despacha_o_job(): void
    {
        // Achado real desta sessão: ausência de config equivale a IA
        // desativada em todos os outros automatismos do Kanban (mesmo
        // padrão de FollowupConversas) — este job segue a mesma regra.
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->delete();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    /**
     * Paridade entre canais (regra fundamental do CLAUDE.md): confirma que o
     * hook único cobre de fato os três pontos reais de criação de mensagem
     * humana, não só documenta a intenção. Testa disparando o webhook/
     * endpoint real de cada canal, não chamando Mensagem::create() direto.
     */
    public function test_mensagem_humana_via_uazapi_webhook_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token' => 'wh-objetivo-uazapi', 'uazapi_instance_token' => 'inst-objetivo-uazapi',
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-objetivo-uazapi',
            'config' => ['instance_token' => 'inst-objetivo-uazapi'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511911112222']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);

        // Mensagem enviada pelo atendente direto no app do celular (fromMe,
        // sem viaApi) — mesmo formato usado em UazapiWebhookController pra
        // detectar mensagem humana (ver transferirParaHumano()).
        $this->postJson('/api/webhook/uazapi/wh-objetivo-uazapi', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => true,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Endereço anotado, obrigado',
            ],
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_humana_via_covercut_echo_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999888', 'webhook_secret' => 'segredo-objetivo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511933334444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);

        $payload = [
            'event' => 'message', 'direction' => 'outbound', 'from_number_id' => '999888',
            'echo_source' => 'phone',
            'contact' => ['wa_id' => '5511933334444'],
            'message' => ['id' => 'wamid.objetivo1', 'type' => 'text', 'text' => 'Endereço anotado'],
        ];
        $body = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, 'segredo-objetivo');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_humana_via_painel_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok-painel-objetivo']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok-painel-objetivo']]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Endereço anotado, valeu!',
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }
}
```

Rota e campo confirmados em `app/Http/Controllers/Painel/KanbanController.php:230` (`enviarMensagem`) e `routes/web.php:215`: `POST /api/painel/kanban/ticket/{ticket}/mensagem`, body `{ conteudo: string }`. `enviarMensagem()` chama `$canal->servico()->enviarTextoDireto(...)` antes de criar a `Mensagem` — o teste precisa do `Http::fake()` já incluído acima pra esse envio não falhar de verdade.

- [ ] **Step 2: Rodar os testes pra confirmar que falham**

Run: `php artisan test --filter=MensagemHumanaDisparaAvaliacaoObjetivosTest`
Expected: FAIL — nenhum job é despachado ainda (hook não existe)

- [ ] **Step 3: Adicionar o hook em `Mensagem::booted()`**

Em `app/Models/Mensagem.php`, adicionar os imports necessários e o hook:

```php
<?php

namespace App\Models;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mensagem extends Model
{
    protected $table = 'mensagens';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        // Achado real (2026-08-13): quando é um humano que conduz a conversa
        // manualmente (não a IA), nada observava o que ele escreveu pra
        // atualizar a checklist de objetivos da coluna — o ticket nunca
        // avançava sozinho nesse caminho, só quando a própria IA respondia.
        // Hook único aqui (em vez de em cada controller de webhook/painel)
        // cobre os três canais de mensagem humana de uma vez — regra
        // fundamental de paridade entre canais do CLAUDE.md.
        static::created(function (Mensagem $mensagem) {
            if ($mensagem->remetente !== 'humano') {
                return;
            }

            $ticket = TicketAtendimento::withoutGlobalScopes()->find($mensagem->ticket_id);
            if (! $ticket) {
                return;
            }

            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->first();

            if (! $config?->ia_ativo) {
                return;
            }

            $idsAtivos = KanbanColunaObjetivo::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->where('ativo', true)
                ->pluck('id');

            if ($idsAtivos->isEmpty()) {
                return;
            }

            $jaCumpridos = collect($ticket->objetivos_cumpridos ?? []);
            if ($idsAtivos->diff($jaCumpridos)->isEmpty()) {
                return; // checklist já completa, nada pendente
            }

            AvaliarObjetivosPorMensagemHumanaJob::dispatch($ticket->id);
        });
    }

    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'remetente',
        'tipo',
        'conteudo',
        'midia_url',
        'provider_message_id',
        'enviado_em',
    ];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class);
    }
}
```

- [ ] **Step 4: Rodar os testes pra confirmar que passam**

Run: `php artisan test --filter=MensagemHumanaDisparaAvaliacaoObjetivosTest`
Expected: PASS (9 testes)

Rodar a suíte completa pra garantir que o hook novo em `Mensagem` (que roda em toda criação de mensagem do sistema) não quebrou nada:

Run: `php artisan test`
Expected: PASS (só a falha pré-existente e não-relacionada do `ExampleTest`)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Mensagem.php tests/Feature/MensagemHumanaDisparaAvaliacaoObjetivosTest.php
git commit -m "feat: hook em Mensagem dispara avaliacao de objetivos quando humano conduz a conversa"
```

---

## Verificação final

Depois da Task 4, rodar a suíte inteira do projeto uma última vez:

Run: `php artisan test`
Expected: PASS (única falha esperada: `ExampleTest`, pré-existente e não-relacionada)

Deploy segue o fluxo padrão do projeto: merge na `main` (finishing-a-development-branch), depois `./deploy.sh` — nunca deploy manual via SSH (regra do `CLAUDE.md`).
