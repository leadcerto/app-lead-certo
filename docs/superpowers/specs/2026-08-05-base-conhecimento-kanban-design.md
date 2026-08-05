# Base de Conhecimento por Kanban e por Coluna — Design Técnico

> Primeira de três frentes relacionadas, decompostas a pedido do Leonardo em
> 2026-08-05: (1) esta spec — a estrutura da base de conhecimento em si; (2)
> modo do agente ficar em silêncio observando e perguntando; (3) loop de
> aprendizado contínuo que alimenta a base de conhecimento a partir das
> respostas do humano. (2) e (3) dependem de (1) existir primeiro e ainda não
> têm design.

---

## 1. Contexto e problema

O agente de IA hoje só tem duas fontes de conhecimento por conversa:
`tenant.ia_contexto` (informações gerais da empresa, ex: "Informações do
negócio") e `kanban_coluna_configs.ia_contexto` (instruções daquela etapa
específica) — ambos texto livre, montados em
`SdrResponderService::montarHistorico()`.

Não existe hoje nenhuma noção de **objetivo de saída de uma coluna** que o
sistema consiga rastrear. O que existe é `SdrResponderService::derivarChecklist()`
— um checklist gerado por regex, **100% hardcoded pro negócio de frete/mudança**
(endereço de origem, endereço de destino, lista de itens, data, escadas,
serviços extras). Funciona hoje só porque o Frete Rio é a única empresa real
usando o sistema — mesmo problema de fundo já corrigido nesta sessão pro foco
de análise de imagem (`MediaProcessorService::FOCO_PADRAO`).

Também não existe nenhum conhecimento no nível do **Kanban como um todo** —
hoje só existe o nível tenant (empresa) e o nível coluna (etapa). Isso importa
principalmente olhando pra frente: o projeto já tem um Kanban por tenant hoje,
mas `T-MULTI-KANBAN-ARQUITETURA` (backlog) prevê múltiplos Kanbans por tenant
no futuro (vendas, pós-venda, prospecção...), cada um com sua própria
estratégia geral, distinta das instruções de cada coluna individual.

## 2. Escopo

**Dentro do escopo:**
- Campo de conhecimento geral por Kanban (`kanbans.conhecimento_geral`).
- Checklist de objetivos configurável por coluna, substituindo o
  `derivarChecklist()` hardcoded.
- Progresso do checklist rastreado por ticket (por lead), não só por coluna.
- Mecanismo do agente reportar progresso do checklist na própria resposta —
  reaproveita o padrão já existente dos tokens de movimento de coluna.
- Migração dos 6 itens hardcoded do Frete Rio pro novo formato configurável.
- Tela de configuração (seção nova no nível Kanban + editor de objetivos por
  coluna) e indicador de progresso no card do Kanban.

**Fora do escopo (frentes futuras, dependem desta):**
- Modo do agente ficar em silêncio observando a conversa e fazer perguntas
  pro humano responder.
- Loop de aprendizado contínuo — como respostas do humano viram conhecimento
  reaproveitado em conversas futuras (de outros leads).
- Qualquer coisa envolvendo múltiplos Kanbans por tenant
  (`T-MULTI-KANBAN-ARQUITETURA`) — o campo `kanbans.conhecimento_geral` já
  nasce pronto pra esse futuro, mas nada aqui pressupõe que ele já existe.

## 3. Modelo de dados

```
kanbans
└── conhecimento_geral (text, nullable)   [NOVO]
    "O que a IA precisa saber sobre este Kanban como um todo" — visão geral,
    complementa (não substitui) tenant.ia_contexto, que continua existindo
    pra informações da empresa em si (endereço, telefone, regras gerais).

kanban_coluna_configs
└── ia_contexto (text, nullable)          [JÁ EXISTE — sem mudança]
    Vira a parte "informações" da base de conhecimento da coluna. Mesmo
    campo, mesmo uso, só reenquadrado conceitualmente.

kanban_coluna_objetivos                    [TABELA NOVA]
├── id
├── tenant_id
├── coluna_kanban (string — mesma chave usada em kanban_coluna_configs)
├── texto (string, ex: "Endereço de origem confirmado")
├── ordem (integer)
├── ativo (boolean, default true)
└── timestamps

tickets_atendimento
└── objetivos_cumpridos (json, nullable)   [NOVO]
    Array de IDs de kanban_coluna_objetivos já marcados cumpridos PARA ESTE
    ticket. Zerado/ignorado ao mudar de coluna (objetivos são por coluna —
    ver seção 5, "Reset ao mudar de coluna").
```

`kanban_coluna_objetivos` é tabela própria (não JSON num campo) pelo mesmo
motivo que `sequencia_mensagens` é tabela própria: precisa suportar
adicionar/reordenar/excluir item a item pela UI, igual já funciona hoje pras
mensagens de sequência e pras variações.

## 4. Como o agente usa e atualiza o checklist

**No prompt** (`SdrResponderService::montarHistorico()`), o system prompt
passa a incluir, nesta ordem, tudo texto livre concatenado como já acontece
hoje:

1. `tenant.ia_contexto` (já existe)
2. `kanban.conhecimento_geral` (novo — "conhecimento geral do Kanban")
3. `kanban_coluna_configs.ia_contexto` da coluna atual (já existe —
   "instruções desta etapa")
4. Bloco de objetivos da coluna atual, com status por ticket — substitui
   `[STATUS_CHECKLIST]` gerado por `derivarChecklist()`:

```
=== OBJETIVOS DESTA ETAPA (marque quando cumprir) ===
✅ Endereço de origem confirmado
❌ Lista de itens: pendente
❌ Data e horário: pendente

Pra marcar um objetivo como cumprido, inclua no final da sua resposta um
token [OBJETIVO_CUMPRIDO:<id>] — pode incluir mais de um na mesma resposta,
um por linha. NUNCA mencione ou explique esses tokens ao lead.
===
```

**Atualização de progresso:** mesmo mecanismo já usado pros tokens de
movimento de coluna (`[EM_ATENDIMENTO]`, `[ENCERRADO]`...) —
`SdrResponderService::responder()` já varre a resposta procurando tokens
conhecidos antes de mandar a mensagem pro lead; ganha uma segunda varredura
pra `[OBJETIVO_CUMPRIDO:<id>]`, que:
- Adiciona o id ao array `objetivos_cumpridos` do ticket (sem duplicar).
- É removido do texto antes do envio — igual já acontece com os tokens de
  coluna hoje (`str_replace($tokens, '', $resposta)`).

**Reset ao mudar de coluna:** ao entrar numa coluna nova (token de movimento
detectado), `objetivos_cumpridos` é limpo — os objetivos são específicos de
cada etapa, não fazem sentido carregados de uma coluna pra outra.

## 5. Migração do Frete Rio

Seed (migration de dados, não de schema) criando os 6 itens hardcoded de hoje
como `kanban_coluna_objetivos` na coluna correspondente do tenant Frete Rio
(mapeamento: os campos de `derivarChecklist()` hoje são checados sem
distinção de coluna — a migration os associa à coluna `em_atendimento`, que é
onde a qualificação do lead acontece hoje). `SdrResponderService::derivarChecklist()`
é **removido** depois da migration — não fica como fallback. Um tenant novo
sem nenhum objetivo configurado simplesmente não tem checklist nenhum (bloco
"OBJETIVOS DESTA ETAPA" fica de fora do prompt), em vez de herdar checklist
de frete por engano — mesmo princípio já aplicado ao `FOCO_PADRAO` genérico.

## 6. UI

**Configurações do Kanban** (`kanban/config.blade.php`):
- Nova seção no topo da página (antes da lista de colunas): "Base de
  Conhecimento do Kanban" — textarea única, salva em `kanbans.conhecimento_geral`.
- Dentro de cada coluna, abaixo da seção "Agente de IA" já existente: novo
  bloco "Objetivos para avançar" — lista de itens com adicionar/reordenar/
  excluir, mesmo padrão visual já usado pra Variações de mensagem (reformulada
  em abas nesta mesma sessão) e mensagens de sequência.

**Card do Kanban:** indicador de progresso (ex.: "3/6 objetivos cumpridos")
na conversa aberta, calculado a partir de `objetivos_cumpridos` do ticket vs.
o total de `kanban_coluna_objetivos` ativos da coluna atual.

## 7. Tratamento de erros e casos extremos

- Coluna sem nenhum `kanban_coluna_objetivos` cadastrado → bloco de
  objetivos simplesmente não aparece no prompt (sem checklist vazio
  confuso).
- Token `[OBJETIVO_CUMPRIDO:<id>]` referenciando um id que não existe mais
  (objetivo excluído entre uma resposta e outra) → ignorado silenciosamente,
  logado em debug.
- Ticket muda de coluna e volta pra mesma coluna depois (ex.: reaberto) →
  `objetivos_cumpridos` já foi zerado na saída; recomeça do zero — aceitável,
  não é diferente de como qualquer outro dado de etapa hoje já se comporta.

## 8. Testes

- `KanbanColunaObjetivo` model + CRUD via API (criar/reordenar/excluir),
  mesmo padrão de teste já usado pra `SequenciaMensagem`.
- `SdrResponderService::montarHistorico()`: bloco de objetivos aparece
  corretamente formatado com status atual; ausente quando a coluna não tem
  objetivo cadastrado.
- `SdrResponderService::responder()`: token `[OBJETIVO_CUMPRIDO:<id>]` é
  detectado, persiste em `objetivos_cumpridos`, removido do texto final
  mandado ao lead; múltiplos tokens na mesma resposta funcionam.
- Reset de `objetivos_cumpridos` ao mudar de coluna via token de movimento.
- Migração do Frete Rio: os 6 objetivos aparecem corretamente na coluna
  `em_atendimento` depois de rodar o seed.
- Regressão: nenhum teste existente que dependia do texto exato do
  `[STATUS_CHECKLIST]` hardcoded (nenhum encontrado na auditoria desta sessão).

## 9. Fora de escopo — pendências explícitas

- Modo "agente em silêncio observando/perguntando" — spec própria, depende
  desta.
- Loop de aprendizado contínuo entre conversas diferentes — spec própria,
  depende desta.
- Suporte completo a múltiplos Kanbans por tenant (`T-MULTI-KANBAN-ARQUITETURA`)
  — `kanbans.conhecimento_geral` já nasce pronto pra esse cenário, mas nada
  aqui implementa a seleção/gestão de múltiplos Kanbans em si.
