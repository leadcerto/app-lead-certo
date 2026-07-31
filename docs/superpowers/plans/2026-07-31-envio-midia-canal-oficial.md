# Envio de Mídia no Canal Oficial (Covercut) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o painel do Kanban enviar imagem, áudio, documento e sticker também pelo canal Oficial (Covercut), não só pela Uazapi.

**Architecture:** Adiciona 4 métodos de mídia à `CanalWhatsappInterface`; `UazapiChannelService` delega pro `UazapiService` já existente e testado; `CovercutChannelService` implementa de verdade via `POST /messages/send` com `{tipo}.link` apontando pro storage público do Lead Certo (sem upload prévio pra Meta); `KanbanController::enviarMidia()` passa a resolver o serviço certo via `$canal->servico()` em vez de assumir Uazapi.

**Tech Stack:** Laravel 13 / PHP 8.4, `Illuminate\Support\Facades\Http`, PHPUnit (`RefreshDatabase`, `Http::fake()`).

## Global Constraints

- Envio via link direto (`{tipo}.link`), nunca via upload prévio pra `media_id` da Meta — decisão fechada na spec.
- Escopo é só `KanbanController::enviarMidia()` (envio manual do atendente) — `SequenciaMensagemJob` (sequências automáticas do bot) fica de fora desta entrega.
- Nenhum comportamento existente da Uazapi pode mudar — `UazapiChannelService` só delega pros métodos já existentes e já testados em `UazapiService`.
- Mídia respeita a mesma janela de conversa (24h/72h) que texto já respeita no Covercut.
- Áudio só marca `voice: true` (nota de voz) quando o arquivo tem extensão `.ogg` — outros formatos aceitos (`mp3`, `m4a`, `wav`, `webm`) vão como áudio comum.
- Spec de referência: `docs/superpowers/specs/2026-07-31-envio-midia-canal-oficial-design.md`.

---

### Task 1: `CanalWhatsappInterface` + `UazapiChannelService` — adicionar métodos de mídia

**Files:**
- Modify: `app/Services/Canais/CanalWhatsappInterface.php`
- Modify: `app/Services/Canais/UazapiChannelService.php`
- Test: `tests/Feature/UazapiChannelServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\WhatsappCanal::tokenUazapi(): ?string` (já existe); `App\Services\UazapiService::enviarImagem(string $token, string $numero, string $url, string $caption = ''): bool`, `enviarAudio(string $token, string $numero, string $url, bool $ptt = true): bool`, `enviarDocumento(string $token, string $numero, string $url, string $filename = '', string $caption = ''): bool`, `enviarSticker(string $token, string $numero, string $url): bool` (todos já existem e já são usados em produção).
- Produces: `CanalWhatsappInterface::enviarImagem/enviarAudio/enviarDocumento/enviarSticker(WhatsappCanal $canal, string $telefone, string $url, ...): bool` — assinatura que Task 2 (Covercut) e Task 3 (KanbanController) vão consumir.

- [ ] **Step 1: Escrever os testes que falham para `UazapiChannelService`**

Abra `tests/Feature/UazapiChannelServiceTest.php` e adicione estes métodos de teste dentro da classe, depois do `test_whatsapp_canal_servico_resolve_uazapi_channel_service_para_provider_uazapi` existente:

```php
    public function test_envia_imagem_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg', 'legenda');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request->hasHeader('token', 'token-canal-uazapi') && $request['type'] === 'image');
    }

    public function test_envia_audio_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarAudio($canal, '5511999999999', 'https://exemplo.com/audio.ogg');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'ptt');
    }

    public function test_envia_documento_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarDocumento($canal, '5511999999999', 'https://exemplo.com/arquivo.pdf', 'arquivo.pdf');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'document' && $request['docName'] === 'arquivo.pdf');
    }

    public function test_envia_sticker_via_uazapi_usando_token_do_canal(): void
    {
        Http::fake(['*/send/media' => Http::response(['id' => 'abc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'uazapi',
            'config' => ['instance_token' => 'token-canal-uazapi'],
        ]);

        $enviado = app(UazapiChannelService::class)->enviarSticker($canal, '5511999999999', 'https://exemplo.com/fig.webp');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) => $request['type'] === 'sticker');
    }

    public function test_enviar_imagem_retorna_false_quando_canal_sem_token(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => []]);

        $enviado = app(UazapiChannelService::class)->enviarImagem($canal, '5511999999999', 'https://exemplo.com/foto.jpg');

        $this->assertFalse($enviado);
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=UazapiChannelServiceTest`
Expected: FAIL — `Call to undefined method App\Services\Canais\UazapiChannelService::enviarImagem()` (e mesmo erro pros outros 3 métodos novos).

- [ ] **Step 3: Adicionar os 4 métodos à interface**

Em `app/Services/Canais/CanalWhatsappInterface.php`, adicione depois de `enviarTextoDireto()` (antes do `}` final):

```php

    /**
     * Envia uma imagem. $url deve ser uma URL pública acessível.
     * Retorna false (sem lançar exceção) em qualquer falha de envio.
     */
    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool;

    /**
     * Envia um arquivo de áudio. $url deve ser uma URL pública acessível.
     * $ptt = true pede que apareça como nota de voz gravada na hora — cada
     * provedor decide se/como atender isso conforme suas próprias regras de formato.
     */
    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool;

    /**
     * Envia um documento/arquivo. $url deve ser uma URL pública acessível.
     */
    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool;

    /**
     * Envia uma figurinha (.webp) — tipo de mídia próprio do WhatsApp, separado
     * de imagem comum.
     */
    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool;
```

- [ ] **Step 4: Implementar os 4 métodos em `UazapiChannelService`**

Em `app/Services/Canais/UazapiChannelService.php`, adicione estes métodos depois de `enviarTextoDireto()` (antes do `}` final da classe):

```php

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarImagem($token, $telefone, $url, $caption);
    }

    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarAudio($token, $telefone, $url, $ptt);
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption);
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        $token = $canal->tokenUazapi();

        if (! $token) {
            Log::warning('UazapiChannelService: canal sem token, mensagem não enviada', ['canal_id' => $canal->id]);
            return false;
        }

        return $this->uazapi->enviarSticker($token, $telefone, $url);
    }
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=UazapiChannelServiceTest`
Expected: PASS (todos os testes, novos e existentes).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Canais/CanalWhatsappInterface.php app/Services/Canais/UazapiChannelService.php tests/Feature/UazapiChannelServiceTest.php
git commit -m "feat: adiciona métodos de envio de mídia à CanalWhatsappInterface (Uazapi)"
```

---

### Task 2: `CovercutChannelService` — implementar envio de mídia via link

**Files:**
- Modify: `app/Services/Canais/CovercutChannelService.php`
- Test: `tests/Feature/CovercutChannelServiceTest.php`

**Interfaces:**
- Consumes: `CanalWhatsappInterface::enviarImagem/enviarAudio/enviarDocumento/enviarSticker` (assinaturas definidas na Task 1); `App\Models\TicketAtendimento` (já usado no arquivo); `config('services.covercut.base_url'|'api_key'|'api_secret')` (já existe em `config/services.php`).
- Produces: implementação completa dos 4 métodos, chamada por Task 3 via `$canal->servico()`.

- [ ] **Step 1: Escrever os testes que falham**

Adicione estes métodos de teste em `tests/Feature/CovercutChannelServiceTest.php`, depois do `test_retorna_false_sem_lancar_excecao_em_falha_de_conexao` existente (antes do `}` final da classe):

```php
    public function test_envia_imagem_via_covercut_com_legenda(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.img'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarImagem($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/foto.jpg', 'legenda');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'image'
            && $request['image']['link'] === 'https://app.leadcerto.app.br/storage/foto.jpg'
            && $request['image']['caption'] === 'legenda'
        );
    }

    public function test_envia_audio_ogg_como_nota_de_voz(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.audio'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarAudio($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/audio.ogg');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'audio'
            && $request['audio']['link'] === 'https://app.leadcerto.app.br/storage/audio.ogg'
            && $request['audio']['voice'] === true
        );
    }

    public function test_envia_audio_mp3_sem_marcar_como_nota_de_voz(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.audio'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarAudio($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/audio.mp3');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'audio'
            && ! isset($request['audio']['voice'])
        );
    }

    public function test_envia_documento_com_nome_de_arquivo(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.doc'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarDocumento($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/arquivo.pdf', 'boleto.pdf');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'document'
            && $request['document']['link'] === 'https://app.leadcerto.app.br/storage/arquivo.pdf'
            && $request['document']['filename'] === 'boleto.pdf'
        );
    }

    public function test_envia_sticker_via_covercut(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.sticker'], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = $this->canalOficial($tenant->id);

        $enviado = app(CovercutChannelService::class)->enviarSticker($canal, '5511999999999', 'https://app.leadcerto.app.br/storage/fig.webp');

        $this->assertTrue($enviado);
        Http::assertSent(fn ($request) =>
            $request['type'] === 'sticker'
            && $request['sticker']['link'] === 'https://app.leadcerto.app.br/storage/fig.webp'
        );
    }

    public function test_bloqueia_envio_de_imagem_fora_da_janela(): void
    {
        Http::fake();
        Log::spy();

        $tenant  = Tenant::factory()->create();
        $canal   = $this->canalOficial($tenant->id);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->subHour(),
        ]);

        $enviado = app(CovercutChannelService::class)->enviarImagem($canal, '5511988888888', 'https://app.leadcerto.app.br/storage/foto.jpg');

        $this->assertFalse($enviado);
        Http::assertNothingSent();
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=CovercutChannelServiceTest`
Expected: FAIL — `Call to undefined method App\Services\Canais\CovercutChannelService::enviarImagem()` (e mesmo erro pros outros 3 métodos novos). Os testes já existentes (`test_envia_texto_via_covercut_dentro_da_janela` etc.) continuam passando neste ponto — ainda não tocamos `enviarTexto()`.

- [ ] **Step 3: Reescrever `CovercutChannelService.php` inteiro**

Substitua **todo o conteúdo** de `app/Services/Canais/CovercutChannelService.php` por:

```php
<?php

namespace App\Services\Canais;

use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens pelo canal oficial (Meta Cloud API, via Covercut).
 * Nunca dispara proativamente — só responde dentro da janela de conversa (seção 4
 * do design, docs/superpowers/specs/2026-07-27-canal-whatsapp-oficial-covercut-design.md).
 * Sem templates pagos: fora da janela, o envio é bloqueado, sem fallback.
 * Mídia (imagem/áudio/documento/sticker) é enviada via link público — nunca faz
 * upload prévio pra Meta (ver docs/superpowers/specs/2026-07-31-envio-midia-canal-oficial-design.md).
 */
class CovercutChannelService implements CanalWhatsappInterface
{
    public function enviarTexto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        return $this->enviar($canal, $telefone, [
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);
    }

    /**
     * Covercut não tem pipeline de humanização (isso é exclusivo do Uazapi) — o envio
     * já é uma única mensagem imediata, então só delega para enviarTexto(). A checagem
     * de janela de conversa continua valendo normalmente.
     */
    public function enviarTextoDireto(WhatsappCanal $canal, string $telefone, string $texto): bool
    {
        return $this->enviarTexto($canal, $telefone, $texto);
    }

    public function enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool
    {
        $imagem = ['link' => $url];
        if ($caption !== '') {
            $imagem['caption'] = $caption;
        }

        return $this->enviar($canal, $telefone, ['type' => 'image', 'image' => $imagem]);
    }

    /**
     * $ptt = true pede nota de voz, mas só marca 'voice' quando o arquivo é .ogg —
     * a Covercut/Meta exige esse formato (codec opus) pra renderizar como nota de
     * voz de verdade; outro formato marcado como voice pode ser rejeitado ou
     * renderizado errado do lado do WhatsApp.
     */
    public function enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool
    {
        $audio = ['link' => $url];
        if ($ptt && strtolower(pathinfo($url, PATHINFO_EXTENSION)) === 'ogg') {
            $audio['voice'] = true;
        }

        return $this->enviar($canal, $telefone, ['type' => 'audio', 'audio' => $audio]);
    }

    public function enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool
    {
        $documento = ['link' => $url];
        if ($filename !== '') {
            $documento['filename'] = $filename;
        }
        if ($caption !== '') {
            $documento['caption'] = $caption;
        }

        return $this->enviar($canal, $telefone, ['type' => 'document', 'document' => $documento]);
    }

    public function enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool
    {
        return $this->enviar($canal, $telefone, ['type' => 'sticker', 'sticker' => ['link' => $url]]);
    }

    /**
     * Monta e envia qualquer tipo de mensagem via POST /messages/send — checa
     * janela de conversa e phone_number_id, nunca lança exceção. $corpo já deve
     * trazer 'type' e o campo de conteúdo específico do tipo (text/image/audio/...).
     */
    private function enviar(WhatsappCanal $canal, string $telefone, array $corpo): bool
    {
        if (! $this->dentroDaJanela($canal, $telefone)) {
            return false;
        }

        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('CovercutChannelService: canal sem phone_number_id configurado', ['canal_id' => $canal->id]);
            return false;
        }

        $baseUrl = config('services.covercut.base_url');

        try {
            $response = Http::withHeaders([
                    'X-API-Key'    => config('services.covercut.api_key'),
                    'X-API-Secret' => config('services.covercut.api_secret'),
                ])
                ->post("{$baseUrl}/messages/send", array_merge([
                    'from' => $phoneNumberId,
                    'to'   => $telefone,
                ], $corpo));
        } catch (\Throwable $e) {
            // Http::post lança ConnectionException em falhas de rede (DNS, timeout, TLS,
            // conexão recusada). A interface exige nunca lançar exceção.
            Log::warning('CovercutChannelService: exceção ao enviar mensagem', [
                'canal_id' => $canal->id,
                'tipo'     => $corpo['type'] ?? 'desconhecido',
                'erro'     => $e->getMessage(),
            ]);
            return false;
        }

        if (! $response->successful()) {
            Log::warning('CovercutChannelService: falha ao enviar mensagem', [
                'canal_id' => $canal->id,
                'tipo'     => $corpo['type'] ?? 'desconhecido',
                'status'   => $response->status(),
                'body'     => $response->body(),
            ]);
        }

        return $response->successful();
    }

    /**
     * Checa se ainda existe janela de conversa aberta (24h, ou 72h se veio de
     * anúncio) pro telefone neste canal. Sem ticket em aberto pro telefone, não há
     * janela pra checar — não bloqueia (ex: primeiro contato antes de qualquer
     * ticket existir); a Covercut também valida a janela do lado dela.
     */
    private function dentroDaJanela(WhatsappCanal $canal, string $telefone): bool
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
                'canal_id'   => $canal->id,
                'ticket_id'  => $ticket->id,
                'expirou_em' => $ticket->janela_expira_em->toIso8601String(),
            ]);
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 4: Rodar todos os testes de `CovercutChannelServiceTest` e confirmar que passam**

Run: `php artisan test --filter=CovercutChannelServiceTest`
Expected: PASS — os 4 testes novos de mídia, o teste de janela expirada pra imagem, **e os 4 testes já existentes de texto** (o refactor de `enviarTexto()`/`enviarTextoDireto()` não pode mudar o comportamento deles).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Canais/CovercutChannelService.php tests/Feature/CovercutChannelServiceTest.php
git commit -m "feat: implementa envio de mídia (imagem/áudio/documento/sticker) via link no CovercutChannelService"
```

---

### Task 3: `KanbanController::enviarMidia()` — resolver o serviço pelo provider do canal

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php`
- Test: `tests/Feature/KanbanEnviarMidiaCanalOficialTest.php` (novo)

**Interfaces:**
- Consumes: `App\Models\WhatsappCanal::servico(): CanalWhatsappInterface` (já existe); `CanalWhatsappInterface::enviarImagem/enviarAudio/enviarDocumento/enviarSticker` (Task 1 + Task 2).
- Produces: nada consumido por tasks futuras — esta é a última task do plano.

- [ ] **Step 1: Escrever o teste de integração que falha**

Crie `tests/Feature/KanbanEnviarMidiaCanalOficialTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KanbanEnviarMidiaCanalOficialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.xyz'], 200)]);
    }

    private function criarTicketOficial(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);
    }

    public function test_envia_imagem_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'image');
    }

    public function test_envia_audio_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.ogg', 10, 'audio/ogg');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'audio' && $request['audio']['voice'] === true);
    }

    public function test_envia_documento_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('boleto.pdf', 10, 'application/pdf');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'documento',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'document' && $request['document']['filename'] === 'boleto.pdf');
    }

    public function test_envia_figurinha_pelo_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->image('fig.webp')->mimeType('image/webp');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'imagem',
            'arquivo' => $arquivo,
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => $request['type'] === 'sticker');
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=KanbanEnviarMidiaCanalOficialTest`
Expected: FAIL — os 4 testes retornam 502 ("Nenhum canal de WhatsApp vinculado a este atendimento"), porque `enviarMidia()` ainda usa `tokenUazapi()` (sempre vazio pro Covercut).

- [ ] **Step 3: Editar `KanbanController.php` — remover a dependência direta de `UazapiService`**

Em `app/Http/Controllers/Painel/KanbanController.php`, remova a linha de import (ela deixa de ser usada neste arquivo depois do Step 4):

Troque:
```php
use App\Jobs\ConversationQAJob;
use App\Jobs\GerarResumoTicketJob;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\SequenciaService;
use App\Services\UazapiService;
use Illuminate\Contracts\View\View;
```

Por:
```php
use App\Jobs\ConversationQAJob;
use App\Jobs\GerarResumoTicketJob;
use App\Models\KanbanColuna;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\SequenciaService;
use Illuminate\Contracts\View\View;
```

E troque o construtor:
```php
class KanbanController extends Controller
{
    public function __construct(
        private UazapiService $uazapi,
    ) {}
```

Por (sem construtor — não há mais nenhuma propriedade injetada usada na classe):
```php
class KanbanController extends Controller
{
```

- [ ] **Step 4: Editar o corpo de `enviarMidia()`**

No mesmo arquivo, troque:
```php
        $model = TicketAtendimento::with(['contato', 'tenant', 'canal'])->findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $arquivo  = $request->file('arquivo');
        $caption  = $request->input('caption', '');
        $telefone = $model->contato->telefone;
        $token    = $model->canal?->tokenUazapi();

        if (! $token) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        $path     = $arquivo->store('kanban-midia', 'public');
        $url      = url('storage/' . $path);
        $filename = $arquivo->getClientOriginalName();

        $ehFigurinha = $tipo === 'imagem' && strtolower($arquivo->getClientOriginalExtension()) === 'webp';

        $enviado = match (true) {
            $ehFigurinha          => $this->uazapi->enviarSticker($token, $telefone, $url),
            $tipo === 'imagem'    => $this->uazapi->enviarImagem($token, $telefone, $url, $caption),
            $tipo === 'audio'     => $this->uazapi->enviarAudio($token, $telefone, $url, true),
            $tipo === 'documento' => $this->uazapi->enviarDocumento($token, $telefone, $url, $filename, $caption),
            default               => false,
        };
```

Por:
```php
        $model = TicketAtendimento::with(['contato', 'tenant', 'canal'])->findOrFail($ticket);

        if ($conflito = $this->assumirAutomaticamente($model, $request->user())) {
            return $conflito;
        }

        $arquivo  = $request->file('arquivo');
        $caption  = $request->input('caption', '');
        $telefone = $model->contato->telefone;
        $canal    = $model->canal;

        if (! $canal) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        $path     = $arquivo->store('kanban-midia', 'public');
        $url      = url('storage/' . $path);
        $filename = $arquivo->getClientOriginalName();

        $ehFigurinha = $tipo === 'imagem' && strtolower($arquivo->getClientOriginalExtension()) === 'webp';
        $servico     = $canal->servico();

        $enviado = match (true) {
            $ehFigurinha          => $servico->enviarSticker($canal, $telefone, $url),
            $tipo === 'imagem'    => $servico->enviarImagem($canal, $telefone, $url, $caption),
            $tipo === 'audio'     => $servico->enviarAudio($canal, $telefone, $url, true),
            $tipo === 'documento' => $servico->enviarDocumento($canal, $telefone, $url, $filename, $caption),
            default               => false,
        };
```

- [ ] **Step 5: Rodar o teste novo e confirmar que passa**

Run: `php artisan test --filter=KanbanEnviarMidiaCanalOficialTest`
Expected: PASS — os 4 testes (imagem, áudio, documento, figurinha pelo canal Oficial).

- [ ] **Step 6: Rodar a suíte completa de testes — checagem de regressão**

Run: `php artisan test`
Expected: PASS em tudo que passava antes (em especial `KanbanEnviarMidiaFigurinhaTest`, que cobre o mesmo endpoint pelo canal Uazapi — tem que continuar 100% verde, sem nenhuma mudança de comportamento). Única falha esperada, pré-existente e sem relação: `ExampleTest`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanEnviarMidiaCanalOficialTest.php
git commit -m "fix: KanbanController::enviarMidia resolve o serviço pelo provider do canal, corrige envio no Covercut"
```
