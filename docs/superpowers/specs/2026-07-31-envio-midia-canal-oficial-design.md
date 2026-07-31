# Envio de Mídia (imagem/áudio/documento/sticker) no Canal Oficial (Covercut) — Design Técnico

> Complementa `2026-07-30-midia-canal-oficial-covercut-design.md` (recepção de mídia no canal Oficial). Este documento cobre o envio — hoje o painel do Kanban só sabe mandar mídia pela Uazapi.

---

## 1. Contexto e problema

O canal Oficial (Covercut) recebe mídia corretamente desde 2026-07-31 (confirmado com teste real). Mas ao tentar **responder** um ticket desse canal com áudio, imagem ou documento pela tela do Kanban, o envio falha com o popup enganoso "Nenhum canal de WhatsApp vinculado a este atendimento".

**Causa raiz:** `CanalWhatsappInterface` só define `enviarTexto()`/`enviarTextoDireto()` — nunca teve métodos de mídia. `KanbanController::enviarMidia()` sempre foi escrito só pra Uazapi: pega `$model->canal->tokenUazapi()` (retorna vazio pra Covercut) e chama `$this->uazapi->enviarImagem/enviarAudio/enviarDocumento` direto, sem checar o provider do canal.

**Esta entrega é 100% aditiva.** O canal Uazapi continua funcionando exatamente como está.

## 2. Escopo

**Dentro do escopo:**
- Envio de imagem, áudio, documento e sticker pelo canal Oficial, disparado pelo atendente humano no painel do Kanban (`KanbanController::enviarMidia()`).
- Paridade de comportamento com a Uazapi: os 4 tipos que já funcionam lá devem funcionar igual no Covercut.

**Fora do escopo (decisão explícita do Leonardo em 2026-07-31):**
- Envio de mídia em sequências automáticas do bot (`SequenciaMensagemJob`) — hoje pula imagem/botões silenciosamente no Covercut; continua assim por enquanto.
- Botões interativos no canal Oficial (feature separada, já listada em "Fora do MVP").
- Upload prévio via `media_id` da Meta — ver decisão técnica abaixo.

## 3. Descoberta técnica principal

A documentação da Covercut (`api.covercut.com.br/docs/`, seções `enviar-imagem`, `enviar-audio`, `enviar-documento`, `enviar-sticker`) mostra que `POST /api/v1/messages/send` aceita mídia de duas formas: **via link** (`{tipo}.link`, a Meta baixa direto da URL pública) ou **via upload prévio** (`POST /media/upload` retorna um `media_id`, depois referenciado em `{tipo}.id`).

**Decisão: usar via link.** Os arquivos que o atendente sobe pelo painel já ficam em `storage/public` com URL pública (mesmo padrão que a Uazapi já usa) — enviar por link é o mesmo formato de requisição que `enviarTexto()` já usa hoje, sem precisar gerenciar upload nem `media_id`. A documentação sugere o upload como "mais rápido e confiável", mas isso importa mais pra volumes altos ou arquivos gerados dinamicamente por IA — não é o caso aqui (arquivo já existe e já é público antes do envio).

### 3.1 Payloads confirmados (docs reais da Covercut)

```json
POST /api/v1/messages/send
{"from": "{phone_number_id}", "to": "{telefone}", "type": "image",
 "image": {"link": "https://app.leadcerto.app.br/storage/...", "caption": "opcional"}}

{"from": "...", "to": "...", "type": "audio",
 "audio": {"link": "https://.../audio.ogg", "voice": true}}

{"from": "...", "to": "...", "type": "document",
 "document": {"link": "https://.../arquivo.pdf", "filename": "boleto.pdf"}}

{"from": "...", "to": "...", "type": "sticker",
 "sticker": {"link": "https://.../figurinha.webp"}}
```

- `voice: true` no áudio faz a mensagem aparecer como nota de voz gravada na hora — a documentação exige que o arquivo seja `.ogg` (codec opus) pra isso funcionar corretamente.
- Sem exemplo de `voice` combinado com `link` nos docs (só aparece no exemplo via `media_id`) — assumido que o campo funciona igual independente da origem do arquivo (`link` vs `id`), já que é um campo do objeto `audio` da Meta Cloud API, não específico de um dos dois modos.

## 4. Arquitetura

```
CanalWhatsappInterface (app/Services/Canais/)
├── enviarTexto()        [existente]
├── enviarTextoDireto()  [existente]
├── enviarImagem(WhatsappCanal $canal, string $telefone, string $url, string $caption = ''): bool     [NOVO]
├── enviarAudio(WhatsappCanal $canal, string $telefone, string $url, bool $ptt = true): bool           [NOVO]
├── enviarDocumento(WhatsappCanal $canal, string $telefone, string $url, string $filename = '', string $caption = ''): bool  [NOVO]
└── enviarSticker(WhatsappCanal $canal, string $telefone, string $url): bool                            [NOVO]

UazapiChannelService — implementa os 4 novos métodos delegando pros métodos que
JÁ EXISTEM em UazapiService (enviarImagem/enviarAudio/enviarDocumento/enviarSticker).
Zero mudança de comportamento — só resolve o token e delega, mesmo padrão de
enviarTextoDireto() já existente.

CovercutChannelService — implementa de verdade via POST /messages/send:
├── private dentroDaJanela(WhatsappCanal $canal, string $telefone): bool
│     extrai a checagem de janela (ticket + janela_expira_em) que hoje só existe
│     dentro de enviarTexto() — reusada pelos 5 métodos de envio (texto + 4 mídia),
│     elimina duplicação
├── enviarImagem() → type=image, image.link + image.caption (se houver)
├── enviarAudio()  → type=audio, audio.link + audio.voice (true só se a extensão
│                    do arquivo for .ogg — ver 4.1)
├── enviarDocumento() → type=document, document.link + document.filename
└── enviarSticker()   → type=sticker, sticker.link

KanbanController::enviarMidia() — troca:
  $token = $model->canal?->tokenUazapi();
  if (! $token) { return 502 'Nenhum canal vinculado'; }
  match(...) { ... $this->uazapi->enviarImagem($token, ...) ... }

  por:
  if (! $model->canal) { return 502 'Nenhum canal vinculado'; }
  match(...) { ... $model->canal->servico()->enviarImagem($model->canal, ...) ... }
```

### 4.1 Áudio: nota de voz (`voice`) só quando o formato permite

O upload no painel aceita `mimes:mp3,ogg,webm,m4a,wav` (validação já existente em `KanbanController::enviarMidia`). A Covercut exige `.ogg` (opus) pra renderizar como nota de voz. `CovercutChannelService::enviarAudio()` decide `voice` pela extensão do arquivo na URL:

- `.ogg` → `voice: true` (nota de voz, mesmo efeito visual que a Uazapi sempre produz)
- qualquer outro formato aceito → `voice` omitido (mensagem de áudio comum, anexo reproduzível)

Isso é uma pequena diferença do comportamento da Uazapi (que sempre manda como nota de voz, independente do formato, porque o protocolo Baileys não tem essa restrição) — mas é o que o formato real permite sem arriscar a Meta rejeitar ou renderizar errado um arquivo não-opus marcado como voice note.

## 5. Tratamento de erros e janela de conversa

Mesmo padrão já usado em `enviarTexto()`, reaplicado aos 4 métodos novos:

- Fora da janela de 24h/72h (`ticket.janela_expira_em` expirado) → bloqueia, loga warning, retorna `false`. Mídia está sujeita à mesma regra de janela de mensagens livres da Meta que o texto já respeita.
- Canal sem `phone_number_id` configurado → loga warning, retorna `false`.
- Exceção de rede (`Http::post` lança `ConnectionException`) → capturada, loga warning, retorna `false`. Nunca lança.
- Resposta HTTP não-2xx da Covercut → loga warning com status + corpo, retorna `false`.
- `KanbanController::enviarMidia()` já tem o tratamento de "envio falhou" (deleta o arquivo do storage, retorna 502 "Falha ao enviar pelo WhatsApp") — nenhuma mudança necessária aí, só troca de quem é chamado.

## 6. Testes

- Novos testes de `CovercutChannelService` cobrindo os 4 métodos: caminho feliz (`Http::fake()` conferindo `type` e payload corretos pra cada tipo), bloqueio por janela expirada, canal sem `phone_number_id`, falha de rede/HTTP não lança exceção.
- Teste de áudio: `.ogg` gera `voice: true`; `.mp3`/outro formato não gera o campo `voice`.
- Teste de integração `KanbanController::enviarMidia`: enviar imagem/áudio/documento/sticker num ticket do canal Oficial agora retorna sucesso (201) em vez do 502 antigo — mocka `Http::fake()` pro endpoint da Covercut.
- Regressão: testes existentes de `KanbanController::enviarMidia` no canal Uazapi continuam passando sem alteração (delegação pra `UazapiService` já testada permanece idêntica).

## 7. Fora de escopo — pendências explícitas

- Envio de mídia em sequências automáticas do bot (`SequenciaMensagemJob`) — decisão explícita de deixar fora desta entrega.
- Botões interativos no canal Oficial.
- Upload via `media_id` da Meta (via link cobre a necessidade atual).
- T-ORIENTACAO-EXTRACAO e T-TRANSCRICAO-ENVIADO — pendências relacionadas mas distintas, tratadas em specs próprias.
