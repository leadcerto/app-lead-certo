# Canal WhatsApp Oficial via Covercut — Design Técnico

> Complementa o manual funcional `2026-07-25-cadastro-whatsapp-oficial-manual.md` (fluxo genérico de Embedded Signup, sem schema/código). Este documento cobre a integração técnica concreta com a **Covercut** (`api.covercut.com.br`), parceira BSP da Meta escolhida para operar a API Oficial do WhatsApp da Lead Certo.
>
> **Atualizado em 2026-07-29**, depois da entrega do suporte a múltiplos números não-oficiais (`docs/superpowers/plans/2026-07-27-canal-whatsapp-multinumero-kanban.md`, já em produção) e de confirmação direta com o Leonardo + consulta à documentação real da Covercut (`api.covercut.com.br/docs/#configuracao` — ver memória `referencia-docs-covercut`). Substitui as suposições da versão anterior (fluxo de widget embutido) pelo mecanismo real, mais simples.

---

## 1. Contexto e objetivo

A Lead Certo já suporta múltiplos números de WhatsApp **não-oficiais** (Uazapi/Baileys, QR Code) por tenant, vinculados a Kanbans — entregue e em produção. Esta entrega adiciona o segundo tipo de canal já previsto na modelagem: a **API Oficial do WhatsApp** (Meta Cloud API), operada através da Covercut, para receber os leads gerados por anúncios "clique para o WhatsApp".

### Decisão estratégica de uso do canal oficial

O canal oficial será usado **exclusivamente em modo de recepção/resposta**:
- Nunca dispara mensagem proativamente (nem sequência automática, nem campanha).
- Só responde quem iniciou a conversa (lead que clicou no anúncio ou mandou mensagem).
- **Não usaremos templates pagos da Meta.** Uma vez fechada a janela de conversa (24h, ou 72h quando a conversa se origina de anúncio), o sistema **não tenta reabrir** — o envio é bloqueado e o ticket é sinalizado para atenção humana, se necessário, por outro canal.

A prospecção continua sendo feita pelos números não-oficiais (Uazapi), sem nenhuma mudança.

### Escopo desta entrega (MVP)

**Dentro do escopo:** conectar um número oficial já cadastrado na Covercut + receber mensagem real do lead + responder dentro da janela de conversa + rotear para o Kanban certo.

**Fora do escopo — pendências explícitas, não esquecidas, só adiadas** (ver seção 8 para a lista completa):
- Webhook de Alertas da Covercut (qualidade do número, suspensão, assinatura).
- Buscar números automaticamente via API da Covercut (fica manual: colar `phone_number_id`).
- Templates de mensagem, rodízio ponderado por maturidade de número, limpeza dos campos legados em `tenants`.

---

## 2. Modelo de negócio dos provedores (Uazapi e Covercut)

Uazapi e Covercut são prestadoras de serviço para a Lead Certo — a Lead Certo mantém **uma conta em cada uma** (credenciais globais, no `.env`, iguais ao padrão já usado para `UAZAPI_KEY`), e cada franqueado (tenant) tem seus **próprios números exclusivos** dentro dessas contas compartilhadas.

**Confirmado com o Leonardo (2026-07-29): o cadastro de cada número oficial na Covercut é manual, feito por ele diretamente no painel deles** (`api.covercut.com.br/dashboard`) — não existe (nem é necessário construir) um fluxo de provisionamento programático a partir do Lead Certo. O papel do Lead Certo é **adotar** um número que já existe do lado da Covercut, não criá-lo.

---

## 3. Modelo de dados

### 3.1 Tabela `whatsapp_canais` — já existe, sem migration nova aqui

Criada na entrega anterior, já suporta os dois tipos:

```
whatsapp_canais
  id
  tenant_id          FK -> tenants
  tipo               string('oficial' | 'nao_oficial')
  provider            string('covercut' | 'uazapi')
  status             string('connected' | 'connecting' | 'disconnected')
  phone              string, nullable
  connected_since    timestamp, nullable
  config             json   -- segredos/campos específicos do provider:
                             --   uazapi: instance_name, instance_token, webhook_token
                             --   covercut: phone_number_id, webhook_secret
  webhook_token      string, nullable, unique  -- só usado por canais 'uazapi' (token na URL); canais 'covercut' não usam este campo, ver seção 6
  timestamps
```

Nenhuma alteração de schema necessária nesta tabela. `tipo='oficial'` e `provider='covercut'` já são valores válidos, só nunca populados ainda.

### 3.2 Alterações em `tickets_atendimento` (migration nova, aditiva)

```
+ janela_expira_em      timestamp, nullable   -- só usado quando canal.tipo === 'oficial'
+ janela_origem_anuncio boolean, default false -- true = janela de 72h (veio de anúncio), false = 24h
```

`whatsapp_canal_id` já existe (entrega anterior) — é o mesmo campo que resolve o canal do ticket, oficial ou não-oficial.

### 3.3 Alterações em `mensagens`

```
uazapi_message_id  →  provider_message_id
```

Generalização do campo de deduplicação de eventos de webhook, hoje nomeado especificamente para Uazapi, para servir aos dois provedores.

### 3.4 Tabela pivot `kanban_whatsapp_canais` — já existe, reaproveitada sem mudança

Um canal oficial se vincula a um Kanban exatamente do mesmo jeito que um canal não-oficial já se vincula hoje (`kanban.config`, entrega anterior). Nenhuma mudança de schema ou de UI de vínculo — só passa a listar canais `tipo='oficial'` também, que hoje nunca existem.

---

## 4. Regra de negócio: janela de conversa

- Toda mensagem inbound recebida pelo canal oficial atualiza `janela_expira_em` do ticket correspondente:
  - `+24h` por padrão.
  - `+72h` se o payload do webhook trouxer dados de referral de anúncio (ex: `ctwa_clid` / objeto `referral` do Cloud API) — nesse caso `janela_origem_anuncio = true`.
- Cada nova mensagem do lead **reinicia** a contagem (recalcula `janela_expira_em` a partir de agora).
- Qualquer tentativa de envio pelo canal oficial passa por uma checagem prévia: se `now() > janela_expira_em`, o envio é **bloqueado** — sem fallback de template, sem retry automático. Loga o bloqueio e sinaliza o ticket para atenção humana.
- Não há, nesta entrega, nenhuma tela de configuração de templates — está fora de escopo porque a decisão de negócio é não usar templates.

---

## 5. Camada de serviço

Introduzir uma interface pequena, `CanalWhatsappInterface`, com os métodos que hoje o `UazapiService` expõe para envio (`enviarTexto` no mínimo — botões/mídia podem ficar para uma iteração seguinte se a Cloud API/Covercut exigir formato muito diferente, ver pendência na seção 8), implementada por:

- `UazapiChannelService` — wrapper fino do `UazapiService` existente (comportamento inalterado, usado pelos canais não-oficiais).
- `CovercutChannelService` — novo, fala com a API da Covercut (`X-API-Key`/`X-API-Secret`, base `https://api.covercut.com.br/api/v1`), e aplica a checagem de janela (seção 4) antes de qualquer envio.

Consumidores que hoje resolvem `$ticket->canal->tokenUazapi()` diretamente (`SdrResponderService`, `KanbanController::enviarMensagem`, etc. — lista completa dos pontos já migrados na entrega anterior) passam a resolver `$ticket->canal` e delegar à implementação correta pelo `provider` do canal, em vez de assumir Uazapi.

### 5.1 Seleção de número: vínculo por Kanban (reaproveitado sem mudança)

- **Não-oficial**: sorteio aleatório entre os canais vinculados ao Kanban (já implementado).
- **Oficial**: como o canal oficial só responde (nunca prospecta), o vínculo ao Kanban serve para **rotear** a mensagem inbound recebida por aquele número para o Kanban correto, quando o tenant tiver mais de um Kanban. Não participa do sorteio de prospecção.

---

## 6. Webhook de entrada

**Confirmado em 2026-07-29 direto na documentação/painel da Covercut** (`api.covercut.com.br/docs/#configuracao`):

- A Covercut expõe `POST /api/v1/numbers/webhook` (autenticado por `X-API-Key`/`X-API-Secret`) para registrar a URL de callback de um número específico: `{ "from": "<phone_number_id>", "webhook_url": "...", "enabled": true }`. A resposta traz o `webhook_secret` gerado para aquele número — vai para `whatsapp_canais.config.webhook_secret`.
- Existe também `GET /api/v1/numbers/webhook?from=<phone_number_id>` (consultar) e remoção (`webhook_url` vazio ou `{"action":"delete"}`).
- Existe uma "Configuração Geral (Fallback)" no painel da Covercut (URL usada por qualquer número sem URL específica) — **não usamos o fallback**: registramos explicitamente a mesma URL fixa (decisão da seção 6.1) em cada número, no momento em que ele é adotado no Lead Certo.
- Autenticação de cada evento recebido: headers `X-BSP-Signature` (HMAC-SHA256 de `hash_hmac('sha256', $payload_bruto, $webhook_secret)`) + `X-BSP-Timestamp`.
- Payload de mensagem inbound confirmado: `{ event: "message", direction: "inbound", contact: { wa_id, user_id, name }, message: { id, type, text } }`. A documentação pode ter mais campos por tipo de mensagem (mídia, botão, referral de anúncio) — conferir `api.covercut.com.br/docs/#configuracao` na hora de escrever o parser, não assumir do que está resumido aqui.

### 6.1 Decisão: URL de webhook única para todo o sistema

Ao contrário da Uazapi (token opaco embutido na própria URL, uma URL por instância), o canal oficial usa **uma única URL fixa** para todos os números oficiais, de todos os tenants (ex: `POST /api/webhook/covercut`). O `CovercutWebhookController`:

1. Lê `phone_number_id` (campo `to`, ou equivalente — confirmar nome exato no payload real na hora de implementar) do evento recebido.
2. Busca o `WhatsappCanal` cujo `config->phone_number_id` bate com esse valor (`provider='covercut'`).
3. Só então valida `X-BSP-Signature` usando o `webhook_secret` **daquele canal específico** — a validação não pode rodar antes de saber qual canal é, já que o segredo é por número.
4. Se nenhum canal corresponder, ou a assinatura não bater, rejeita (401/404) e loga — mesmo espírito do fallback/rejeição já usado no webhook da Uazapi.

Menos pontos de configuração (a URL nunca muda, mesmo número novo), ao custo de resolver o canal pelo conteúdo do payload em vez de pela URL — igual ao trade-off já aceito na Uazapi quando um fallback de token legado foi adicionado.

A lógica de negócio comum — criar/atualizar `Contato` e `TicketAtendimento`, salvar `Mensagem`, disparar SDR, avançar kanban — é extraída do `UazapiWebhookController` para um serviço compartilhado, chamado pelos dois controllers de webhook com o payload já normalizado. Evita duplicar a lógica de negócio entre os dois canais.

---

## 7. Fluxo de conexão (UI)

Reaproveita a tela de Configurações → WhatsApp já reformulada na entrega anterior (lista de números, não mais "1 card de status"). Ganha uma nova seção:

- **Seção "WhatsApp Oficial (Business API)"**: lista dos números oficiais já conectados (apelido, telefone, status) + botão "Conectar número oficial" → **formulário simples** (não widget): campos `phone_number_id` (colado do painel da Covercut), telefone, apelido. Ao salvar:
  1. Backend chama `POST /api/v1/numbers/webhook` na Covercut com a URL fixa (seção 6.1) para aquele `phone_number_id`.
  2. Guarda o `webhook_secret` retornado em `whatsapp_canais.config`.
  3. Cria a linha em `whatsapp_canais` (`tipo='oficial'`, `provider='covercut'`, `status='connected'`).
- Cada número da lista tem uma ação de remover, que desregistra o webhook na Covercut (`{"action":"delete"}`) e apaga a linha — **mesmo aviso reforçado de "isso é irreversível" que já foi identificado como pendência para o botão de remover não-oficial** (ver memória `arquitetura-canais-whatsapp`: o botão de remover hoje só tem um `confirm()` genérico do navegador).

A atribuição de qual(is) Kanban(ns) cada número oficial atende continua na tela `kanban.config` (seção 5.1), sem mudança nenhuma na UI de lá.

---

## 8. Fora de escopo (combinado nesta rodada — pendências explícitas)

- **Webhook de Alertas da Covercut** (qualidade do número, suspensão, assinatura — endpoint separado no painel deles). Fica para uma entrega futura, depois que o canal oficial estiver funcionando de verdade em produção.
- **Buscar números automaticamente via API da Covercut** (dropdown em vez de colar `phone_number_id` manualmente) — só vale a pena se/quando confirmarmos que existe um endpoint de listagem; por ora, formulário manual resolve.
- **Envio de mídia/botões pelo canal oficial** — o MVP cobre texto; se a Cloud API/Covercut exigir formato muito diferente do `UazapiService` para mídia/botões, essa parte pode virar uma iteration própria depois de validar texto em produção.
- Templates de mensagem (marketing/utilidade) para reengajamento fora da janela.
- Rodízio ponderado por maturidade/capacidade de cada número não-oficial — já era pendência da entrega anterior, continua.
- Migração completa para fora da Uazapi — Uazapi continua sendo a via de prospecção.
- Reforço do aviso de confirmação no botão "Remover" (canal oficial e não-oficial) — pendência identificada, não bloqueia esta entrega.

---

## 9. Referências

- Manual funcional: `docs/superpowers/specs/2026-07-25-cadastro-whatsapp-oficial-manual.md`
- Plano da entrega anterior (não-oficial, já em produção): `docs/superpowers/plans/2026-07-27-canal-whatsapp-multinumero-kanban.md`
- Regras de humanização/anti-ban (canal não-oficial): `leadcerto-whatsapp-regras/regra-geral-de-envio-de-mensagens-no-whatsapp.md`
- Documentação técnica Covercut (fonte viva, consultar sempre antes de implementar): `api.covercut.com.br/docs/#configuracao`
