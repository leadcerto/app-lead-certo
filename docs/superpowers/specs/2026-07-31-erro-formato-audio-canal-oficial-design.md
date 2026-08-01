# Erro claro pra áudio incompatível no canal Oficial (Covercut) — Design Técnico

> Follow-up da entrega `2026-07-31-envio-midia-canal-oficial-design.md`. A revisão final daquela entrega achou que áudio gravado no painel do Kanban pode sair em `.webm`, formato que a Meta Cloud API não aceita — o envio falha com o erro genérico "Falha ao enviar pelo WhatsApp", sem explicar o motivo real ao atendente.

## 1. Contexto e problema

O gravador de áudio do painel (`resources/views/kanban/index.blade.php:1032-1034`) tenta `audio/webm;codecs=opus` primeiro — suportado pela maioria dos navegadores. A Meta Cloud API (canal Oficial/Covercut) só aceita áudio em `aac`, `amr`, `mpeg` (mp3), `mp4` (m4a) ou `ogg` (opus) — `webm` não está nessa lista. `wav`, também aceito hoje pelo upload do painel, também não está.

**Decisão explícita do Leonardo em 2026-07-31:** não instalar `ffmpeg`/converter áudio no servidor agora (adicionaria dependência nova de infraestrutura). Só trocar o erro genérico por um específico, avisando o atendente do motivo real — sem resolver a gravação em si.

## 2. Escopo

**Dentro do escopo:**
- Validação em `KanbanController::enviarMidia()`: quando `tipo === 'audio'` e o canal do ticket é `provider === 'covercut'`, checar a extensão do arquivo enviado contra o que a Meta aceita. Se não aceito, retorna 422 com mensagem específica, sem salvar o arquivo no storage.

**Fora do escopo:**
- Conversão/transcodificação de áudio no servidor (decisão explícita de não fazer agora).
- Qualquer mudança no gravador do painel (JS) ou na Uazapi (canal não-oficial não tem essa restrição — Baileys aceita webm).
- Mudanças em `CovercutChannelService` ou `CanalWhatsappInterface` (nenhuma delas precisa saber dessa regra — é validação de entrada, específica do endpoint do painel).

## 3. Regra de validação

Formatos hoje aceitos pelo upload do painel (`KanbanController.php`, regra `mimes:mp3,ogg,webm,m4a,wav`): `mp3`, `ogg`, `webm`, `m4a`, `wav`.

Subconjunto aceito pela Meta pra mensagens de áudio: `mp3`, `ogg`, `m4a`. Os outros dois (`webm`, `wav`) formam o conjunto rejeitado — únicos que disparam o novo erro, e só quando o canal é Covercut.

```php
private const AUDIO_EXTENSOES_ACEITAS_COVERCUT = ['mp3', 'ogg', 'm4a'];
```

## 4. Mensagem de erro

```
"O canal Oficial (WhatsApp Business) não aceita áudio nesse formato (.{ext}). Anexe um arquivo de áudio nos formatos .mp3, .ogg ou .m4a."
```

Retornada como `response()->json(['message' => ...], 422)` — o frontend já exibe `json.message` num `alert()` (linha 1103 de `kanban/index.blade.php`), nenhuma mudança de JS necessária.

## 5. Testes

- Ticket em canal Covercut + upload de áudio `.webm` → 422, mensagem específica, nenhum arquivo criado em `storage/public/kanban-midia`, nenhuma chamada HTTP disparada (`Http::assertNothingSent()`).
- Ticket em canal Covercut + upload de áudio `.ogg`/`.mp3`/`.m4a` → continua funcionando normalmente (regressão, usa o teste já existente de `KanbanEnviarMidiaCanalOficialTest`).
- Ticket em canal Uazapi + upload de áudio `.webm` → continua funcionando normalmente, sem a nova validação (regressão, `KanbanEnviarMidiaFigurinhaTest`/testes existentes da Uazapi não podem mudar de comportamento).
