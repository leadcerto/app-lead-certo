# Guardrails de Resposta — Design Técnico

> Bloco 3 de 4, decompostos a partir de `2026-08-06-regras-atendimento-ia-humano-contexto.md`
> (Regras 2, 5, 6, 7, 9). Depende do Bloco 1 (`2026-08-06-alerta-interno-agente-design.md`,
> já em produção) para notificar o humano quando o agente pausa com dúvida.

## 1. Contexto e problema

Hoje o agente sempre responde, mesmo quando a pergunta do lead está fora do seu
escopo ou da base de conhecimento configurada — o risco é uma resposta errada ou
inventada. Este bloco dá ao agente um jeito seguro de pausar, pedir orientação
a um humano, e só então responder de verdade — sem nunca perder o controle da
conversa.

**Decisão de arquitetura fechada com o Leonardo:** o humano orienta por um campo
dedicado, separado do chat normal do card. Isso importa porque hoje **qualquer
mensagem mandada pelo chat normal já assume a conversa inteira**
(`KanbanController::enviarMensagem()` → `assumirAutomaticamente()`, que seta
`agente_responsavel = 'humano'` e trava `vendedor_id`). Se o humano respondesse
pelo chat normal, a IA perderia o controle mesmo pra uma dúvida pontual — o
campo dedicado evita isso: o agente lê a orientação, monta a resposta e manda
ele mesmo, sem nunca soltar as rédeas da conversa.

## 2. Escopo

**Dentro do escopo:**
- Regra 7 (autovalidação): instrução de prompt, 1 chamada de IA só (decisão já
  fechada — sem chamada dupla).
- Regra 2 (pausa e pede orientação): detecção do token de dúvida, criação de
  alerta (Bloco 1), campo dedicado de orientação no card, resposta de verdade
  ao lead depois de orientado.
- Regra 9 (lead insiste durante a espera): mensagem única configurável por
  coluna, não repete.
- Regra 5 (não perguntar o que já foi respondido) e Regra 6 (proibição de eco):
  reforço de prompt, sem mudança de schema/lógica.

**Fora do escopo:**
- Bloco 4 (Regras 3, 12, 13-parte-nova) — monitoramento proativo, ainda sem spec.
- Qualquer mudança no fluxo de mensagem normal do card (`enviarMensagem()`) —
  o campo de orientação é um endpoint novo e separado.
- Estruturar a "base de conhecimento" pra aprender com respostas passadas de
  dúvidas — o próprio `AlertaInterno` respondido já serve de registro (mesmo
  raciocínio do Bloco 2 pra Regra 1); o loop de aprendizado de verdade é a
  Frente 3 da base de conhecimento, ainda sem design.

## 3. Modelo de dados

```
tickets_atendimento
├── aguardando_orientacao_em    (timestamp, nullable)   [NOVO]
│   Não-nulo = agente pausado, esperando orientação. Limpo quando o humano
│   responde (ou se o ticket mudar de coluna — ver seção 6).
└── mensagem_espera_enviada     (boolean, default false) [NOVO]
    Controla a Regra 9 — evita repetir a mensagem de espera a cada nova
    mensagem do lead durante a mesma pausa.

alertas_internos                                        [Bloco 1, campos novos]
├── resposta      (text, nullable)   [NOVO]
└── respondido_em (timestamp, nullable) [NOVO]
    Só usados por alertas do tipo 'duvida_ia' — os demais tipos (já existentes
    desde o Bloco 1/2) simplesmente nunca preenchem esses campos. Reaproveita
    a tabela existente em vez de criar uma nova — a "resposta a um alerta" é
    conceitualmente parte do próprio alerta, não uma entidade separada.

kanban_coluna_configs
└── aguardando_orientacao_mensagem (text, nullable)      [NOVO]
    Mensagem padrão da Regra 9, configurável por coluna — mesmo padrão de
    `auto_mover_mensagem`. Fallback genérico se não configurada (ex: "Estou
    verificando mais detalhes sobre isso pra te dar a melhor resposta. Em
    breve retorno!").
```

## 4. Mecanismo — pausa e alerta

`SdrResponderService`: o system prompt ganha uma instrução de autovalidação
antes de finalizar a resposta — relevância, escopo, consistência com o
histórico (Regra 7). Se qualquer critério falhar, o agente responde só com o
token `[DUVIDA: <resumo curto>]` em vez de uma resposta normal.

`responder()` ganha uma nova varredura de token (mesmo padrão já usado pros
tokens de movimento de coluna): ao detectar `[DUVIDA:...]`:
- Nada é enviado ao lead nesse turno.
- `$ticket->update(['aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => false])`.
- `AlertaInternoService::criar($ticket->tenant_id, 'duvida_ia', <título>, <resumo da dúvida>, $ticket->id)`.

## 5. Mecanismo — Regra 9 (lead insiste)

No ponto de entrada de mensagem do lead (webhook), antes de despachar o job
normal do agente: se `aguardando_orientacao_em` está preenchido, o agente **não
responde normalmente** nesse turno (mensagem do lead é salva no histórico
normalmente, só não dispara `SdrResponderJob`). Se `mensagem_espera_enviada`
ainda for falso, envia a mensagem configurada (ou o fallback) e marca como
enviada — nunca repete enquanto a mesma pausa durar.

## 6. Mecanismo — humano orienta

Painel novo no card do ticket (não no dropdown do alerta — pequeno demais pra
digitar orientação com contexto), visível quando `aguardando_orientacao_em`
está preenchido: mostra o resumo da dúvida (do próprio alerta) + textarea +
botão enviar.

Ao submeter (endpoint novo):
1. Salva `resposta`/`respondido_em` no `AlertaInterno` correspondente (o mais
   recente do tipo `duvida_ia` pra esse ticket sem resposta ainda).
2. Despacha o agente de novo, com a orientação injetada no contexto — mesmo
   mecanismo já usado pro marcador `[Atendente humano respondeu]:` (sessão de
   05/08) — algo como `[Orientação do atendente]: <texto>`. O agente monta a
   resposta de verdade com base nisso e manda pro lead.
3. Limpa `aguardando_orientacao_em`/`mensagem_espera_enviada` — o ticket sai
   do estado de espera.

**Mudança de coluna durante a espera:** se o ticket mudar de coluna enquanto
aguarda orientação (manual ou automático), a pausa é descartada — `update()`
também limpa `aguardando_orientacao_em`/`mensagem_espera_enviada` (mesmo
raciocínio já usado no Bloco 1 pro reset de `objetivos_cumpridos` ao mudar de
coluna). Evita um alerta órfão amarrado a um contexto que já não existe mais.

## 7. Regras 5 e 6 — só prompt

Duas instruções novas no system prompt de `SdrResponderService`, sem mudança
de schema nem lógica:
- Nunca repetir literalmente o que o lead acabou de escrever (Regra 6) —
  reformulação com valor agregado é permitida, cópia pura não.
- Nunca perguntar algo já marcado como objetivo cumprido no checklist da
  coluna (Regra 5, Frente 1 da base de conhecimento) — o bloco de objetivos já
  injetado no prompt (Frente 1) já contém essa informação, só falta a
  instrução explícita de não perguntar de novo o que já está ✅.

## 8. UI

**Card do ticket:** indicador visual de "Aguardando orientação" quando
`aguardando_orientacao_em` está preenchido (badge/destaque, mesmo padrão
visual já usado pra outros estados do card). Painel de orientação (seção 6)
aparece só nesse estado.

**Config da coluna:** campo de texto pra `aguardando_orientacao_mensagem`, na
mesma seção "Agente de IA" dos outros textos configuráveis por coluna.

## 9. Tratamento de erros e casos extremos

- `[DUVIDA:...]` detectado mas `AlertaInternoService::criar()` falha → mesmo
  padrão do Bloco 2 (Regra 1): a pausa em si (`aguardando_orientacao_em`) não
  deve depender do alerta ter sido criado com sucesso — logar e seguir.
- Múltiplas dúvidas na mesma pausa (token `[DUVIDA:...]` nunca deveria disparar
  de novo enquanto já está aguardando — mas se acontecer) → não cria alerta
  duplicado; a varredura de token só roda quando o agente de fato gera uma
  resposta, e enquanto `aguardando_orientacao_em` está preenchido a Regra 9
  impede o agente de ser chamado normalmente, então esse caso não é alcançável
  no fluxo normal.
- Humano orienta um ticket que já não está mais aguardando (ex: reassumiu por
  timeout do Bloco 2 nesse meio tempo, ou mudou de coluna) → endpoint retorna
  erro claro (não processa uma orientação órfã).
- Ticket reassumido pelo Bloco 2 (timeout de humano em silêncio) enquanto
  aguardava orientação de uma dúvida → **não se aplica**: `aguardando_orientacao_em`
  preenchido significa que quem está no controle continua sendo o agente
  (`agente_responsavel` nunca muda pra `'humano'` nesse fluxo) — os dois
  mecanismos não se cruzam.

## 10. Testes

- Token de dúvida pausa o ticket sem enviar mensagem ao lead, cria alerta com
  `ticket_id` correto.
- Lead escreve durante a pausa → mensagem única de espera, não dispara
  `SdrResponderJob`.
- Lead insiste de novo → não repete a mensagem de espera, mensagem salva no
  histórico normalmente.
- Humano orienta → agente responde de verdade ao lead usando o contexto da
  orientação; estado de espera limpo.
- Orientar um ticket que não está mais aguardando → erro claro, nada é enviado.
- Mudar de coluna durante a espera → estado de espera limpo automaticamente.
- Regra 6: teste de prompt garantindo a instrução de anti-eco está presente
  no system message.
- Regra 5: teste garantindo que objetivos já cumpridos aparecem marcados no
  prompt com instrução de não re-perguntar (complementa os testes já
  existentes do bloco de objetivos, Frente 1 da base de conhecimento).

## 11. Fora de escopo — pendências explícitas

- Bloco 4 (Regras 3, 12, 13-parte-nova) — monitoramento proativo, ainda sem
  spec própria.
- Loop de aprendizado contínuo (Frente 3 da base de conhecimento) — a resposta
  do humano numa dúvida não vira conhecimento reaproveitável em conversas de
  outros leads ainda, só resolve a conversa atual.
