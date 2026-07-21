# Reconciliação de Mensagens após Desconexão do WhatsApp — Design

**Goal:** Quando a instância WhatsApp (Uazapi) fica desconectada por um período relevante e volta a conectar, o sistema recupera automaticamente as mensagens que chegaram durante a queda — criando contato, ticket e card no Kanban pra quem não tinha, e registrando a conversa completa — sem depender de intervenção manual.

**Contexto:** O usuário relatou que quedas de conexão de horas ou dias fazem o sistema "não ler todas as conversas e não criar os cards/cadastros" quando a conexão volta. Investigação via `systematic-debugging` confirmou a causa raiz: `UazapiWebhookController::handleConexao()` (evento `EventType=connection`) só atualiza duas colunas de status no tenant — não existe nenhum mecanismo de reconciliação. O sistema depende 100% do webhook em tempo real (`EventType=messages`); mensagens que chegam enquanto a instância está desconectada nunca são recuperadas automaticamente hoje.

## APIs da Uazapi usadas (pesquisadas em `docs.uazapi.com/openapi-bundled.json`, spec v2.1.1)

- **`POST /chat/find`** — lista chats da instância; suporta filtro por operador (`wa_lastMsgTimestamp >= X`) e ordenação. Usado pra descobrir quais chats tiveram mensagem nova durante a janela de desconexão.
- **`POST /message/find`** — busca mensagens já armazenadas localmente pela própria Uazapi (resposta imediata, não depende do celular estar ativo agora). Primeira tentativa, mais barata.
- **`POST /message/history-sync`** — pede ao WhatsApp pra reenviar histórico antigo de um chat específico (`number` obrigatório, `mode=history` por padrão, `messageid` opcional como âncora, `count` até 100). Resposta chega depois, assíncrona, via um novo evento de webhook `EventType=history`. A doc oficial avisa: *"a recuperação pode só acontecer depois de abrir o WhatsApp no celular ou deixá-lo ativo em segundo plano"* — usado só como fallback.
- Estrutura da mensagem (`Message` schema) é a mesma em todos os contextos (webhook ao vivo, `/message/find`, eventos `history`): `messageid`, `chatid`, `sender`, `senderName`, `fromMe`, `messageType`, `messageTimestamp` (inteiro, milissegundos), `text`.

## Decisões fechadas nesta sessão

1. **Limiar de disparo:** reconciliação só roda se o gap desconectado→conectado for **≥ 5 minutos**. Quedas rápidas (segundos/poucos minutos) não acionam nada — o webhook em tempo real já dá conta.
2. **Estratégia em cascata:** pra cada chat pendente, tenta `/message/find` primeiro (barato, não depende do celular); só recorre a `/message/history-sync` (mais lento, depende do celular ativo) se ainda faltar mensagem depois disso.
3. **Processamento em fila, não tudo de uma vez:** cada chat pendente vira um job (`ReconciliarChatJob`) numa fila dedicada, com delay escalonado — evita rajada de chamadas e risco de erro 429 (limite de taxa) da Uazapi numa queda grande com muitos chats.
4. **IA não responde mensagem recuperada:** o registro (contato, ticket, card, histórico) é criado normalmente, mas a IA (SDR bot) **não** dispara resposta automática nem inicia sequência — evita a IA responder dias depois como se fosse em tempo real, o que soaria fora de contexto pro lead. Fica pra um humano revisar e decidir a abordagem.
5. **Marcação visual:** cards criados/atualizados pela reconciliação ganham um badge visível no Kanban ("🕓 Recuperado — revisar") com a data real da mensagem, distinguindo de um lead que chegou agora.
6. **Arquitetura:** gatilho reativo na reconexão (Abordagem A) **+** varredura periódica de segurança (Abordagem C) — a varredura cobre o caso do próprio evento `connection` que avisa "reconectei" se perder (mesmo problema de confiabilidade, só que na notificação em vez da mensagem).

## Modelo de dados

**`tenants`** ganha 2 colunas:
```
whatsapp_desconectado_desde          timestamp, nullable
ultima_reconciliacao_verificada_em   timestamp, nullable
```
- `whatsapp_desconectado_desde`: marcado quando `handleConexao()` recebe status `close`/`connecting`/`timeout`; usado pra calcular o gap e como início da janela de busca. Limpo (`null`) ao reconectar.
- `ultima_reconciliacao_verificada_em`: marcado quando `IniciarReconciliacaoWhatsAppJob` termina com sucesso pra aquele tenant. Consultado pela varredura periódica.

**`tickets_atendimento`** ganha 1 coluna:
```
recuperado_via_reconciliacao   boolean, default false
```
Setado `true` quando o ticket é criado ou recebe mensagem através do fluxo de reconciliação (evento `history`). É o campo que aciona o badge no Kanban.

## Orquestração (jobs)

```
UazapiWebhookController::handleConexao()
  status ∈ {close, connecting, timeout}:
    tenant.update(whatsapp_status='disconnected', whatsapp_desconectado_desde=now())
  status === 'open':
    $gap = tenant.whatsapp_desconectado_desde ? now()->diffInMinutes(tenant.whatsapp_desconectado_desde) : 0
    tenant.update(whatsapp_status='connected', whatsapp_connected_since=now(), whatsapp_desconectado_desde=null)
    se $gap >= 5 → IniciarReconciliacaoWhatsAppJob::dispatch(tenant.id, $desconectadoDesdeOriginal)

IniciarReconciliacaoWhatsAppJob(tenantId, desconectadoDesde)
  $janela = max($desconectadoDesde, now()->subDays(tenant.retencao_conversas_dias ?? 30))
  POST /chat/find { sort: '-wa_lastMsgTimestamp', wa_lastMsgTimestamp: '>=' . $janela->timestamp, limit: 50, offset: paginado até esgotar }
  para cada chat retornado (já traz wa_lastMsgTimestamp do próprio /chat/find, sem precisar de nova consulta):
    ReconciliarChatJob::dispatch(tenant.id, chat.wa_chatid, chat.wa_lastMsgTimestamp)
      ->onQueue('reconciliacao')
      ->delay(now()->addSeconds($indice * 3))   // escalonado, 1 chat a cada 3s
  ao concluir a paginação: tenant.update(ultima_reconciliacao_verificada_em: now())

ReconciliarChatJob(tenantId, chatId, waLastMsgTimestamp)
  $localMaisRecente = POST /message/find { chatid: chatId, limit: 1 }  // já ordenado por mais recente
  se $localMaisRecente.messageTimestamp < $waLastMsgTimestamp (recebido do job pai, sem nova chamada a /chat/find):
    POST /message/history-sync { number: chatId, mode: 'history', count: 50 }
    // resultado chega depois via webhook EventType=history — não precisa aguardar aqui
```

Fila dedicada `reconciliacao`, separada de `default`, para não atrasar mensagens ao vivo durante um pico de reconciliação.

**Varredura de segurança:** novo command `whatsapp:verificar-reconciliacao`, agendado a cada 30 minutos:
```
para cada tenant com whatsapp_status='connected'
  E whatsapp_connected_since < now()->subMinutes(10)  // já devia ter tido tempo de reconciliar
  E (ultima_reconciliacao_verificada_em é null OU ultima_reconciliacao_verificada_em < whatsapp_connected_since):
    IniciarReconciliacaoWhatsAppJob::dispatch(tenant.id, tenant.whatsapp_connected_since->subMinutes(5))
```
Cobre o cenário em que o evento `connection` (status `open`) nunca chegou via webhook, mas a instância na Uazapi já está conectada de fato (detectável comparando com `UazapiService::status()` se necessário numa iteração futura — nesta primeira versão, a varredura confia no último `connection` recebido).

## Tratamento do evento `history` no webhook

```php
match ($tipo) {
    'messages'   => $this->handleMensagem($payload, $tenant),
    'connection' => $this->handleConexao($payload, $tenant),
    'history'    => $this->handleHistorico($payload, $tenant),   // novo
    default      => null,
};
```

`handleHistorico()` reaproveita a lógica de criação de contato/ticket/mensagem já existente em `processarMensagemLead()` — extraída para um método privado comum `registrarMensagemRecebida(Tenant, telefone, conteudo, pushName, msg, recuperado: bool)` — com as diferenças:

- `mensagens.enviado_em` usa `msg['messageTimestamp']` (ms → `Carbon::createFromTimestampMs()`), não `now()` — preserva a data real da mensagem.
- Ticket (novo ou existente) recebe `recuperado_via_reconciliacao = true`.
- **Não** dispara `SdrResponderJob` nem `SequenciaService::iniciarParaTicket()`.
- A trava de idempotência por `uazapi_message_id` (`unique`, já existente) protege contra duplicação se `history-sync` for chamado mais de uma vez pro mesmo chat.

## UI — Kanban

Card com `ticket.recuperado_via_reconciliacao = true` exibe badge "🕓 Recuperado — revisar" (cor âmbar, para diferenciar dos badges existentes) e a data real da última mensagem (não "agora"). Badge é só leitura nesta primeira versão — não existe ainda um botão de "marcar como revisado" (fora de escopo; se necessário, entra numa iteração futura).

## Novos métodos em `UazapiService`

```php
public function buscarChats(array $filtros): array   // POST /chat/find
public function buscarMensagens(string $chatId, int $limit = 1): array  // POST /message/find
public function solicitarHistorico(string $chatId, int $count = 50): array  // POST /message/history-sync
```

## Fora de escopo (explicitamente adiado)

- Botão de "marcar card como revisado" pra tirar o badge — fica manual (a equipe move o card normalmente).
- Reconciliação retroativa manual sob demanda (rodar pra uma data específica, fora do fluxo automático) — se necessário, vira um command à parte numa iteração futura.
- Verificação ativa do status real na Uazapi durante a varredura de segurança (hoje ela confia no último evento `connection` recebido) — melhoria possível, não crítica pra essa primeira versão.
- Tratamento de mensagens de grupo na reconciliação — mantém a mesma regra já existente hoje (`isGroup` é ignorado em `handleMensagem`), replicada em `handleHistorico`.

## Testes

- Unit/Feature: cálculo do gap em `handleConexao()` — dispara job só quando ≥ 5min, não dispara em quedas curtas.
- Feature: `IniciarReconciliacaoWhatsAppJob` pagina `/chat/find` corretamente e despacha um `ReconciliarChatJob` por chat, com delay escalonado.
- Feature: `ReconciliarChatJob` tenta `/message/find` primeiro; só chama `/message/history-sync` quando o resultado local ainda está desatualizado frente ao `wa_lastMsgTimestamp`.
- Feature: `handleHistorico()` cria contato/ticket/mensagem com `enviado_em` correto (do `messageTimestamp`, não `now()`), marca `recuperado_via_reconciliacao=true`, e **não** dispara `SdrResponderJob`/`SequenciaService`.
- Feature: idempotência — mesmo `uazapi_message_id` recebido duas vezes via `history` não duplica mensagem.
- Feature: varredura periódica detecta tenant conectado sem reconciliação recente e redispara o job.
- Feature (UI/manual, Playwright local): card com `recuperado_via_reconciliacao=true` mostra o badge corretamente.
