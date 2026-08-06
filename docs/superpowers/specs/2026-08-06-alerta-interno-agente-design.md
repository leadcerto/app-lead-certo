# Infra de Alerta Interno do Agente — Design Técnico

> Bloco 1 de 4, decompostos a partir de `2026-08-06-regras-atendimento-ia-humano-contexto.md`
> (Regra 11 — "mensagens de alerta do agente são privadas por padrão"). Pré-requisito
> técnico dos Blocos 2 (handoff humano ↔ agente), 3 (guardrails de resposta) e 4
> (monitoramento proativo de Kanban) — nenhum deles ainda tem spec própria.

## 1. Contexto e problema

As Regras 1, 2, 3, 12 e 13 do documento de contexto todas dependem do agente conseguir
mandar um aviso privado pro atendente humano — nunca visível ao lead — associado ou não
a um ticket específico. Hoje não existe nenhum mecanismo genérico pra isso.

O que existe hoje e **não é a mesma coisa**: `AgendaImediataService` (o sino de
"Agenda para agora") é uma fila de *ação pendente* — tickets aguardando resposta
humana há mais de 15 min, leads novos sem atribuição. É computado on-demand a cada
chamada, não persiste histórico, e não tem conceito de "lido"/"não lido" por item.
Alerta interno é diferente: é um *aviso do que já aconteceu ou está acontecendo*,
gerado pelo agente em resposta a um evento específico, que persiste e pode ser
revisado depois.

## 2. Escopo

**Dentro do escopo:**
- Tabela e model `AlertaInterno` — genérico o bastante pra qualquer bloco futuro
  (Regras 1, 2, 3, 12, 13) criar alertas sem mudar esquema.
- `AlertaInternoService::criar()` — ponto único de criação, reaproveitado por todos
  os consumidores futuros.
- API de listagem e marcação de lido.
- UI: ícone + dropdown na barra de topo, ao lado do sino existente.

**Fora do escopo (deixado pros blocos que vão consumir esta infra):**
- Qualquer lógica que decida *quando* gerar um alerta (isso é Regra 1/2/3/12/13 —
  Blocos 2/3/4).
- Alvo por usuário específico — por enquanto todo alerta é visível a qualquer perfil
  do tenant que já vê o Kanban (mesmo alcance de hoje). Direcionamento por pessoa é
  melhoria futura, sem uso previsto ainda.
- Qualquer envio por e-mail/push fora do painel.

## 3. Modelo de dados

```
alertas_internos                           [TABELA NOVA]
├── id
├── tenant_id
├── ticket_id (nullable — Regra 3 pode gerar alerta sem ticket específico,
│              ex: "3 leads travados na coluna Orçamento")
├── tipo (string, ex: 'duvida_ia' | 'reassuncao_automatica' |
│                     'monitoramento_coluna' | 'migracao_coluna')
├── titulo (string, curto — usado na linha do dropdown)
├── conteudo (text — corpo completo, visível ao expandir)
├── lido_em (timestamp, nullable)
└── timestamps
```

`tipo` é string livre, não enum de banco — os Blocos 2/3/4 vão introduzir tipos novos
conforme forem implementados, e um enum de banco exigiria migration a cada bloco.
Validação de valores aceitos (se necessária) fica na camada de aplicação, não no schema.

Model `AlertaInterno`: `TenantScope` (mesmo padrão de `KanbanColunaConfig`/`Kanban`),
`belongsTo(Tenant)`, `belongsTo(TicketAtendimento)` nullable.

## 4. Service

`AlertaInternoService::criar(int $tenantId, string $tipo, string $titulo, string $conteudo, ?int $ticketId = null): AlertaInterno`

Ponto único de escrita — todo consumidor futuro (Blocos 2/3/4) chama este método em
vez de instanciar `AlertaInterno::create()` direto, mesmo racional do
`EcoTranscricaoService`: evita duplicar a lógica de criação em cada job/controller
que precisar alertar, e dá um único lugar pra evoluir (ex: se um dia precisar
despachar notificação push além de persistir, muda aqui uma vez só).

## 5. API

- `GET /api/painel/alertas` — lista os alertas do tenant, mais recentes primeiro,
  retorna os 20 mais recentes (sem paginação real — é uma lista fixa pro dropdown,
  não uma tela paginável).
  Retorna também a contagem de não lidos (`nao_lidos_count`) pro badge.
- `POST /api/painel/alertas/{id}/marcar-lido` — seta `lido_em = now()`.
- `POST /api/painel/alertas/marcar-todos-lidos` — marca todos os não lidos do
  tenant de uma vez (ação "limpar tudo" no dropdown).

Rotas dentro do grupo de roles já usado pro sino/Kanban
(`role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda`) — mesmo alcance de
visibilidade de hoje.

## 6. UI

Ícone novo na barra de topo (`layouts/app.blade.php`), ao lado do sino existente —
mesmo padrão visual (badge vermelho com contagem, dropdown ao clicar, fecha ao
clicar fora), mas componente Alpine próprio, não o mesmo componente do sino
(semânticas diferentes, ver seção 1). Cada item do dropdown mostra `titulo`,
timestamp relativo, e um indicador visual de não-lido; clicar marca como lido
individualmente. Botão "Marcar tudo como lido" no topo do dropdown. Polling de 60s,
mesmo intervalo já usado pelo sino.

Se o alerta tiver `ticket_id`, o item é clicável e leva pro ticket no Kanban (mesmo
padrão de link já usado em outros lugares do painel que apontam pra um ticket
específico). Sem `ticket_id`, o item não é clicável, só informativo.

## 7. Tratamento de erros e casos extremos

- `ticket_id` referenciando um ticket já excluído/inacessível → o alerta continua
  existindo e visível (é um registro histórico), só o link de "ir pro ticket" não
  funciona — sem tratamento especial necessário, o `findOrFail` na navegação já
  cobre isso com um 404 natural.
- Chamada de `AlertaInternoService::criar()` a partir de um contexto sem tenant
  autenticado (job/webhook) → segue o mesmo padrão já usado em outros services
  chamados fora de request HTTP: `tenantId` é sempre passado explicitamente pelo
  chamador, nunca inferido de `auth()->user()`.
- Volume alto de alertas (ex: Bloco 4 gerando muitos de monitoramento) → fora de
  escopo deste bloco resolver throttling/agrupamento; o limite de 20 itens da
  listagem já evita problema de performance na tela. Se virar ruído de verdade na
  prática, vira ajuste dos blocos que geram (não desta infra).

## 8. Testes

- `AlertaInterno` model: isolamento por tenant (`TenantScope`), cast de `lido_em`.
- `AlertaInternoService::criar()`: cria com e sem `ticket_id`, tipo livre aceito.
- Controller: listar (ordem, limite de 20, contagem de não lidos), marcar-lido
  individual, marcar-todos-lidos, isolamento cross-tenant (tenant A não vê/marca
  alerta de tenant B — mesmo padrão de teste já usado em
  `KanbanColunaObjetivoControllerTest::test_isolamento_por_tenant`).

## 9. Fora de escopo — pendências explícitas

- Blocos 2, 3 e 4 (Regras 1-3, 5-9, 12-13) — nenhum ainda tem spec própria, todos
  vão consumir `AlertaInternoService::criar()` quando forem desenhados.
- Direcionamento de alerta por usuário específico (hoje é tenant-wide).
- Qualquer canal de entrega fora do painel (e-mail, push, WhatsApp pro humano).
- Agrupamento/throttling de alertas repetidos.
