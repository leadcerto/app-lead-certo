# Ajustes de Integração Pós-13-Regras — Design Técnico

> Bloco 5, fora da numeração original das 13 regras. Nasceu de uma auditoria
> holística feita depois dos Blocos 1-4 (todos em produção), revisando os 4
> juntos em vez de isoladamente — achados de integração que nenhuma revisão
> por bloco tinha visão completa pra pegar. Referência:
> `docs/superpowers/specs/2026-08-06-regras-atendimento-ia-humano-contexto.md`
> e as specs dos Blocos 1-4.

## 1. Contexto e problema

Cada um dos 4 blocos passou por revisão individual e revisão final de branch,
mas nenhuma revisão viu os 4 juntos depois de todos em produção. A auditoria
achou 5 pontos de fricção reais, todos já com direção confirmada com o
Leonardo:

1. `KanbanController::encerrar()` não marca a origem da migração como
   humana, diferente de `mover()`/`moverParaOutros()` — o caminho de
   encerramento mais usado do sistema mente pra auditoria de "quem moveu"
   (Regra 13).
2. O painel de alertas (Bloco 1) trata todos os tipos genericamente — com
   volume real, `duvida_ia` (acionável, lead esperando) pode sumir da lista
   de 20 mais recentes atrás de `ticket_travado` (informativo, gerado em
   volume pelo Bloco 4).
3. `SdrResponderService::responder()` retorna `null` tanto pra "pausou
   aguardando orientação" quanto pra "canal recusou o envio" — o
   `FollowupConversas` (comando pré-existente) não distinguia os dois antes
   do Bloco 3, e a correção do Bloco 3 (não avançar `followup_estagio_enviado`
   em nenhum `null`) removeu sem querer o teto de tentativas que existia pro
   segundo caso, fazendo a IA tentar de novo pra sempre a cada 5min.
4. A pausa da Regra 2 (`aguardando_orientacao_em`) não tem prazo de validade
   — se ninguém responde o alerta, o ticket fica pausado indefinidamente, e
   se a coluna muda antes de alguém responder o `AlertaInterno`
   correspondente fica órfão (nunca recebe resposta, endpoint de orientar
   retorna 422 pra sempre).
5. O guardrail de salto de coluna (Bloco 4) exclui colunas de papel
   Encerramento/TransferenciaHumana do cálculo de salto pra não gerar ruído
   em fechamento automático por silêncio e reabertura — mas isso também
   apagou o caso mais consequente que a Regra 13 original queria pegar: a
   IA pulando direto pra Encerrado por engano, via decisão real dela (não
   política automática), também não alerta mais.

## 2. Escopo

**Dentro do escopo:** os 5 achados acima, cada um como task(s) isolada(s).

**Fora do escopo:** os achados menores da auditoria (reset de
`origemMudancaColuna`, otimização de queries do guardrail, deep-link do
alerta pro ticket, horário comercial do `kanban:monitorar`, unidade "dias"
na UI do timeout de reassunção) — baixo valor, deixados de fora a não ser
que apareçam naturalmente durante a implementação de algum dos 5 itens
acima. Registro em base de conhecimento (pendência transversal a 5 das 13
regras originais) — segue fora do escopo de todos os blocos até agora,
inclusive este.

## 3. Achado 1 — `encerrar()` marca origem humana

Uma linha em `KanbanController::encerrar()`
(`app/Http/Controllers/Painel/KanbanController.php:268`), mesmo padrão de
`mover()`/`moverParaOutros()`:

```php
$model = TicketAtendimento::findOrFail($ticket);

$model->origemMudancaColuna = 'humano';
$model->update($model->dadosParaEncerrar([...]));
```

## 4. Achado 2 — painel prioriza dúvidas não respondidas

`AlertaInternoController::index()` passa a buscar em duas partes: todas as
`duvida_ia` com `resposta` nula primeiro (sem entrar no limite de 20),
completando com os demais tipos por `created_at` desc até fechar 20 no
total. Dúvida pendente nunca sai da lista por volume de outros alertas. Só
muda a query — resposta JSON, UI (`layouts/app.blade.php`) e os outros dois
endpoints (`marcarLido`/`marcarTodosLidos`) continuam iguais.

## 5. Achado 3 — teto de tentativas de envio

**Modelo de dados:**
```
tickets_atendimento
└── tentativas_envio_falhas (integer, default 0) [NOVO]
    Conta falhas seguidas de "canal recusou o envio". Zerado sempre que uma
    mensagem é enviada com sucesso (nesse ponto o valor já não importa mais
    pro ciclo atual). Não é resetado por pausa (Regra 2) nem por outros
    `null` — só cresce quando `responder()` retorna `null` especificamente
    pelo motivo "canal recusou".
```

**Mecanismo:** `SdrResponderService::responder()` já distingue internamente
os motivos de retornar `null` (são `return null;` em pontos diferentes do
método) — mas hoje devolve só `null` pro chamador, perdendo essa distinção.
Solução mais simples e menos invasiva, sem mudar a assinatura de retorno: o
**próprio** `SdrResponderService::responder()` incrementa
`tentativas_envio_falhas` no ponto exato onde hoje já loga "envio não
confirmado pelo canal" (`SdrResponderService.php:178-186`), e zera o
contador no ponto onde o envio é confirmado com sucesso. `FollowupConversas`
não precisa saber o motivo do `null` — só verifica, depois da chamada, se
`tentativas_envio_falhas >= 3`; se sim, não tenta de novo nesse ciclo (loga
e segue, sem chamar `responder()` de novo pra esse ticket) **e** cria
`AlertaInterno` tipo `envio_falhou` (uma vez só — reaproveita
`tentativas_envio_falhas === 3` como o gatilho exato do alerta, pra não
repetir a cada ciclo de 5min depois disso).

**Erro e casos extremos:**
- Pausa da Regra 2 (`aguardando_orientacao_em`) não incrementa o contador —
  é um motivo diferente de `null`, sem relação com falha de envio.
- `AlertaInternoService::criar()` falhar ao criar o `envio_falhou` não deve
  impedir o `FollowupConversas` de simplesmente parar de tentar — mesmo
  padrão try/catch + log dos outros blocos.

## 6. Achado 4 — timeout da pausa de dúvida + fecha alerta órfão

**Modelo de dados:**
```
kanban_coluna_configs
├── duvida_timeout_ativo    (boolean, default false) [NOVO]
└── duvida_timeout_segundos (integer, nullable)       [NOVO]
    Mesmo padrão exato de timeout_reassuncao_ativo/segundos (Bloco 2) —
    toggle + valor, com fallback de 3600s se o toggle estiver ligado e o
    valor nunca foi salvo (mesmo raciocínio do achado 4 da revisão final do
    Bloco 2).
```

**Mecanismo — expiração:** novo comando `conversas:expirar-pausa-orientacao`,
a cada 5min, mesmo padrão estrutural de `ReassumirAgente`
(`app/Console/Commands/ReassumirAgente.php`): candidatos são tickets com
`aguardando_orientacao_em` preenchido há mais tempo que
`duvida_timeout_segundos` da coluna atual. Pra cada candidato (reconferido
antes de agir, mesmo padrão defensivo):
1. Fecha o `AlertaInterno` pendente — mesma query que `orientar()` já usa
   pra achar o alerta certo (`AlertaInterno::where('tenant_id', ...)->where('ticket_id', ...)->where('tipo', 'duvida_ia')->whereNull('resposta')->latest('id')->first()`)
   — `resposta = 'Não respondido a tempo — retomado automaticamente.'`,
   `respondido_em = now()`.
2. `$ticket->update(['aguardando_orientacao_em' => null, 'mensagem_espera_enviada' => false])`
   — reassunção silenciosa (mesma filosofia já confirmada com o Leonardo no
   Bloco 2 pra Regra 1: a IA não manda mensagem nenhuma, só volta a
   responder normalmente na próxima vez que o lead escrever).

**Mecanismo — fecha alerta órfão na troca de coluna:** o hook
`TicketAtendimento::updating()` já reseta `aguardando_orientacao_em`/
`mensagem_espera_enviada` quando a coluna muda antes de alguém responder
(`app/Models/TicketAtendimento.php:73-76`). Esse mesmo bloco passa a também
fechar o `AlertaInterno` pendente, com o mesmo texto/mecanismo do item 1
acima (motivo diferente, mesma consequência: "Mudou de coluna antes de
receber orientação — pausa descartada.").

## 7. Achado 5 — guardrail de salto distingue IA de sistema

**Mudança de semântica, sem mudança de schema** (`origem` já é string livre
em `kanban_coluna_historico`, Bloco 4):

- Default de `TicketAtendimento::$origemMudancaColuna` (quando nada seta a
  propriedade) muda de `'ia'` pra `'sistema'` — cobre auto-mover por
  silêncio (`FollowupConversas`), reabertura por webhook (Uazapi/Covercut),
  botões de ação do WhatsApp (`KanbanBotaoActionService`), e qualquer outro
  caminho automático que não seja uma decisão real da IA no meio de uma
  conversa.
- `SdrResponderService` passa a marcar `$ticket->origemMudancaColuna = 'ia';`
  explicitamente, no ponto exato onde processa o token de movimento de
  coluna (`SdrResponderService.php:121`, antes do `update()`) — esse é o
  único lugar do sistema onde a IA decide mover a coluna em tempo real,
  como parte de uma resposta que ela mesma gerou.
- Em `alertarSeMigracaoAtipica()`: a exclusão de colunas Encerramento/
  TransferenciaHumana do cálculo de `$pulou` passa a valer **só quando
  `origem === 'sistema'`**. Origem `'ia'` (decisão real via token) volta a
  contar pra `$pulou` mesmo envolvendo essas colunas — um pulo grande
  decidido pela própria IA em tempo real é raro e vale o glance de
  auditoria, mesmo que na maioria das vezes seja legítimo (ex. objetivo
  cumprido, conversa terminou naturalmente). Origem `'humano'` continua
  alertando sempre, independente de `$pulou` (comportamento já existente,
  inalterado).

```php
$pulou = $ordemAntes !== null && $ordemDepois !== null
    && abs($ordemDepois - $ordemAntes) > 1
    && ! ($origem === 'sistema' && ($papelForaDaOrdem($papelAntes) || $papelForaDaOrdem($papelDepois)));
```

## 8. Testes

- `encerrar()` grava `origem = 'humano'` na linha de histórico.
- Painel: N dúvidas não respondidas + M outros alertas → todas as N dúvidas
  aparecem, completando com os M mais recentes até 20 no total; dúvida já
  respondida (`resposta` preenchida) não entra nessa prioridade, conta como
  alerta normal.
- 3 falhas seguidas de envio → para de tentar, 1 alerta `envio_falhou`
  (não repete no ciclo seguinte); envio com sucesso no meio zera o contador;
  pausa da Regra 2 não incrementa o contador.
- Pausa expira após `duvida_timeout_segundos` → alerta fechado com a
  resposta automática, `aguardando_orientacao_em` limpo, sem mensagem ao
  lead; coluna sem timeout configurado nunca expira automaticamente.
- Coluna muda antes de alguém responder → alerta pendente fechado com o
  texto de "mudou de coluna", mesmo comportamento pro caso manual e
  automático.
- IA move via token pra uma coluna de papel Encerramento com salto > 1 →
  alerta `migracao_atipica` (novo — não alertava antes deste bloco).
- Sistema move automaticamente (auto-mover/webhook/botão) pra Encerramento
  com salto > 1 → continua sem alerta (comportamento do Bloco 4, inalterado).
- Humano move manualmente → continua sempre alertando, independente de papel
  ou salto (inalterado).

## 9. Fora de escopo — pendências explícitas

- Registro em base de conhecimento (transversal às Regras 1, 2, 3, 5, 13).
- Reset de `origemMudancaColuna` após uso, otimização de queries do
  guardrail, deep-link do alerta pro ticket específico, horário comercial
  do `kanban:monitorar`, unidade "dias" na UI do timeout de reassunção.
