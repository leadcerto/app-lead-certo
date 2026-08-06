# Erro Claro pra Áudio Incompatível no Canal Oficial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar o erro genérico "Falha ao enviar pelo WhatsApp" por um erro específico e claro quando o atendente tenta enviar áudio num formato que a Meta Cloud API não aceita (`.webm`/`.wav`) pelo canal Oficial (Covercut).

**Architecture:** Validação adicional em `KanbanController::enviarMidia()`, antes de salvar o arquivo no storage — só se aplica quando `tipo === 'audio'` e `$canal->provider === 'covercut'`. Nenhuma mudança na Uazapi, no `CanalWhatsappInterface`, no `CovercutChannelService` nem no frontend.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (`RefreshDatabase`, `Http::fake()`).

## Global Constraints

- Sem conversão/transcodificação de áudio no servidor — decisão explícita de não instalar `ffmpeg` agora (spec `docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md`).
- A validação só se aplica ao canal Covercut (`provider === 'covercut'`) — Uazapi continua aceitando `webm`/`wav` sem nenhuma mudança.
- Formatos aceitos pela Meta pra áudio: `mp3`, `ogg`, `m4a`. Os dois rejeitados (dentre os hoje aceitos pelo upload do painel): `webm`, `wav`.
- Resposta de erro: `422` com `{"message": "..."}`, arquivo NÃO deve ser salvo no storage quando rejeitado.

---

### Task 1: Validação de formato de áudio pro canal Oficial

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php`
- Test: `tests/Feature/KanbanEnviarMidiaCanalOficialTest.php`

**Interfaces:**
- Consumes: `WhatsappCanal::$provider` (propriedade já existente, valores `'uazapi'`/`'covercut'`); `UploadedFile::getClientOriginalExtension()` (já usado nesse mesmo método, linha 414 atual).
- Produces: nada consumido por outra task — plano de task única.

- [ ] **Step 1: Escrever os testes que falham**

Adicione estes métodos de teste em `tests/Feature/KanbanEnviarMidiaCanalOficialTest.php`, depois do `test_envia_figurinha_pelo_canal_oficial` existente (antes do `}` final da classe):

```php
    public function test_audio_webm_falha_com_erro_especifico_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.webm', 10, 'audio/webm');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'O canal Oficial (WhatsApp Business) não aceita áudio nesse formato (.webm). Grave por outro navegador (o Firefox costuma gravar em .ogg) ou anexe um arquivo .mp3/.ogg/.m4a.']);
        Http::assertNothingSent();
        Storage::disk('public')->assertDirectoryEmpty('kanban-midia');
    }

    public function test_audio_wav_falha_com_erro_especifico_no_canal_oficial(): void
    {
        $ticket = $this->criarTicketOficial();
        $user   = User::factory()->create(['tenant_id' => $ticket->tenant_id, 'perfil' => 'dono', 'ativo' => true]);

        $arquivo = UploadedFile::fake()->create('audio.wav', 10, 'audio/wav');

        $response = $this->actingAs($user)->post("/api/painel/kanban/ticket/{$ticket->id}/midia", [
            'tipo'    => 'audio',
            'arquivo' => $arquivo,
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
        Storage::disk('public')->assertDirectoryEmpty('kanban-midia');
    }
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php.bat artisan test --filter=KanbanEnviarMidiaCanalOficialTest`
Expected: FAIL nos 2 testes novos — hoje o áudio `.webm`/`.wav` passa da validação de upload (`mimes:mp3,ogg,webm,m4a,wav` aceita os dois) e cai no envio de verdade via `Http::fake()`, retornando 201 em vez de 422 (o `CovercutChannelService::enviarAudio()` de hoje aceita qualquer formato que chegue, só decide o campo `voice` pela extensão — não rejeita nada). Os testes já existentes (imagem/áudio ogg/documento/sticker) continuam passando.

- [ ] **Step 3: Adicionar a constante e a validação em `KanbanController.php`**

Em `app/Http/Controllers/Painel/KanbanController.php`, logo antes de `public function enviarMidia(Request $request, int $ticket): JsonResponse` (linha 380 atual), adicione:

```php
    // Formatos de áudio que a Meta Cloud API aceita pra mensagens (aac/amr/mpeg/mp4/ogg,
    // ver docs/superpowers/specs/2026-07-31-erro-formato-audio-canal-oficial-design.md) —
    // subconjunto do que a validação de upload abaixo aceita (mp3,ogg,webm,m4a,wav).
    // webm/wav são aceitos pelo upload mas rejeitados pela Meta — daí a checagem extra
    // só pro canal Covercut logo abaixo.
    private const AUDIO_EXTENSOES_ACEITAS_COVERCUT = ['mp3', 'ogg', 'm4a'];

    public function enviarMidia(Request $request, int $ticket): JsonResponse
    {
```

Depois, troque:
```php
        if (! $canal) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        $path     = $arquivo->store('kanban-midia', 'public');
```

Por:
```php
        if (! $canal) {
            return response()->json(['message' => 'Nenhum canal de WhatsApp vinculado a este atendimento.'], 502);
        }

        if ($tipo === 'audio' && $canal->provider === 'covercut') {
            $extensao = strtolower($arquivo->getClientOriginalExtension());
            if (! in_array($extensao, self::AUDIO_EXTENSOES_ACEITAS_COVERCUT, true)) {
                return response()->json([
                    'message' => "O canal Oficial (WhatsApp Business) não aceita áudio nesse formato (.{$extensao}). Grave por outro navegador (o Firefox costuma gravar em .ogg) ou anexe um arquivo .mp3/.ogg/.m4a.",
                ], 422);
            }
        }

        $path     = $arquivo->store('kanban-midia', 'public');
```

- [ ] **Step 4: Rodar os testes novos e confirmar que passam**

Run: `php.bat artisan test --filter=KanbanEnviarMidiaCanalOficialTest`
Expected: PASS — todos os testes do arquivo (os 4 já existentes + os 2 novos).

- [ ] **Step 5: Rodar a suíte completa — checagem de regressão**

Run: `php.bat artisan test`
Expected: mesma contagem de passes de antes desta task + 2, com a mesma única falha pré-existente e sem relação (`ExampleTest`). Em especial, confirmar que `tests/Feature/KanbanEnviarMidiaFigurinhaTest.php` (canal Uazapi, aceita `.webp` sem essa validação nova) continua passando sem nenhuma mudança — prova que a checagem só afeta o Covercut.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanEnviarMidiaCanalOficialTest.php
git commit -m "feat: erro específico pra áudio incompatível (.webm/.wav) no canal Oficial"
```
