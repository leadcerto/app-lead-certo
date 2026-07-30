# Mídia (áudio/imagem) no Canal WhatsApp Oficial (Covercut) — Design Técnico

> Complementa `2026-07-27-canal-whatsapp-oficial-covercut-design.md` (que entregou o MVP texto-apenas do canal Oficial, já em produção). Este documento cobre a extensão pra processar áudio e imagem recebidos por esse canal — hoje descartados silenciosamente.

---

## 1. Contexto e problema

O canal Oficial (Covercut) foi ativado em produção em 2026-07-30, coexistindo com o canal Uazapi no mesmo número físico. Levantado pelo Leonardo no mesmo dia: leads que mandam áudio ou imagem por esse número não têm a mensagem processada — o `CovercutWebhookController` hoje **descarta silenciosamente** qualquer mensagem que não seja `type=text` (só loga, não cria nem `Mensagem` nem atualiza o ticket).

Efeito colateral descoberto: como a mensagem nem chega a existir no banco, o histórico da conversa fica com um buraco — quando o Estágio de silêncio dispara depois, a IA "não sabe" que o lead mandou algo, e a resposta sai fora de contexto.

**Esta entrega é 100% aditiva.** O canal Uazapi continua funcionando exatamente como está — cada número opera no canal ao qual está conectado, os dois convivem em paralelo.

## 2. Escopo

**Dentro do escopo:**
- Áudio → transcrição (reaproveita `MediaProcessorService::transcreverAudioBase64()`, já usado pelo Uazapi).
- Imagem → descrição pro contexto do bot + lista de itens identificados no card (reaproveita `descreverImagemComVisao()` e a lógica de extração de itens, já usadas pelo Uazapi).
- Mensagens de tipo não suportado pela própria Meta (`type: "unsupported"` — enquetes, pagamento, etc.) continuam só logadas — a Covercut documenta que o conteúdo real é irrecuperável via API.

**Fora do escopo (mesmo tratamento que o Uazapi já dá — não é regressão, é paridade):**
- Vídeo e documento: placeholder de texto simples, sem transcrição/análise real (igual já é hoje no Uazapi).
- Envio de mídia pelo canal Oficial (só recepção — envio de mídia oficial continua fora do MVP geral do canal).

## 3. Descoberta técnica principal

Diferente do Uazapi (Baileys), a mídia da API Oficial da Meta **já chega descriptografada do lado da Covercut** — não precisamos replicar a descriptografia E2E do protocolo WhatsApp (`MediaProcessorService::descriptografarMidiaWhatsApp()`, específica do Uazapi). Buscar o arquivo é uma única chamada HTTP autenticada.

### 3.1 Endpoint de download (confirmado nos docs reais da Covercut)

```
GET /api/v1/media/get?id={media_id}&from={phone_number_id}
Headers: X-API-Key, X-API-Secret (mesmas credenciais globais já em uso)
```

- `id`: o identificador de mídia do payload do webhook (ver 3.2).
- `from`: o `phone_number_id` do canal (já salvo em `whatsapp_canal.config['phone_number_id']`).
- **Decisão de design:** sempre usar `&mode=stream`. Sem esse parâmetro, o retorno seria a resposta JSON/base64 padrão da Covercut — mas o formato exato dessa resposta (nomes dos campos) não é capturado nos docs, só o modo stream é auto-descritivo (corpo = bytes brutos do arquivo, mime-type no header `Content-Type` da resposta HTTP). Usar sempre `mode=stream` elimina essa ambiguidade por completo — não precisa adivinhar nome de campo nenhum.

### 3.2 Formato do payload de entrada — inferido, não capturado nos docs

Os docs da Covercut não mostram um exemplo de webhook **de entrada** para áudio/imagem — só mostram o formato de **envio** (`message.image.id`, `message.audio.id`) e o texto de entrada (`message.type: "text"`). Por analogia direta com o formato de envio já documentado, e com o padrão estável e público da própria Meta Cloud API (da qual a Covercut é wrapper direto), a expectativa é:

```json
{
  "event": "message", "direction": "inbound",
  "message": {
    "id": "wamid...", "type": "image",
    "image": { "id": "media_id_aqui", "mime_type": "image/jpeg", "caption": "opcional" }
  }
}
```
```json
{
  "message": {
    "id": "wamid...", "type": "audio",
    "audio": { "id": "media_id_aqui", "mime_type": "audio/ogg; codecs=opus", "voice": true }
  }
}
```

**Decisão explícita do Leonardo:** construir já em cima dessa inferência (confiança alta), mas de forma **defensiva** — se os campos esperados não existirem no payload real, loga o payload bruto completo (em vez de falhar silenciosamente ou lançar exceção) para ajuste rápido assim que a primeira mensagem real chegar.

## 4. Arquitetura

Adiciona a `MediaProcessorService` um conjunto paralelo de métodos "Oficial", que buscam a mídia via Covercut mas **reaproveitam as mesmas funções de IA já existentes** (channel-agnostic — recebem bytes/URL, não sabem de onde vieram):

```
MediaProcessorService (existente, só adições — nenhum método Uazapi é alterado)
├── processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise): ?string
│     dispatch por $message['type'] (image/audio/video/document/unsupported)
├── private processarImagemOficial(...): string
│     → baixarMidiaCovercut() + descreverImagemComVisao() [REAPROVEITADO]
├── public extrairItensImagemOficial(...): ?string
│     → baixarMidiaCovercut() + helper de extração de itens
│       (extraído de dentro de extrairItensImagem() pra um método privado
│       compartilhado — único refactor desta entrega, sem mudar
│       comportamento externo do Uazapi)
├── private processarAudioOficial(...): string
│     → baixarMidiaCovercut() + transcreverAudioBase64() [REAPROVEITADO]
├── private processarVideoOficial / processarDocumentoOficial
│     → placeholder de texto, sem chamada de IA (paridade com Uazapi)
├── public baixarEPersistirUrlOficial(array $message, WhatsappCanal $canal, string $mediaType): ?string
│     → baixarMidiaCovercut() + salvarBytes() [REAPROVEITADO] — mídia local pro card
└── private baixarMidiaCovercut(string $mediaId, WhatsappCanal $canal): ?array
      → GET /media/get, retorna ['base64' => ..., 'mime' => ...] ou null
      → payload de resposta inesperado: loga bruto, retorna null (não lança)
```

`CovercutWebhookController` passa a chamar esses métodos no lugar do descarte atual, espelhando o fluxo que `UazapiWebhookController` já tem: processa mídia → monta `$conteudo`/`$tipoMensagem` → baixa e persiste `midia_url` local → pra imagem, acumula itens em `ticket.lista_itens`.

## 5. Tratamento defensivo (payload/resposta fora do esperado)

Dois pontos de incerteza justificam tratamento defensivo explícito, ambos resolvendo pra "loga e segue sem quebrar o restante do processamento da mensagem" (nunca lança exceção que derruba o webhook inteiro):

1. **Payload do webhook sem os campos esperados** (`message.image.id` ausente, etc.) — loga o payload bruto completo em warning, mensagem é tratada como se não tivesse mídia processável (mesmo fallback do Uazapi quando a URL de mídia não é encontrada: guarda a legenda, se houver, como conteúdo).
2. **`/media/get?mode=stream` retorna erro HTTP ou corpo vazio** — loga status + corpo (truncado) em warning, retorna `null` (chamador trata como "não conseguiu baixar", mesmo padrão de erro já usado em todo o `MediaProcessorService`). O `mode=stream` elimina a ambiguidade de formato (ver 3.1) — o único caso defensivo real aqui é falha HTTP, não formato inesperado de sucesso.

## 6. Testes

Segue o padrão já usado nos testes de `UazapiWebhookController`/`MediaProcessorService` — mocka `Http::fake()` pro endpoint `/media/get`, nunca chama a Covercut de verdade.

- Áudio processado corretamente → `Mensagem` criada com transcrição, `tipo='audio'`, `midia_url` preenchido.
- Imagem processada corretamente → `Mensagem` com descrição, `ticket.lista_itens` atualizado.
- Payload sem campo `image.id`/`audio.id` → loga warning, não lança exceção, mensagem tratada com fallback (legenda ou placeholder).
- Resposta do `/media/get` em formato inesperado → loga warning, retorna null, resto do fluxo do webhook continua normalmente.
- Vídeo/documento → placeholder de texto, sem chamada de IA.
- Tipo `unsupported` → só loga, nenhuma `Mensagem` criada (mesmo comportamento de hoje).
- Regressão: nenhum teste existente de `UazapiWebhookController`/`MediaProcessorService` muda de comportamento (o único refactor — extração do helper de itens — é coberto pelos testes já existentes do Uazapi, que devem continuar verdes sem alteração).

## 7. Fora de escopo — pendências explícitas

- Webhook de Alertas da Covercut (já adiado, sem relação com esta entrega).
- Envio de mídia/botões pelo canal Oficial.
- Análise real de vídeo/documento (paridade com o Uazapi atual, não uma melhoria nova).
