# Mídia no Canal Oficial (Covercut) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Processar áudio (transcrição) e imagem (descrição + itens) recebidos pelo canal WhatsApp Oficial (Covercut) — hoje descartados silenciosamente, causando buracos no histórico da conversa que fazem a IA responder fora de contexto.

**Architecture:** Adiciona a `MediaProcessorService` (existente, usado hoje só pelo Uazapi) um conjunto paralelo de métodos "Oficial" que buscam a mídia via API da Covercut (`GET /media/get?mode=stream` — a Meta já entrega descriptografado, sem precisar replicar a descriptografia E2E do Uazapi) mas **reaproveitam as mesmas funções de IA já existentes** (`transcreverAudioBase64`, `descreverImagemComVisao`). `CovercutWebhookController` é restruturado uma única vez (Task 1) pra resolver o ticket ANTES de processar a mídia (precisa de `coluna_kanban` pro foco de análise de imagem), depois só ganha branches novos (Tasks 2-3). 100% aditivo — nenhum método/comportamento do Uazapi muda, exceto uma extração seelseif eíntese de helper privado sem efeito externo (Task 2).

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit clássico (`test_*`, sem Pest), `Http::fake()` para mockar a Covercut, `Storage::fake('public')` para mockar persistência de mídia.

## Global Constraints

- Nunca fazer deploy manual via SSH — sempre `git commit` local + `./deploy.sh`.
- Credenciais Covercut são globais (`.env`/`config('services.covercut.*')`), nunca por tenant — já configuradas (`base_url`, `api_key`, `api_secret`).
- `WhatsappCanal.config['phone_number_id']` já existe e resolve o número (confirmado em `CovercutChannelService.php:37`).
- Download de mídia **sempre** usa `&mode=stream` (corpo = bytes brutos, mime no header `Content-Type`) — nunca tentar parsear um envelope JSON/base64 (formato não documentado, ver design).
- Qualquer falha ao processar mídia (payload inesperado, download falhou) deve **logar e seguir** — nunca lançar exceção que derruba o webhook inteiro (mesmo padrão já usado em `UazapiWebhookController::processarMensagemLead`, `try/catch (\Throwable $e)` ao redor da chamada de `MediaProcessorService`).
- `mensagens.tipo` é enum `['texto', 'imagem', 'audio', 'video', 'documento']` — mas o código do Uazapi (`UazapiWebhookController.php:296-298`) nunca usa o valor `'documento'` de fato, mapeia documento pra `'texto'`. Manter a mesma convenção aqui (paridade exata, não introduzir uso novo do enum).
- Testes seguem o estilo de `tests/Feature/UazapiWebhookMidiaTest.php` e `tests/Feature/CovercutWebhookControllerTest.php`: `Http::fake()`, `Storage::fake('public')`, POST no endpoint do webhook, assert em `Mensagem`/`TicketAtendimento`. Nenhuma chamada real à Covercut ou à OpenRouter/Groq em teste (as chaves `openrouter.key`/`groq.key` não são configuradas no ambiente de teste, então as funções de IA já retornam early — comportamento existente, não precisa mockar).

---

### Task 1: Restruturar `CovercutWebhookController` + processar ÁUDIO

**Files:**
- Modify: `app/Services/MediaProcessorService.php`
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php`
- Modify: `tests/Feature/CovercutWebhookControllerTest.php` (import `Storage` facade, `Storage::fake('public')` no `setUp()` se ainda não tiver — ver Step 5)
- Test: `tests/Feature/CovercutWebhookMidiaTest.php` (novo)

**Interfaces:**
- Produces: `MediaProcessorService::processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string` (dispatcher — só trata `text`/`audio` nesta task, `image`/`video`/`document` caem no `default => null`, tratados nas Tasks 2-3), `MediaProcessorService::baixarEPersistirUrlOficial(array $message, WhatsappCanal $canal, string $mediaType): ?string`.
- Consumes: `transcreverAudioBase64(string $base64, string $mime): ?string` e `salvarBytes(string $bytes, string $mime, string $mediaType): string` — métodos privados já existentes em `MediaProcessorService`, sem nenhuma alteração de assinatura.

- [ ] **Step 1: Adicionar os métodos novos em `MediaProcessorService`**

Adicionar estes métodos na classe (qualquer posição depois do construtor, ex. logo antes do fechamento `}` final da classe):

```php
    // -------------------------------------------------------------------------
    // Canal Oficial (Covercut) — a Meta já entrega mídia descriptografada, sem
    // precisar replicar a descriptografia E2E do WhatsApp (usada só no Uazapi).
    // -------------------------------------------------------------------------

    /**
     * Processa mensagem de mídia recebida pelo canal Oficial (Covercut) e retorna
     * texto descritivo pro contexto do bot. Retorna null se o tipo não é
     * processado (ainda) ou a mídia não pôde ser buscada/analisada.
     */
    public function processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string
    {
        $tipo = $message['type'] ?? null;

        return match ($tipo) {
            'audio' => $this->processarAudioOficial($message, $canal),
            default => null,
        };
    }

    private function processarAudioOficial(array $message, WhatsappCanal $canal): string
    {
        if (! $this->groqKey) {
            return '[Áudio recebido — transcrição não configurada]';
        }

        $mediaId = $message['audio']['id'] ?? null;
        if (! $mediaId) {
            Log::warning('MediaProcessor: payload de áudio oficial sem audio.id', ['message' => $message]);
            return '[Áudio recebido — não foi possível identificar o arquivo]';
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return '[Áudio recebido — não foi possível transcrever]';
        }

        $transcricao = $this->transcreverAudioBase64(base64_encode($midia['bytes']), $midia['mime']);

        return $transcricao
            ? "[Áudio transcrito: {$transcricao}]"
            : '[Áudio recebido — não foi possível transcrever]';
    }

    /**
     * Baixa mídia recebida pelo canal Oficial via Covercut e salva permanentemente
     * em storage/public, retornando uma URL própria (mesmo padrão de
     * baixarEPersistirUrl(), usado pelo Uazapi) — pra exibir no card do Kanban.
     * Retorna null se não conseguir baixar.
     */
    public function baixarEPersistirUrlOficial(array $message, WhatsappCanal $canal, string $mediaType): ?string
    {
        $mediaId = $message[$mediaType]['id'] ?? null;
        if (! $mediaId) {
            return null;
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return null;
        }

        return $this->salvarBytes($midia['bytes'], $midia['mime'], $mediaType);
    }

    /**
     * Busca os bytes de uma mídia via API da Covercut. Usa sempre mode=stream —
     * o corpo da resposta é o arquivo bruto, mime-type no header Content-Type —
     * evita ter que adivinhar o formato de um envelope JSON/base64 não
     * documentado (ver design técnico, seção 3.1).
     * Retorna ['bytes' => string, 'mime' => string] ou null em qualquer falha.
     */
    private function baixarMidiaCovercut(string $mediaId, WhatsappCanal $canal): ?array
    {
        $baseUrl       = config('services.covercut.base_url');
        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $baseUrl || ! $phoneNumberId) {
            Log::warning('MediaProcessor: canal oficial sem base_url/phone_number_id', ['canal_id' => $canal->id]);
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])->timeout(30)->get("{$baseUrl}/media/get", [
                'id'   => $mediaId,
                'from' => $phoneNumberId,
                'mode' => 'stream',
            ]);

            if (! $response->successful()) {
                Log::warning('MediaProcessor: falha ao baixar mídia da Covercut', [
                    'media_id' => $mediaId,
                    'status'   => $response->status(),
                    'body'     => substr($response->body(), 0, 300),
                ]);
                return null;
            }

            return [
                'bytes' => $response->body(),
                'mime'  => $response->header('Content-Type') ?: 'application/octet-stream',
            ];
        } catch (\Exception $e) {
            Log::error('MediaProcessor: exceção ao baixar mídia da Covercut', [
                'media_id' => $mediaId, 'erro' => $e->getMessage(),
            ]);
            return null;
        }
    }
```

Adicionar o import no topo do arquivo (junto dos outros `use`):

```php
use App\Models\WhatsappCanal;
```

- [ ] **Step 2: Restruturar `CovercutWebhookController::processarMensagem()`**

Substituir o método inteiro (linhas atuais ~81-175) por esta versão — a mudança principal é mover a resolução do contato/ticket pra ANTES de derivar `$conteudo`, porque a Task 2 (imagem) precisa de `$ticket->coluna_kanban` pra buscar `foco_analise_imagem`. Nesta task, `image`/`video`/`document` ainda caem no mesmo log de "ignorado" de hoje (comportamento inalterado pra esses tipos — só `audio` passa a ser processado):

```php
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
        $pushName = $payload['contact']['name'] ?? null;

        $temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
        $janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']);

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

        // `message.text` chega como STRING simples no payload real da Covercut
        // (ex.: "text": "Ola"), não como objeto `{body: ...}` — o formato Meta
        // Cloud API "cru" seria `text.body`, então a leitura tolera os dois.
        $tipo         = $payload['message']['type'] ?? null;
        $conteudo     = null;
        $tipoMensagem = 'texto';
        $midiaUrl     = null;

        if ($tipo === 'text') {
            $conteudo = $payload['message']['text']['body'] ?? ($payload['message']['text'] ?? null);
        } elseif ($tipo === 'audio') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
                if ($conteudo !== null) {
                    $tipoMensagem = 'audio';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'audio');
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar áudio', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        }

        if (! $conteudo) {
            // MVP: image/video/document ainda não têm tratamento (ver Tasks 2-3).
            Log::info('Covercut webhook: mensagem não-texto ignorada (MVP)', [
                'message_id' => $messageId,
                'type'       => $tipo,
            ]);
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'            => $ticket->id,
                'tenant_id'            => $tenant->id,
                'remetente'            => 'lead',
                'tipo'                 => $tipoMensagem,
                'conteudo'             => $conteudo,
                'midia_url'            => $midiaUrl,
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
```

Adicionar o import no topo do arquivo:

```php
use App\Services\MediaProcessorService;
```

- [ ] **Step 3: Rodar a suíte existente do Covercut pra confirmar que a restruturação não quebrou nada**

Run (via PowerShell): `php artisan test --filter=CovercutWebhookControllerTest`
Expected: todos os testes já existentes continuam passando (o teste `test_mensagem_inbound_nao_textual_e_logada_e_ignorada` usa `type: 'image'`, que ainda cai no log de ignorado nesta task — permanece válido até a Task 2).

- [ ] **Step 4: Escrever o teste (falhando) pro processamento de áudio**

Criar `tests/Feature/CovercutWebhookMidiaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CovercutWebhookMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Bus::fake();
        config([
            'services.covercut.base_url'   => 'https://fake-covercut.test/api/v1',
            'services.covercut.api_key'    => 'fake-key',
            'services.covercut.api_secret' => 'fake-secret',
        ]);
    }

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->withHeaders(['X-BSP-Signature' => $assinatura])
            ->call('POST', '/api/webhook/covercut', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], $body);
    }

    public function test_audio_recebido_e_transcrito_e_salvo_com_midia_url(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-audio', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.audio1', 'type' => 'audio', 'audio' => ['id' => 'media-audio-1', 'mime_type' => 'audio/ogg']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio1')->first();
        $this->assertNotNull($mensagem, 'Mensagem de áudio deveria ter sido criada');
        $this->assertSame('audio', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('/storage/kanban-midia/', $mensagem->midia_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('kanban-midia'));

        Http::assertSent(fn ($request) =>
            str_contains($request->url(), '/media/get')
            && $request['id'] === 'media-audio-1'
            && $request['from'] === '950147584848138'
            && $request['mode'] === 'stream'
        );
    }

    public function test_audio_sem_id_no_payload_e_tratado_sem_quebrar(): void
    {
        Http::fake(); // não deveria ser chamado

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio2', 'type' => 'audio'], // sem 'audio' => ['id' => ...]
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio2')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível identificar o arquivo]', $mensagem->conteudo);
        Http::assertNothingSent();
    }

    public function test_download_da_covercut_falha_nao_quebra_o_webhook(): void
    {
        Http::fake(['*/media/get*' => Http::response('erro interno', 500)]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.audio3', 'type' => 'audio', 'audio' => ['id' => 'media-x']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.audio3')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('[Áudio recebido — não foi possível transcrever]', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
    }
}
```

- [ ] **Step 5: Rodar o teste novo e confirmar que passa (GREEN)**

Nota: esta task não segue RED→GREEN clássico — a implementação (Steps 1-2) precisou vir antes do teste porque restrutura um método existente de forma atômica (não dá pra restruturar em fatias menores sem deixar o controller num estado intermediário quebrado). O teste deste Step valida o resultado da restruturação + do processamento de áudio.

Run: `php artisan test --filter=CovercutWebhookMidiaTest`
Expected: PASS, 3/3.

- [ ] **Step 6: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesmo número de passes de antes + 3 novos, única falha o `ExampleTest` pré-existente (302 vs 200).

- [ ] **Step 7: Commit**

```bash
git add app/Services/MediaProcessorService.php app/Http/Controllers/Webhook/CovercutWebhookController.php tests/Feature/CovercutWebhookMidiaTest.php
git commit -m "feat: processa áudio recebido pelo canal Oficial (Covercut)

Restrutura CovercutWebhookController pra resolver o ticket antes de
processar mídia (necessário pras Tasks seguintes, que precisam de
coluna_kanban pro foco de análise de imagem). Áudio agora é
transcrito via a mesma função Whisper já usada pelo Uazapi
(transcreverAudioBase64) — só a busca do arquivo é nova, via
GET /media/get?mode=stream da Covercut (a Meta já entrega
descriptografado, sem precisar da descriptografia E2E do Uazapi).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Processar IMAGEM (descrição + itens identificados)

**Files:**
- Modify: `app/Services/MediaProcessorService.php`
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php`
- Modify: `tests/Feature/CovercutWebhookControllerTest.php` (atualizar o teste que hoje usa `type: 'image'` esperando ser ignorado — não é mais verdade após esta task)
- Test: `tests/Feature/CovercutWebhookMidiaTest.php` (adicionar casos de imagem)

**Interfaces:**
- Consumes: `baixarMidiaCovercut()` (Task 1, privado, mesma classe), `descreverImagemComVisao(string $imageUrl, string $caption, ?string $focoAnalise): string` (já existente, sem alteração de assinatura).
- Produces: `MediaProcessorService::extrairItensImagemOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string` (público — consumido pelo controller pra popular `ticket.lista_itens`).

- [ ] **Step 1: Extrair o helper compartilhado de `extrairItensImagem()` (Uazapi) — refactor seguro, comportamento idêntico**

Em `MediaProcessorService.php`, localizar o método `extrairItensImagem()` (público, Uazapi) e substituí-lo por esta versão — a lógica de chamada de visão foi extraída pra um novo método privado `chamarVisaoParaItens()`, reaproveitado pela Task 2. O comportamento externo de `extrairItensImagem()` não muda em nada (mesma ordem de checagens, mesmo resultado):

```php
    public function extrairItensImagem(array $msg, string $instanceToken, ?string $focoAnalise = null): ?string
    {
        if (! $this->openRouterKey) {
            return null;
        }

        $mediaUrl = $this->obterUrlImagem($msg, $instanceToken);
        if (! $mediaUrl) {
            return null;
        }

        return $this->chamarVisaoParaItens($mediaUrl, $focoAnalise);
    }
```

Adicionar o novo método privado logo depois (contém exatamente o corpo que estava em `extrairItensImagem()`, sem nenhuma mudança de lógica):

```php
    private function chamarVisaoParaItens(string $mediaUrl, ?string $focoAnalise = null): ?string
    {
        $foco = trim($focoAnalise ?: self::FOCO_PADRAO);

        $modelosVision = FreeModelsService::vision();
        if (count($modelosVision) < 3) {
            $modelosVision[] = self::VISAO_PAGO_FALLBACK;
        }

        $prompt = "Liste em tópicos curtos (um item por linha, começando com '-') o que aparece na imagem "
            . "relacionado a: {$foco}. Seja objetivo, sem frases longas — só o essencial de cada item "
            . "(ex: '- Sofá 3 lugares'). Se nada relevante aparecer, responda apenas 'Nada identificado'.";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openRouterKey}",
                'HTTP-Referer'  => config('app.url', 'https://app.leadcerto.app.br'),
                'X-Title'       => 'Lead Certo',
            ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'models'   => $modelosVision,
                'route'    => 'fallback',
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $mediaUrl]],
                        ['type' => 'text',      'text'      => $prompt],
                    ],
                ]],
                'max_tokens' => 200,
            ]);

            if ($response->successful()) {
                $texto = trim($response->json('choices.0.message.content') ?? '');
                return ($texto && ! str_contains(mb_strtolower($texto), 'nada identificado')) ? $texto : null;
            }

            Log::warning('MediaProcessor: extração de itens falhou', ['status' => $response->status()]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor: extração de itens exceção', ['erro' => $e->getMessage()]);
        }

        return null;
    }
```

- [ ] **Step 2: Rodar a suíte de mídia do Uazapi pra confirmar que o refactor não quebrou nada**

Run: `php artisan test --filter=UazapiWebhookMidiaTest`
Expected: PASS, mesmo resultado de antes do refactor (nenhum teste toca `extrairItensImagem` diretamente hoje, mas confirma que a classe ainda carrega/funciona sem erro de sintaxe).

- [ ] **Step 3: Adicionar os métodos de imagem oficial em `MediaProcessorService`**

Adicionar (mesma área dos métodos "Oficial" da Task 1):

```php
    private function processarImagemOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): string
    {
        $caption = $message['image']['caption'] ?? '';
        $mediaId = $message['image']['id'] ?? null;

        if (! $mediaId) {
            Log::warning('MediaProcessor: payload de imagem oficial sem image.id', ['message' => $message]);
            return $caption ?: '[Imagem recebida]';
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return $caption ? "[Imagem: {$caption}]" : '[Imagem recebida — não foi possível analisar o conteúdo]';
        }

        $dataUri   = 'data:' . ($midia['mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($midia['bytes']);
        $descricao = $this->descreverImagemComVisao($dataUri, $caption, $focoAnalise);
        $prefixo   = $caption ? "[Imagem: {$caption}] " : '[Imagem] ';

        return $prefixo . $descricao;
    }

    public function extrairItensImagemOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string
    {
        if (! $this->openRouterKey) {
            return null;
        }

        $mediaId = $message['image']['id'] ?? null;
        if (! $mediaId) {
            return null;
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return null;
        }

        $dataUri = 'data:' . ($midia['mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($midia['bytes']);

        return $this->chamarVisaoParaItens($dataUri, $focoAnalise);
    }
```

Atualizar o dispatcher `processarOficial()` (adicionado na Task 1) pra incluir `image`:

```php
    public function processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string
    {
        $tipo = $message['type'] ?? null;

        return match ($tipo) {
            'audio' => $this->processarAudioOficial($message, $canal),
            'image' => $this->processarImagemOficial($message, $canal, $focoAnalise),
            default => null,
        };
    }
```

- [ ] **Step 4: Atualizar `CovercutWebhookController` pra tratar imagem**

No método `processarMensagem()`, adicionar um `elseif` pra `image` logo depois do bloco de `audio` (import de `KanbanColunaConfig` já não existe no arquivo — adicionar):

```php
use App\Models\KanbanColunaConfig;
```

Substituir o trecho:

```php
        } elseif ($tipo === 'audio') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
                if ($conteudo !== null) {
                    $tipoMensagem = 'audio';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'audio');
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar áudio', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        }
```

por:

```php
        } elseif ($tipo === 'audio') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
                if ($conteudo !== null) {
                    $tipoMensagem = 'audio';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'audio');
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar áudio', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'image') {
            try {
                $focoAnalise = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->value('foco_analise_imagem');

                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal, $focoAnalise);
                if ($conteudo !== null) {
                    $tipoMensagem = 'imagem';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'image');

                    $itens = app(MediaProcessorService::class)->extrairItensImagemOficial($payload['message'], $canal, $focoAnalise);
                    if ($itens) {
                        $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                        $ticket->update(['lista_itens' => $listaAtual . $itens]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar imagem', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        }
```

- [ ] **Step 5: Atualizar o teste existente que assumia `type: 'image'` como ignorado**

Em `tests/Feature/CovercutWebhookControllerTest.php`, o teste `test_mensagem_inbound_nao_textual_e_logada_e_ignorada` usa `'message' => ['id' => 'wamid.midia', 'type' => 'image']` — isso não é mais verdade (imagem agora é processada). Trocar o payload desse teste especificamente para um tipo genuinamente não tratado:

```php
            'message' => ['id' => 'wamid.midia', 'type' => 'sticker'],
```

(mantém o resto do teste idêntico — `sticker` não é um tipo tratado por nenhum branch, cai no log de "ignorado" como antes).

- [ ] **Step 6: Escrever os testes novos de imagem**

Adicionar em `tests/Feature/CovercutWebhookMidiaTest.php`:

```php
    public function test_imagem_recebida_e_descrita_e_salva_com_midia_url_e_itens(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-imagem', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777', 'name' => 'Sandro'],
            'message' => ['id' => 'wamid.img1', 'type' => 'image', 'image' => ['id' => 'media-img-1', 'mime_type' => 'image/jpeg', 'caption' => 'minha sala']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.img1')->first();
        $this->assertNotNull($mensagem, 'Mensagem de imagem deveria ter sido criada');
        $this->assertSame('imagem', $mensagem->tipo);
        $this->assertNotNull($mensagem->midia_url);
        $this->assertStringContainsString('minha sala', $mensagem->conteudo);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/media/get') && $request['id'] === 'media-img-1');
    }

    public function test_imagem_sem_id_no_payload_e_tratada_sem_quebrar(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.img2', 'type' => 'image', 'image' => ['caption' => 'sem id aqui']],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $mensagem = Mensagem::where('provider_message_id', 'wamid.img2')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('sem id aqui', $mensagem->conteudo);
        Http::assertNothingSent();
    }
```

- [ ] **Step 7: Rodar os testes novos e confirmar GREEN**

Run: `php artisan test --filter=CovercutWebhookMidiaTest`
Expected: PASS, 5/5 (3 da Task 1 + 2 novos).

- [ ] **Step 8: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesmo total de antes + 2 novos, única falha o `ExampleTest` pré-existente.

- [ ] **Step 9: Commit**

```bash
git add app/Services/MediaProcessorService.php app/Http/Controllers/Webhook/CovercutWebhookController.php tests/Feature/CovercutWebhookMidiaTest.php tests/Feature/CovercutWebhookControllerTest.php
git commit -m "feat: processa imagem recebida pelo canal Oficial (Covercut)

Descrição pro contexto do bot + lista de itens identificados no card,
reaproveitando descreverImagemComVisao() já usado pelo Uazapi. Extrai
a chamada de visão de extrairItensImagem() pra um helper privado
compartilhado (chamarVisaoParaItens) — refactor seguro, comportamento
do Uazapi inalterado.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Vídeo, documento e finalização

**Files:**
- Modify: `app/Services/MediaProcessorService.php`
- Modify: `app/Http/Controllers/Webhook/CovercutWebhookController.php`
- Test: `tests/Feature/CovercutWebhookMidiaTest.php` (adicionar casos de vídeo/documento/unsupported)

**Interfaces:**
- Consumes: `baixarEPersistirUrlOficial()` (Task 1) — reaproveitado pra vídeo.
- Produces: dispatcher `processarOficial()` completo (todos os tipos relevantes tratados).

- [ ] **Step 1: Adicionar os placeholders de vídeo/documento em `MediaProcessorService`**

```php
    private function processarVideoOficial(array $message): string
    {
        $caption = $message['video']['caption'] ?? '';
        return $caption ? "[Vídeo recebido com legenda: {$caption}]" : '[Vídeo recebido]';
    }

    private function processarDocumentoOficial(array $message): string
    {
        $nomeArquivo = $message['document']['filename'] ?? null;
        $caption     = $message['document']['caption'] ?? '';

        if ($nomeArquivo) {
            return "[Documento recebido: {$nomeArquivo}]" . ($caption ? " — {$caption}" : '');
        }

        return $caption ? "[Documento recebido: {$caption}]" : '[Documento recebido]';
    }
```

Atualizar o dispatcher `processarOficial()` pra versão final:

```php
    public function processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): ?string
    {
        $tipo = $message['type'] ?? null;

        return match ($tipo) {
            'audio'    => $this->processarAudioOficial($message, $canal),
            'image'    => $this->processarImagemOficial($message, $canal, $focoAnalise),
            'video'    => $this->processarVideoOficial($message),
            'document' => $this->processarDocumentoOficial($message),
            default    => null,
        };
    }
```

- [ ] **Step 2: Atualizar `CovercutWebhookController` pra vídeo e documento**

Adicionar depois do bloco `elseif ($tipo === 'image')` (dentro do mesmo método `processarMensagem()`):

```php
        } elseif ($tipo === 'video') {
            $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
            $tipoMensagem = 'video';
            $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'video');
        } elseif ($tipo === 'document') {
            // Paridade com o Uazapi: documento nunca guarda midia_url nem usa o
            // enum 'documento' de fato — sempre tipo 'texto' com placeholder.
            $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
        }
```

(Nota: `video` e `document` não precisam de `try/catch` — `processarVideoOficial`/`processarDocumentoOficial` não fazem chamada de rede nenhuma, só formatam texto a partir do próprio payload; só `baixarEPersistirUrlOficial` do vídeo faz rede, e já retorna `null` em qualquer falha internamente, sem lançar.)

- [ ] **Step 3: Atualizar o docblock da classe (estava desatualizado desde a Task 1)**

No topo de `CovercutWebhookController.php`, substituir:

```php
/**
 * Webhook do canal oficial (Covercut/Meta Cloud API). MVP: só texto — sem mídia,
 * sem botão, sem chamada de voz (fora de escopo, ver seção 8 do design técnico).
 * Deliberadamente autocontido (não reusa UazapiWebhookController) — ver Architecture
 * no cabeçalho do plano.
 */
```

por:

```php
/**
 * Webhook do canal oficial (Covercut/Meta Cloud API). Processa texto, áudio
 * (transcrição) e imagem (descrição + itens identificados) — ver
 * docs/superpowers/specs/2026-07-30-midia-canal-oficial-covercut-design.md.
 * Vídeo/documento têm placeholder sem análise real (paridade com o Uazapi).
 * Sem botão nem chamada de voz (fora de escopo). Deliberadamente autocontido
 * (não reusa UazapiWebhookController) — ver Architecture no plano original
 * (2026-07-29).
 */
```

- [ ] **Step 4: Escrever os testes novos**

Adicionar em `tests/Feature/CovercutWebhookMidiaTest.php`:

```php
    public function test_video_e_salvo_com_tipo_video_e_midia_url(): void
    {
        Http::fake([
            '*/media/get*' => Http::response('conteudo-binario-fake-video', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.vid1', 'type' => 'video', 'video' => ['id' => 'media-vid-1', 'caption' => 'olha isso']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.vid1')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('video', $mensagem->tipo);
        $this->assertStringContainsString('olha isso', $mensagem->conteudo);
        $this->assertNotNull($mensagem->midia_url);
    }

    public function test_documento_e_salvo_com_placeholder_sem_midia_url(): void
    {
        Http::fake(); // não deveria ser chamado — documento não baixa mídia

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.doc1', 'type' => 'document', 'document' => ['filename' => 'orcamento.pdf']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $mensagem = Mensagem::where('provider_message_id', 'wamid.doc1')->first();
        $this->assertNotNull($mensagem);
        $this->assertSame('texto', $mensagem->tipo);
        $this->assertStringContainsString('orcamento.pdf', $mensagem->conteudo);
        $this->assertNull($mensagem->midia_url);
        Http::assertNothingSent();
    }

    public function test_tipo_unsupported_da_meta_continua_apenas_logado(): void
    {
        Http::fake();

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);

        $payload = [
            'event' => 'message', 'direction' => 'inbound', 'from_number_id' => '950147584848138',
            'contact' => ['wa_id' => '5521988887777'],
            'message' => ['id' => 'wamid.unsup1', 'type' => 'unsupported', 'unsupported' => ['type' => 'unknown']],
        ];

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertDatabaseMissing('mensagens', ['provider_message_id' => 'wamid.unsup1']);
        Http::assertNothingSent();
    }
```

- [ ] **Step 5: Rodar os testes novos e confirmar GREEN**

Run: `php artisan test --filter=CovercutWebhookMidiaTest`
Expected: PASS, 8/8 (5 anteriores + 3 novos).

- [ ] **Step 6: Rodar a suíte completa**

Run: `php artisan test`
Expected: mesmo total de antes + 3 novos, única falha o `ExampleTest` pré-existente.

- [ ] **Step 7: Commit**

```bash
git add app/Services/MediaProcessorService.php app/Http/Controllers/Webhook/CovercutWebhookController.php tests/Feature/CovercutWebhookMidiaTest.php
git commit -m "feat: vídeo/documento com placeholder no canal Oficial (paridade Uazapi)

Completa a cobertura de tipos de mídia do canal Oficial: vídeo ganha
midia_url (sem análise real, igual ao Uazapi hoje); documento fica só
com placeholder de texto, sem download (mesma decisão do Uazapi, que
nunca usa o enum 'documento' de fato). Tipos genuinamente não
suportados pela Meta (polls, pagamento, etc.) continuam só logados —
conteúdo real é irrecuperável via API, segundo os próprios docs da
Covercut.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Deploy

Após as 3 tasks concluídas e revisadas: `git push origin main` + `./deploy.sh` (mesma rotina já usada em toda a sessão — inclui migrate, build de assets, restart de workers, sem passos manuais).

Não há migration nova nesta entrega (nenhuma alteração de schema — `mensagens.midia_url`/`tipo` e `tickets_atendimento.lista_itens` já existem).
