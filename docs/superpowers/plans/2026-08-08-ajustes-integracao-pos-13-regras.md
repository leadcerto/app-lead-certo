# Ajustes de Integração Pós-13-Regras Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir 5 achados de integração encontrados numa auditoria holística
feita depois dos Blocos 1-4 das 13 regras (já em produção): origem de
migração não marcada em `encerrar()`, painel de alertas sem prioridade,
teto de tentativas de envio perdido, pausa de dúvida sem timeout (com
alerta órfão), e guardrail de salto cego pro caso em que a própria IA
decide pular direto pra Encerrado.

**Architecture:** 9 tarefas micro-decompostas, cada uma isolada e
independentemente testável. Duas cadeias de dependência curtas (migration →
uso da coluna nova) e o resto são tarefas totalmente independentes entre si.

**Tech Stack:** Laravel 13 / PHP 8.4, MySQL 8 (prod) / SQLite (testes),
Alpine.js v3 + Tailwind (config da coluna).

## Global Constraints

- Todo alerta é criado via `AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno`
  (`app/Services/AlertaInternoService.php`) — nunca `AlertaInterno::create()` direto.
- `alertas_internos.tipo` é string livre (sem enum) — o tipo novo deste
  bloco (`envio_falhou`) não exige migration própria.
- Toda query cross-tenant em comando usa `withoutGlobalScopes()` explicitamente.
- Testes usam `RefreshDatabase` + `Tenant::factory()->create()` (semeia 8
  colunas padrão — ver `database/factories/TenantFactory::colunasPadrao()`).
- `AlertaInterno::where('tenant_id', $tid)->where('ticket_id', $tid)->where('tipo', 'duvida_ia')->whereNull('resposta')->latest('id')->first()`
  é a query já estabelecida (`KanbanController::orientar()`) pra achar o
  alerta de dúvida pendente de um ticket — reusar exatamente essa forma em
  qualquer lugar novo que precise achar/fechar esse alerta.
- `TicketAtendimento::$origemMudancaColuna` (propriedade pública, não
  persistida, já existe desde o Bloco 4) é como qualquer código sinaliza
  quem iniciou uma mudança de `coluna_kanban` antes de chamar `->update()`.

---

### Task 1: `KanbanController::encerrar()` marca origem humana

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php`
- Create: `tests/Feature/KanbanControllerEncerrarTest.php`

**Interfaces:** nenhuma nova — só consome `$origemMudancaColuna` (já existe).

- [ ] **Step 1: Marcar a origem antes do update**

Em `app/Http/Controllers/Painel/KanbanController.php`, no método `encerrar()`
(atualmente por volta da linha 264-280):

```php
    public function encerrar(Request $request, int $ticket): JsonResponse
    {
        $request->validate(['tag_desfecho' => 'required|string|max:100']);

        $model = TicketAtendimento::findOrFail($ticket);

        // Regra 13 (Bloco 5) — terceiro e último endpoint de movimentação
        // manual do sistema (os outros dois, mover()/moverParaOutros(), já
        // marcam desde o Bloco 4).
        $model->origemMudancaColuna = 'humano';
        $model->update($model->dadosParaEncerrar([
            'tag_desfecho'         => $request->tag_desfecho,
            'encerrado_em'         => now(),
            'followup_agendado_em' => $request->followup_em ?? null,
        ]));

        ConversationQAJob::dispatch($model->id);
        GerarResumoTicketJob::dispatch($model->id)->delay(now()->addSeconds(5));

        return response()->json(['ticket_id' => $ticket, 'encerrado' => true]);
    }
```

Nenhuma outra linha do método muda.

- [ ] **Step 2: Escrever o teste**

```php
<?php
// tests/Feature/KanbanControllerEncerrarTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerEncerrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_encerrar_grava_origem_humano_no_historico(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/encerrar", [
            'tag_desfecho' => 'venda_fechada',
        ])->assertOk();

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'encerrado', 'origem' => 'humano',
        ]);
    }
}
```

Antes de escrever o teste, confirme a rota exata de `encerrar()` (mesmo
prefixo `/api/painel/kanban/ticket/{ticket}/...` usado por `mover()`
[`/mover`] e `moverParaOutros()` [`/outros`]) olhando `routes/api.php` ou
`routes/web.php` — se o nome do segmento não for `/encerrar`, ajuste a URL
do teste pra bater com a rota real.

- [ ] **Step 3: Rodar o teste**

Run: `php artisan test --filter=KanbanControllerEncerrarTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanControllerEncerrarTest.php
git commit -m "fix: encerrar() marca origem humana no histórico de coluna (Regra 13)"
```

---

### Task 2: Painel prioriza dúvidas não respondidas

**Files:**
- Modify: `app/Http/Controllers/Painel/AlertaInternoController.php`
- Modify: `tests/Feature/AlertaInternoControllerTest.php`

**Interfaces:** nenhuma nova — o formato da resposta JSON não muda, só a
ordem/composição de `data`.

- [ ] **Step 1: Reescrever a query do `index()`**

```php
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Regra 2 (Bloco 5) — dúvidas não respondidas nunca saem da lista por
        // volume de outros tipos de alerta (ex: ticket_travado, gerado a
        // cada 15min pelo Bloco 4). Prioridade: todas as dúvidas pendentes
        // primeiro, depois os demais tipos mais recentes até completar 20.
        $duvidasPendentes = AlertaInterno::where('tenant_id', $tenantId)
            ->where('tipo', 'duvida_ia')
            ->whereNull('resposta')
            ->orderByDesc('created_at')
            ->get();

        $restantes = 20 - $duvidasPendentes->count();

        $outros = $restantes > 0
            ? AlertaInterno::where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->where('tipo', '!=', 'duvida_ia')->orWhereNotNull('resposta');
                })
                ->orderByDesc('created_at')
                ->limit($restantes)
                ->get()
            : collect();

        $alertas = $duvidasPendentes->concat($outros);

        $naoLidos = AlertaInterno::where('tenant_id', $tenantId)
            ->whereNull('lido_em')
            ->count();

        return response()->json([
            'data'            => $alertas,
            'nao_lidos_count' => $naoLidos,
        ]);
    }
```

Note: se `$duvidasPendentes->count()` já for >= 20, `$restantes` fica <= 0
e nenhuma query extra roda — todas as 20+ dúvidas pendentes aparecem
mesmo assim (a lista pode passar de 20 itens nesse caso extremo, o que é
o comportamento correto: dúvida pendente nunca é cortada).

Nenhum outro método do controller muda.

- [ ] **Step 2: Escrever os testes**

Adicionar ao final da classe em `tests/Feature/AlertaInternoControllerTest.php`:

```php
    public function test_duvidas_nao_respondidas_aparecem_primeiro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        // 3 alertas de outro tipo, mais recentes que a dúvida
        for ($i = 0; $i < 3; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }
        $duvida = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida antiga', 'conteudo' => 'x',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $duvida->id);
    }

    public function test_duvida_pendente_nunca_sai_da_lista_por_volume_de_outros_tipos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        $duvida = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida', 'conteudo' => 'x', 'created_at' => now()->subDays(2),
        ]);
        for ($i = 0; $i < 25; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($duvida->id));
        $this->assertSame($duvida->id, $ids->first());
    }

    public function test_duvida_ja_respondida_nao_conta_como_pendente(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida respondida', 'conteudo' => 'x',
            'resposta' => 'já respondida', 'respondido_em' => now(),
            'created_at' => now()->subDay(),
        ]);
        $recente = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'reassuncao_automatica',
            'titulo' => 'Recente', 'conteudo' => 'x',
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $recente->id);
    }

    public function test_lista_completa_20_com_multiplas_duvidas_pendentes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        for ($i = 0; $i < 5; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
                'titulo' => "Dúvida {$i}", 'conteudo' => 'x',
            ]);
        }
        for ($i = 0; $i < 20; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonCount(20, 'data');
    }
```

- [ ] **Step 3: Rodar os testes**

Run: `php artisan test --filter=AlertaInternoControllerTest`
Expected: PASS (9 testes — 5 já existentes + 4 novos).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Painel/AlertaInternoController.php tests/Feature/AlertaInternoControllerTest.php
git commit -m "feat: painel de alertas prioriza dúvidas não respondidas"
```

---

### Task 3: Campo `tentativas_envio_falhas` em tickets_atendimento

**Files:**
- Create: `database/migrations/2026_08_08_000001_add_tentativas_envio_falhas_to_tickets_atendimento.php`
- Modify: `app/Models/TicketAtendimento.php`
- Modify: `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php` (ou outro arquivo de teste do model — ver Step 2)

**Interfaces:**
- Produces: coluna `tickets_atendimento.tentativas_envio_falhas`
  (integer, default 0). Task 4 lê e escreve esse campo.

- [ ] **Step 1: Escrever a migration**

```php
<?php
// database/migrations/2026_08_08_000001_add_tentativas_envio_falhas_to_tickets_atendimento.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            // Bloco 5 — conta falhas seguidas de "canal recusou o envio"
            // (ex: janela expirada no Covercut). Zerado sempre que uma
            // mensagem é enviada com sucesso. Não incrementa na pausa da
            // Regra 2 (motivo diferente de null) — só nessa falha específica.
            $table->unsignedTinyInteger('tentativas_envio_falhas')->default(0)->after('mensagem_espera_enviada');
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropColumn('tentativas_envio_falhas');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration e atualizar o model**

Run: `php artisan migrate`

Em `app/Models/TicketAtendimento.php`, adicionar `'tentativas_envio_falhas',`
ao `$fillable` (último item, depois de `'mensagem_espera_enviada',`) e
`'tentativas_envio_falhas' => 'integer'` ao array retornado por `casts()`.

- [ ] **Step 3: Escrever o teste (fillable/default)**

Criar `tests/Feature/TicketAtendimentoTentativasEnvioFalhasTest.php`:

```php
<?php
// tests/Feature/TicketAtendimentoTentativasEnvioFalhasTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketAtendimentoTentativasEnvioFalhasTest extends TestCase
{
    use RefreshDatabase;

    public function test_tentativas_envio_falhas_comeca_em_zero(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertSame(0, $ticket->fresh()->tentativas_envio_falhas);
    }

    public function test_tentativas_envio_falhas_e_mass_assignable(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'tentativas_envio_falhas' => 2,
        ]);

        $this->assertSame(2, $ticket->fresh()->tentativas_envio_falhas);
    }
}
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoTentativasEnvioFalhasTest`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_08_000001_add_tentativas_envio_falhas_to_tickets_atendimento.php app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoTentativasEnvioFalhasTest.php
git commit -m "feat: campo tentativas_envio_falhas em tickets_atendimento"
```

---

### Task 4: Teto de tentativas + alerta `envio_falhou`

**Depends on:** Task 3 (`tentativas_envio_falhas`).

**Files:**
- Modify: `app/Services/SdrResponderService.php`
- Modify: `app/Console/Commands/FollowupConversas.php`
- Modify: `tests/Feature/SdrResponderServiceEnvioFalhaTest.php`
- Modify: `tests/Feature/FollowupConversasEstagiosTest.php`

**Interfaces:**
- Produces: tipo de alerta `'envio_falhou'`.
- Consumes: `AlertaInternoService::criar()`.

- [ ] **Step 1: `SdrResponderService` incrementa/zera o contador**

Em `app/Services/SdrResponderService.php`, no bloco "── 5. Enviar pelo canal
certo" (atualmente linhas 170-194):

```php
        // ── 5. Enviar pelo canal certo (Uazapi ou Covercut, resolvido pelo ticket) ──
        $telefone = $ticket->contato?->telefone;
        $canal    = $ticket->canal;

        if ($telefone && $canal) {
            $enviado = $canal->servico()->enviarTexto($canal, $telefone, $resposta);
            if (! $enviado) {
                // Achado Importante 3 da revisão final: um bloqueio determinístico
                // (ex: janela expirada no Covercut) não pode gravar uma Mensagem "bot"
                // no histórico — o lead nunca recebeu, e o FollowupConversas avançaria
                // followup_estagio_enviado achando que a mensagem saiu. Sem persistir e
                // sem mover coluna aqui: melhor a IA tentar de novo no próximo gatilho.
                Log::warning('SdrResponder: envio não confirmado pelo canal, resposta não persistida', [
                    'ticket_id' => $ticket->id, 'canal_id' => $canal->id,
                ]);

                // Bloco 5 — conta falhas seguidas pra dar um teto de tentativas
                // (ver FollowupConversas, que decide quando parar e alertar).
                $ticket->increment('tentativas_envio_falhas');

                return null;
            }

            // Bloco 5 — envio confirmado, zera o contador de falhas seguidas.
            if ($ticket->tentativas_envio_falhas > 0) {
                $ticket->update(['tentativas_envio_falhas' => 0]);
            }
        } else {
            Log::warning('SdrResponder: sem canal ou telefone, mensagem não enviada', [
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'tem_canal' => (bool) $canal,
            ]);
        }
```

Nenhuma outra linha do método muda — a pausa da Regra 2 (`return null;` na
linha ~37 e ~94) continua sem tocar em `tentativas_envio_falhas`.

- [ ] **Step 2: `FollowupConversas` verifica o teto e alerta**

Em `app/Console/Commands/FollowupConversas.php`, dentro do bloco de estágios
(atualmente linhas 139-162), o `if ($estagioAlvo > 0 && ...)` passa a checar
o teto antes de chamar `responder()`:

```php
                if ($estagioAlvo > 0 && $estagioAlvo > $row->followup_estagio_enviado && $config?->ia_ativo) {
                    $ticket ??= TicketAtendimento::withoutGlobalScopes()
                        ->with(['contato', 'mensagens', 'persona', 'tenant'])
                        ->find($row->id);

                    if ($ticket) {
                        // Bloco 5 — depois de 3 falhas seguidas de envio (canal
                        // recusando, ex: janela expirada), para de chamar a IA
                        // pra esse ticket nesse ciclo e alerta uma vez só.
                        if ($ticket->tentativas_envio_falhas >= 3) {
                            $this->line("  ⚠ [envio travado] #{$ticket->id} — {$ticket->contato?->nome}");

                            if (! $dry && $ticket->tentativas_envio_falhas === 3) {
                                try {
                                    app(\App\Services\AlertaInternoService::class)->criar(
                                        $ticket->tenant_id,
                                        'envio_falhou',
                                        'Não consegui entregar a mensagem',
                                        'O canal recusou o envio 3 vezes seguidas (ex: janela de conversa expirada). Parei de tentar automaticamente — confira o ticket.',
                                        $ticket->id,
                                    );
                                    // Sobe pra 4 só pra não repetir o alerta no próximo ciclo
                                    // sem mexer no contador real de falhas do envio em si.
                                    $ticket->increment('tentativas_envio_falhas');
                                } catch (\Exception $e) {
                                    Log::warning('FollowupConversas: erro ao alertar envio travado', [
                                        'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                                    ]);
                                }
                            }
                        } else {
                            $this->line("  ↺ [estágio {$estagioAlvo}] #{$ticket->id} — {$ticket->contato?->nome}");

                            if (! $dry) {
                                try {
                                    $respostaEnviada = $sdr->responder($ticket, gatilho: "estagio_{$estagioAlvo}");
                                    if ($respostaEnviada !== null) {
                                        $ticket->update(['followup_estagio_enviado' => $estagioAlvo]);
                                        $estagiosDisparados[(string) $estagioAlvo]++;
                                        $enviados++;
                                    }
                                } catch (\Exception $e) {
                                    Log::warning('FollowupConversas: erro no estágio', [
                                        'ticket_id' => $row->id, 'estagio' => $estagioAlvo, 'erro' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    }
                }
```

Note o truque do `increment` pra 4 dentro do `if ($ticket->tentativas_envio_falhas === 3)`:
na primeira execução em que o contador chega a exatamente 3, cria o alerta
e sobe o contador pra 4 (só pra marcar "já alertei"); nas execuções
seguintes (`>= 3` mas não mais `=== 3`), continua pulando a chamada da IA
mas não tenta criar o alerta de novo. Isso evita repetir o `envio_falhou`
a cada 5 minutos pra sempre.

O bloco de "Follow-up curto (10min)" (linhas 51-81) e o de "Auto-mover"
(linhas 165-188) não mudam — o teto se aplica só ao ciclo de estágios.
O "Follow-up curto" já é naturalmente limitado pela própria janela de
`whereBetween('ultima.ultima_em', [now()->subMinutes(90), now()->subMinutes(10)])`
(depois de 90min de silêncio o ticket sai do filtro sozinho, com ou sem
falha de envio) — diferente do ciclo de estágios, que não tem um teto de
tempo equivalente e por isso é o único que precisava do contador.

- [ ] **Step 3: Escrever os testes**

Adicionar ao final da classe em `tests/Feature/SdrResponderServiceEnvioFalhaTest.php`:

```php
    public function test_incrementa_tentativas_envio_falhas_quando_canal_recusa(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Aqui está sua resposta.']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'sdr_persona_id' => $persona->id,
            'janela_expira_em' => now()->subHour(),
        ]);

        app(SdrResponderService::class)->responder($ticket);

        $this->assertSame(1, $ticket->fresh()->tentativas_envio_falhas);
    }

    public function test_zera_tentativas_envio_falhas_quando_envio_confirma(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Aqui está sua resposta.']]],
            ], 200),
            // CovercutChannelService::enviar() faz POST em "{base_url}/messages/send"
            // (app/Services/Canais/CovercutChannelService.php:108) — qualquer 2xx aqui
            // já satisfaz $response->successful().
            '*/messages/send' => Http::response(['id' => 'wamid.123'], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'sdr_persona_id' => $persona->id,
            'tentativas_envio_falhas' => 2,
        ]);

        app(SdrResponderService::class)->responder($ticket);

        $this->assertSame(0, $ticket->fresh()->tentativas_envio_falhas);
    }
```

Adicionar a `tests/Feature/FollowupConversasEstagiosTest.php`, reusando o
helper privado `criarTicketComUltimaMensagemHaXMinutos()` já definido nessa
classe (cria tenant/contato/ticket/mensagem/config — 90min de silêncio cai
no estágio 1 com os limites padrão, mesmo cenário do primeiro teste do
arquivo):

```php
    public function test_para_de_tentar_apos_3_falhas_seguidas_e_alerta_uma_vez(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 3]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
        $this->assertDatabaseHas('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'envio_falhou']);
        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'envio_falhou')->count()
        );
    }

    public function test_nao_repete_alerta_envio_falhou_na_proxima_execucao(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 3]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);
        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(
            1,
            \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->where('tipo', 'envio_falhou')->count()
        );
    }

    public function test_menos_de_3_tentativas_ainda_chama_a_ia_normalmente(): void
    {
        $ticket = $this->criarTicketComUltimaMensagemHaXMinutos(90);
        $ticket->update(['tentativas_envio_falhas' => 2]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->once()->andReturn('ok');
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame(1, $ticket->fresh()->followup_estagio_enviado);
        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'envio_falhou']);
    }
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=SdrResponderServiceEnvioFalhaTest`
Expected: PASS (3 testes — 1 já existente + 2 novos).

Run: `php artisan test --filter=FollowupConversasEstagiosTest`
Expected: PASS (todos os testes já existentes + 3 novos, sem regressão).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SdrResponderService.php app/Console/Commands/FollowupConversas.php tests/Feature/SdrResponderServiceEnvioFalhaTest.php tests/Feature/FollowupConversasEstagiosTest.php
git commit -m "feat: teto de 3 tentativas de envio + alerta envio_falhou"
```

---

### Task 5: Campos de timeout da pausa de dúvida

**Files:**
- Create: `database/migrations/2026_08_08_000002_add_duvida_timeout_to_kanban_coluna_configs.php`
- Modify: `app/Models/KanbanColunaConfig.php`
- Modify: `tests/Feature/KanbanColunaConfigFillableTest.php`

**Interfaces:**
- Produces: colunas `kanban_coluna_configs.duvida_timeout_ativo` (boolean,
  default false) e `.duvida_timeout_segundos` (integer, nullable). Task 6
  consome os dois pelo nome exato.

- [ ] **Step 1: Escrever a migration**

```php
<?php
// database/migrations/2026_08_08_000002_add_duvida_timeout_to_kanban_coluna_configs.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            // Bloco 5 — mesmo padrão de timeout_reassuncao_ativo/segundos
            // (Bloco 2): toggle + valor. Se ninguém orientar uma dúvida
            // pausada (Regra 2) dentro desse prazo, o agente retoma sozinho
            // (ver comando conversas:expirar-pausa-orientacao).
            $table->boolean('duvida_timeout_ativo')->default(false)->after('tempo_maximo_permanencia_minutos');
            $table->unsignedInteger('duvida_timeout_segundos')->nullable()->after('duvida_timeout_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['duvida_timeout_ativo', 'duvida_timeout_segundos']);
        });
    }
};
```

- [ ] **Step 2: Rodar a migration e atualizar o model**

Run: `php artisan migrate`

Em `app/Models/KanbanColunaConfig.php`, adicionar `'duvida_timeout_ativo',`
e `'duvida_timeout_segundos',` ao `$fillable` (depois de
`'tempo_maximo_permanencia_minutos',`), e `'duvida_timeout_ativo' => 'boolean'`
ao array `$casts`.

- [ ] **Step 3: Escrever o teste**

Adicionar ao final da classe em `tests/Feature/KanbanColunaConfigFillableTest.php`:

```php
    public function test_duvida_timeout_e_mass_assignable(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $config = \App\Models\KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        $this->assertTrue($config->fresh()->duvida_timeout_ativo);
        $this->assertSame(1800, $config->fresh()->duvida_timeout_segundos);
    }
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=KanbanColunaConfigFillableTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_08_000002_add_duvida_timeout_to_kanban_coluna_configs.php app/Models/KanbanColunaConfig.php tests/Feature/KanbanColunaConfigFillableTest.php
git commit -m "feat: campos duvida_timeout_ativo/segundos por coluna (Regra 2)"
```

---

### Task 6: Comando `conversas:expirar-pausa-orientacao`

**Depends on:** Task 5 (`duvida_timeout_ativo`/`duvida_timeout_segundos`).

**Files:**
- Modify: `app/Services/AlertaInternoService.php`
- Create: `app/Console/Commands/ExpirarPausaOrientacao.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/ExpirarPausaOrientacaoTest.php`

**Interfaces:**
- Produces: `AlertaInternoService::fecharDuvidaPendente(int $tenantId, int $ticketId, string $motivo): void`
  — Task 7 também usa esse método.

- [ ] **Step 1: Adicionar o helper de fechar alerta ao `AlertaInternoService`**

Em `app/Services/AlertaInternoService.php`, adicionar um método novo (mantém
o `criar()` existente intocado):

```php
<?php
// app/Services/AlertaInternoService.php
namespace App\Services;

use App\Models\AlertaInterno;
use Illuminate\Support\Str;

class AlertaInternoService
{
    public function criar(
        int $tenantId,
        string $tipo,
        string $titulo,
        string $conteudo,
        ?int $ticketId = null,
    ): AlertaInterno {
        return AlertaInterno::create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticketId,
            'tipo'      => Str::limit($tipo, 50, ''),
            'titulo'    => Str::limit($titulo, 150, ''),
            'conteudo'  => $conteudo,
        ]);
    }

    /**
     * Bloco 5 — fecha o alerta de dúvida pendente (tipo 'duvida_ia', sem
     * resposta ainda) de um ticket, sem exigir uma resposta real do humano.
     * Usado quando a pausa termina por outro motivo que não uma orientação
     * de verdade: timeout (ExpirarPausaOrientacao) ou mudança de coluna
     * (TicketAtendimento::updating()). Mesma query que KanbanController::orientar()
     * já usa pra achar o alerta certo. Sem-efeito se não houver alerta
     * pendente (idempotente — seguro chamar mesmo sem ter certeza que existe).
     */
    public function fecharDuvidaPendente(int $tenantId, int $ticketId, string $motivo): void
    {
        AlertaInterno::where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->where('tipo', 'duvida_ia')
            ->whereNull('resposta')
            ->latest('id')
            ->first()
            ?->update(['resposta' => $motivo, 'respondido_em' => now()]);
    }
}
```

- [ ] **Step 2: Escrever o comando**

```php
<?php
// app/Console/Commands/ExpirarPausaOrientacao.php
namespace App\Console\Commands;

use App\Models\KanbanColunaConfig;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExpirarPausaOrientacao extends Command
{
    protected $signature = 'conversas:expirar-pausa-orientacao
                            {--dry-run : Mostra o que faria sem alterar nada}';

    protected $description = 'Reassume automaticamente tickets pausados aguardando orientação (Regra 2) além do timeout configurado por coluna, fechando o alerta pendente';

    public function handle(AlertaInternoService $alertaService): int
    {
        $dry = $this->option('dry-run');
        $expirados = 0;

        $candidatos = TicketAtendimento::withoutGlobalScopes()
            ->with('contato')
            ->whereNotNull('aguardando_orientacao_em')
            ->get(['id', 'tenant_id', 'coluna_kanban', 'aguardando_orientacao_em', 'contato_id']);

        foreach ($candidatos as $ticket) {
            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->first();

            if (! $config?->duvida_timeout_ativo) {
                continue;
            }

            $timeoutSegundos = $config->duvida_timeout_segundos ?? 3600;
            $esperandoSegundos = now()->diffInSeconds(Carbon::parse($ticket->aguardando_orientacao_em), absolute: true);

            if ($esperandoSegundos < $timeoutSegundos) {
                continue;
            }

            // Reconfere antes de agir — mesmo padrão defensivo do ReassumirAgente
            // (achado 3 da revisão final do Bloco 2): o humano pode ter orientado
            // entre a query e agora.
            $atual = TicketAtendimento::withoutGlobalScopes()->find($ticket->id);
            if (! $atual || ! $atual->aguardando_orientacao_em) {
                continue;
            }

            $this->line("  ⏱ [expirou] #{$ticket->id} — {$ticket->contato?->nome}");

            if ($dry) {
                continue;
            }

            try {
                $alertaService->fecharDuvidaPendente(
                    $ticket->tenant_id,
                    $ticket->id,
                    'Não respondido a tempo — retomado automaticamente.',
                );

                $atual->update([
                    'aguardando_orientacao_em' => null,
                    'mensagem_espera_enviada'  => false,
                ]);

                $expirados++;
            } catch (\Exception $e) {
                Log::warning('ExpirarPausaOrientacao: erro ao expirar pausa', [
                    'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Pausas expiradas: {$expirados}");
        if ($dry) {
            $this->warn('DRY-RUN — nada foi alterado.');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Registrar no agendador**

Em `routes/console.php`, adicionar depois do bloco de
`conversas:reassumir-agente` (mesma cadência, mesmo lugar lógico):

```php
// A cada 5 min — Expira pausas de dúvida (Regra 2) não respondidas a tempo
Schedule::command('conversas:expirar-pausa-orientacao')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/expirar-pausa-orientacao.log'));
```

- [ ] **Step 4: Escrever os testes**

```php
<?php
// tests/Feature/ExpirarPausaOrientacaoTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpirarPausaOrientacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketPausado(Carbon $pausadoEm, string $coluna = 'em_atendimento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcos']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => $pausadoEm,
            'mensagem_espera_enviada' => true,
        ]);
    }

    public function test_expira_pausa_alem_do_timeout_e_fecha_o_alerta(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);
        $alerta = AlertaInterno::create([
            'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Dúvida', 'conteudo' => 'x',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00')); // 35min depois

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $ticket->refresh();
        $this->assertNull($ticket->aguardando_orientacao_em);
        $this->assertFalse($ticket->mensagem_espera_enviada);

        $alerta->refresh();
        $this->assertNotNull($alerta->resposta);
        $this->assertNotNull($alerta->respondido_em);
    }

    public function test_nao_expira_antes_do_timeout(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:20:00')); // 20min depois

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_coluna_sem_timeout_configurado_nunca_expira(): void
    {
        $ticket = $this->criarTicketPausado(now());
        // Sem KanbanColunaConfig nenhuma pra essa coluna.

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_ticket_nao_pausado_nao_e_candidato(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 60,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 11:00:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertSame('bot', $ticket->fresh()->agente_responsavel);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00'));

        $this->artisan('conversas:expirar-pausa-orientacao --dry-run')->assertExitCode(0);

        $this->assertNotNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_reassuncao_e_silenciosa_nenhuma_mensagem_enviada_ao_lead(): void
    {
        $ticket = $this->criarTicketPausado(now());
        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'em_atendimento',
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        $this->mock(\App\Services\SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        Carbon::setTestNow(Carbon::parse('2026-08-08 10:35:00'));

        $this->artisan('conversas:expirar-pausa-orientacao')->assertExitCode(0);

        $this->assertSame(0, \App\Models\Mensagem::where('ticket_id', $ticket->id)->count());
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=ExpirarPausaOrientacaoTest`
Expected: PASS (7 testes).

- [ ] **Step 6: Commit**

```bash
git add app/Services/AlertaInternoService.php app/Console/Commands/ExpirarPausaOrientacao.php routes/console.php tests/Feature/ExpirarPausaOrientacaoTest.php
git commit -m "feat: comando conversas:expirar-pausa-orientacao — timeout da Regra 2"
```

---

### Task 7: Fecha alerta órfão na troca de coluna

**Depends on:** Task 6 (`AlertaInternoService::fecharDuvidaPendente()`).

**Files:**
- Modify: `app/Models/TicketAtendimento.php`
- Modify: `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php` (ou
  outro arquivo de teste do model já existente que cubra o hook `updating()`
  — ver Step 2)

**Interfaces:** nenhuma nova — só chama `AlertaInternoService::fecharDuvidaPendente()`
(Task 6).

- [ ] **Step 1: Atualizar o hook `updating()`**

Em `app/Models/TicketAtendimento.php`, o bloco que já reseta
`aguardando_orientacao_em` na troca de coluna (atualmente linhas 69-76):

```php
            // Regra 2 (Bloco 3): uma dúvida pausada é específica do contexto da
            // coluna atual — se o ticket muda de coluna enquanto aguarda
            // orientação (manual ou automático), a pausa não faz mais sentido.
            // Mesmo raciocínio do reset de objetivos_cumpridos acima.
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('aguardando_orientacao_em')) {
                // Bloco 5 — fecha o alerta pendente ANTES de limpar o campo
                // abaixo, senão a pausa "desapareceria" sem deixar rastro de
                // por que o alerta nunca foi respondido de verdade.
                if ($ticket->aguardando_orientacao_em) {
                    app(\App\Services\AlertaInternoService::class)->fecharDuvidaPendente(
                        $ticket->tenant_id,
                        $ticket->id,
                        'Mudou de coluna antes de receber orientação — pausa descartada.',
                    );
                }

                $ticket->aguardando_orientacao_em = null;
                $ticket->mensagem_espera_enviada  = false;
            }
```

- [ ] **Step 2: Escrever os testes**

Adicionar ao final da classe em
`tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`:

```php
    public function test_mudar_de_coluna_com_pausa_pendente_fecha_o_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        $ticket->update(['aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => true]);
        $alerta = \App\Models\AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Dúvida', 'conteudo' => 'x',
        ]);

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $alerta->refresh();
        $this->assertNotNull($alerta->resposta);
        $this->assertNotNull($alerta->respondido_em);
        $this->assertNull($ticket->fresh()->aguardando_orientacao_em);
    }

    public function test_mudar_de_coluna_sem_pausa_pendente_nao_mexe_em_alerta_nenhum(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo');
        // Ticket nunca foi pausado — sem aguardando_orientacao_em, sem alerta.

        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertSame(0, \App\Models\AlertaInterno::where('ticket_id', $ticket->id)->count());
    }
```

(Reusa o helper `criarTicket()` já definido nessa classe pelas Tasks 4/5 do
Bloco 4.)

- [ ] **Step 3: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoOrigemMudancaColunaTest`
Expected: PASS (todos os já existentes + 2 novos, sem regressão).

- [ ] **Step 4: Commit**

```bash
git add app/Models/TicketAtendimento.php tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
git commit -m "fix: fecha alerta de dúvida pendente ao mudar de coluna antes de orientar"
```

---

### Task 8: UI — config do timeout da pausa de dúvida

**Depends on:** Task 5 (`duvida_timeout_ativo`/`duvida_timeout_segundos`).

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanColunaConfigController.php`
- Modify: `resources/views/kanban/config.blade.php`
- Create: `tests/Feature/KanbanColunaConfigDuvidaTimeoutTest.php`

**Interfaces:** nenhuma nova — expõe os campos da Task 5 via API existente.

- [ ] **Step 1: Expor os campos no `show()`**

Em `app/Http/Controllers/Painel/KanbanColunaConfigController.php`, no
`show()`, adicionar (depois de `'tempo_maximo_permanencia_minutos'`):

```php
            'duvida_timeout_ativo'    => $config?->duvida_timeout_ativo    ?? false,
            'duvida_timeout_segundos' => $config?->duvida_timeout_segundos ?? 3600,
```

- [ ] **Step 2: Validar e persistir no `update()`**

No `update()`, adicionar às regras de validação (depois de
`'tempo_maximo_permanencia_minutos'`):

```php
            'duvida_timeout_ativo'    => 'sometimes|boolean',
            'duvida_timeout_segundos' => 'sometimes|integer|min:60|max:604800',
```

Mesmo padrão de `timeout_reassuncao_*` — nenhuma mudança no
`array_filter`/`updateOrCreate` (esses dois campos não precisam do
tratamento especial de null que `tempo_maximo_permanencia_minutos` tem,
porque "desligado" aqui é representado pelo toggle `false`, não por um
valor nulo).

- [ ] **Step 3: Adicionar o campo na config da coluna (Alpine.js)**

Em `resources/views/kanban/config.blade.php`, quatro alterações:

**3a.** No objeto de dados do componente Alpine, logo depois de
`tempoMaximoPermanenciaMinutos: {},` (por volta da linha 1461):

```javascript
        // Timeout da pausa de dúvida (Regra 2) — mesmo padrão de
        // timeoutReassuncao* acima.
        duvidaTimeoutAtivo: {},
        duvidaTimeoutDelay: {},
        duvidaTimeoutDelayUnidade: {},
```

**3b.** Na função que carrega os dados do servidor, logo depois da linha
`this.tempoMaximoPermanenciaMinutos[key] = json.tempo_maximo_permanencia_minutos ?? null;`
(por volta da linha 1925):

```javascript
                this.duvidaTimeoutAtivo[key] = json.duvida_timeout_ativo ?? false;
                const dt = this.segundosParaDisplay(json.duvida_timeout_segundos ?? 3600);
                this.duvidaTimeoutDelay[key]        = dt.valor;
                this.duvidaTimeoutDelayUnidade[key] = dt.unidade;
```

(`segundosParaDisplay()` já existe no componente — mesma função usada pelo
timeout de reassunção.)

**3c.** No payload de salvamento, logo depois da linha
`tempo_maximo_permanencia_minutos: this.tempoMaximoPermanenciaMinutos[key] || null,`
(por volta da linha 1983):

```javascript
                duvida_timeout_ativo:    this.duvidaTimeoutAtivo[key] ?? false,
                duvida_timeout_segundos: this.delayParaSegundos(this.duvidaTimeoutDelay[key] ?? 1, this.duvidaTimeoutDelayUnidade[key] || 'hora'),
```

(`delayParaSegundos()` também já existe — mesma função usada pelo timeout
de reassunção.)

**3d.** No HTML, logo depois do bloco "Reassumir automaticamente após
silêncio do atendente" existente (termina com `</div>` na linha 953,
imediatamente antes do bloco "Mensagem de espera durante orientação"),
adicionar um bloco no mesmo padrão visual:

```html
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <label class="flex items-center gap-2 mb-2 cursor-pointer">
                                    <input type="checkbox"
                                           :checked="duvidaTimeoutAtivo[col.key]"
                                           @change="duvidaTimeoutAtivo[col.key] = $event.target.checked; iaAlterado[col.key] = true"
                                           class="w-3.5 h-3.5 accent-amber-600">
                                    <span class="text-xs font-semibold text-gray-500">Retomar automaticamente se ninguém orientar (Regra 2)</span>
                                </label>

                                <template x-if="duvidaTimeoutAtivo[col.key]">
                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <span class="text-xs text-gray-500">Depois de</span>
                                        <input type="number" min="1"
                                               :value="duvidaTimeoutDelay[col.key] ?? 1"
                                               @input="duvidaTimeoutDelay[col.key] = parseInt($event.target.value) || 0; iaAlterado[col.key] = true"
                                               class="w-14 text-xs border border-gray-300 rounded px-2 py-1">
                                        <select :value="duvidaTimeoutDelayUnidade[col.key] || 'hora'"
                                                @change="duvidaTimeoutDelayUnidade[col.key] = $event.target.value; iaAlterado[col.key] = true"
                                                class="text-xs border border-gray-300 rounded px-1.5 py-1 bg-white text-gray-700">
                                            <option value="seg">seg</option>
                                            <option value="min">min</option>
                                            <option value="hora">hora</option>
                                        </select>
                                        <span class="text-xs text-gray-500">sem resposta, o agente retoma sozinho</span>
                                    </div>
                                </template>
                                <div class="mt-2 p-3 bg-amber-50 border border-amber-200 rounded-xl">
                                    <p class="text-xs font-semibold text-amber-800 mb-1">Como configurar</p>
                                    <p class="text-xs text-amber-700 leading-relaxed">
                                        Se o agente pausar aguardando sua orientação (Regra 2) e ninguém responder o
                                        alerta dentro desse prazo, ele retoma o atendimento sozinho, sem mandar
                                        nenhuma mensagem pro lead — o alerta é fechado automaticamente. Roda a
                                        cada 5 minutos, sem restrição de horário.
                                    </p>
                                </div>
                            </div>
```

- [ ] **Step 4: Escrever o teste de API**

```php
<?php
// tests/Feature/KanbanColunaConfigDuvidaTimeoutTest.php
namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigDuvidaTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_retorna_defaults_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/coluna-config/lead_novo');

        $response->assertOk();
        $response->assertJson(['duvida_timeout_ativo' => false, 'duvida_timeout_segundos' => 3600]);
    }

    public function test_update_salva_o_timeout_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/coluna-config/em_atendimento', [
            'duvida_timeout_ativo' => true, 'duvida_timeout_segundos' => 1800,
        ]);

        $response->assertOk();
        $config = KanbanColunaConfig::where('tenant_id', $tenant->id)->where('coluna_kanban', 'em_atendimento')->first();
        $this->assertTrue($config->duvida_timeout_ativo);
        $this->assertSame(1800, $config->duvida_timeout_segundos);
    }
}
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=KanbanColunaConfigDuvidaTimeoutTest`
Expected: PASS (2 testes).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanColunaConfigController.php resources/views/kanban/config.blade.php tests/Feature/KanbanColunaConfigDuvidaTimeoutTest.php
git commit -m "feat: UI de config do timeout da pausa de dúvida (Regra 2)"
```

---

### Task 9: `origem` 'sistema' + guardrail de salto distingue IA de sistema

**Files:**
- Modify: `app/Models/TicketAtendimento.php`
- Modify: `app/Services/SdrResponderService.php`
- Modify: `tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`

**Interfaces:**
- Muda o default de `origem` gravado em `kanban_coluna_historico` quando
  `$origemMudancaColuna` não está setado: de `'ia'` pra `'sistema'`.
- `SdrResponderService` passa a setar `$ticket->origemMudancaColuna = 'ia';`
  explicitamente no ponto onde processa o token de coluna.

- [ ] **Step 1: Mudar o default no hook `updated()`**

Em `app/Models/TicketAtendimento.php`, no `static::updated()`:

```php
        static::updated(function (TicketAtendimento $ticket) {
            if ($ticket->wasChanged('coluna_kanban')) {
                $colunaAnterior = $ticket->getOriginal('coluna_kanban');
                // Bloco 5 — default agora é 'sistema' (política automática:
                // auto-mover, webhook, botões), não 'ia'. 'ia' só é gravado
                // quando SdrResponderService marca explicitamente, no único
                // ponto onde a própria IA decide mover a coluna em tempo
                // real (ver SdrResponderService.php, token de movimento).
                $origem = $ticket->origemMudancaColuna ?? 'sistema';
```

(o resto do método não muda — só essa linha do fallback.)

- [ ] **Step 2: `SdrResponderService` marca origem 'ia' explicitamente**

Em `app/Services/SdrResponderService.php`, no bloco "── 4. Detectar token de
movimento de coluna" (atualmente linhas 104-127):

```php
        $moveu = false;
        foreach ($chaves as $chave) {
            $token = '[' . mb_strtoupper($chave) . ']';

            if (str_contains($resposta, $token)) {
                $etapa = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('coluna_kanban', $chave)
                    ->value('etapa_ia_ao_mover') ?? 'etapa_1';

                $papel   = \App\Models\KanbanColuna::papelDe($tenantId, $chave);
                $updates = $papel === \App\Enums\PapelColunaKanban::Encerramento
                    ? $ticket->dadosParaEncerrar(['etapa_ia' => $etapa], $chave)
                    : ['coluna_kanban' => $chave, 'etapa_ia' => $etapa];
                // objetivos_cumpridos é zerado automaticamente pelo hook do model
                // (TicketAtendimento::saving) sempre que coluna_kanban muda e este
                // update não o define explicitamente — ver Achado 2 da revisão final.

                // Bloco 5 — este é o único ponto do sistema onde a própria IA
                // decide mover a coluna em tempo real (não política automática
                // de outro comando/webhook) — marca 'ia' explicitamente pro
                // guardrail de salto (Regra 13) saber diferenciar os dois casos.
                $ticket->origemMudancaColuna = 'ia';
                $ticket->update($updates);
                Log::info("SdrResponder: → {$chave} via token {$token}", ['ticket_id' => $ticket->id]);
                $moveu = true;
                break;
            }
        }
```

- [ ] **Step 3: Atualizar o guardrail de salto**

Em `app/Models/TicketAtendimento.php`, no método `alertarSeMigracaoAtipica()`,
mudar só o cálculo de `$pulou`:

```php
        $pulou = $ordemAntes !== null && $ordemDepois !== null
            && abs($ordemDepois - $ordemAntes) > 1
            // Bloco 5 — a exclusão de colunas fora da ordem normal (Encerramento/
            // TransferenciaHumana) vale só quando a origem é 'sistema' (política
            // automática de alta frequência: auto-mover, webhook, botões).
            // Origem 'ia' (decisão real da própria IA em tempo real, via token)
            // volta a contar mesmo envolvendo essas colunas — é raro e vale o
            // glance de auditoria, mesmo que a maioria das vezes seja legítimo.
            && ! ($origem === 'sistema' && ($papelForaDaOrdem($papelAntes) || $papelForaDaOrdem($papelDepois)));
```

Atualizar também o docblock do método (linhas 80-105 aproximadamente) —
o parágrafo final que hoje diz "Essa exclusão vale só pro cálculo de
$pulou — uma movimentação manual (origem 'humano') continua alertando
independente do papel envolvido" passa a dizer:

```php
     * Colunas de papel Encerramento ou TransferenciaHumana não fazem parte
     * da ordem "normal" do funil — são desvios de fluxo, não etapas
     * sequenciais. Fluxos automáticos de altíssima frequência passam por
     * elas rotineiramente e produzem distância ordinal grande sem que isso
     * seja uma migração atípica de verdade: encerramento automático por
     * silêncio (FollowupConversas) pula de qualquer coluna intermediária
     * direto pro Encerramento, e reabertura de ticket (webhooks Uazapi/
     * Covercut) volta do Encerramento pra uma coluna bem anterior. Contar
     * esses saltos geraria ruído puro em operação normal, contrariando a
     * decisão de produto de só alertar o que é de fato incomum. Essa
     * exclusão vale só pra origem 'sistema' (Bloco 5) — origem 'ia'
     * (decisão real da própria IA em tempo real, via token) e origem
     * 'humano' continuam alertando independente do papel envolvido.
```

- [ ] **Step 4: Escrever os testes**

Adicionar ao final da classe em
`tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php`:

```php
    public function test_movimento_automatico_sem_origem_marcada_grava_sistema(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant);

        // Simula um caminho automático (ex: FollowupConversas, webhook) —
        // nenhum código chama origemMudancaColuna, então cai no novo default.
        $ticket->update(['coluna_kanban' => 'em_atendimento']);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'em_atendimento', 'origem' => 'sistema',
        ]);
    }

    public function test_token_de_coluna_no_sdr_responder_service_grava_origem_ia(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Combinado! [PAGAMENTO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('kanban_coluna_historico', [
            'ticket_id' => $ticket->id, 'coluna' => 'pagamento', 'origem' => 'ia',
        ]);
    }

    public function test_sistema_pulando_para_encerrado_continua_sem_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $ticket = $this->criarTicket($tenant, 'lead_novo'); // ordem 1

        // origemMudancaColuna não setada — cai em 'sistema' (ex: auto-mover).
        $ticket->update(['coluna_kanban' => 'encerrado']); // papel Encerramento, salto grande

        $this->assertDatabaseMissing('alertas_internos', ['ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica']);
    }

    public function test_ia_via_token_pulando_para_encerrado_agora_alerta(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'openrouter.ai/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [['message' => ['content' => 'Tudo bem, até mais! [ENCERRADO]']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = \App\Models\Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);

        app(\App\Services\SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('alertas_internos', [
            'ticket_id' => $ticket->id, 'tipo' => 'migracao_atipica',
        ]);
    }
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=TicketAtendimentoOrigemMudancaColunaTest`
Expected: PASS (todos os já existentes + 4 novos — atenção: os testes já
existentes que checavam `origem === 'ia'` pra movimento automático
[`test_mudanca_de_coluna_sem_marcar_origem_grava_ia_por_padrao`,
`test_movimento_adjacente_pela_ia_nao_gera_alerta`, etc., do Bloco 4]
precisam ser corrigidos nesta task pra esperar `'sistema'` em vez de
`'ia'`, já que o default mudou — ler cada um antes de rodar e ajustar a
asserção de `origem` onde o teste não usa `SdrResponderService` de verdade
[só chama `$ticket->update(['coluna_kanban' => ...])` direto, que agora
cai no novo default]).

Run: `php artisan test --filter=SdrResponderService`
Expected: PASS, sem regressão (o `test_movimento_manual_com_salto_gera_apenas_um_alerta`
e similares do Bloco 4 continuam válidos porque testam origem `'humano'`,
inalterada).

- [ ] **Step 6: Commit**

```bash
git add app/Models/TicketAtendimento.php app/Services/SdrResponderService.php tests/Feature/TicketAtendimentoOrigemMudancaColunaTest.php
git commit -m "feat: origem 'sistema' como default + guardrail de salto distingue IA de sistema (Regra 13)"
```

---

## Depois da Task 9

Rodar a suíte inteira (`php artisan test`) e confirmar que nenhum teste
pré-existente quebrou (exceto o `ExampleTest` flaky já conhecido, e
possivelmente `SequenciaServiceJitterTest`, ambos sem relação com este
bloco). Prestar atenção especial aos testes do Bloco 4 que dependiam do
default `'ia'` (ver nota da Task 9, Step 5) — se algum outro teste fora de
`TicketAtendimentoOrigemMudancaColunaTest.php` também assumir esse default
(buscar por `'origem' => 'ia'` e `origem.*ia` na suíte inteira antes de
considerar a task fechada), corrigir do mesmo jeito. Seguir pra revisão
final de branch inteira (opus) e pro fluxo de
`superpowers:finishing-a-development-branch`, mesmo padrão dos blocos
anteriores.
