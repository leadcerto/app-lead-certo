# Monitoramento Proativo de Kanban — Design Técnico

> Bloco 4 de 4, decompostos a partir de `2026-08-06-regras-atendimento-ia-humano-contexto.md`
> (Regras 3, 12, 13-parte-nova). Último bloco — fecha as 13 regras. Depende do
> Bloco 1 (`2026-08-06-alerta-interno-agente-design.md`, `AlertaInternoService::criar()`,
> já em produção) para notificar o humano.

## 1. Contexto e problema

Hoje o sistema não sabe quando um lead trava numa coluna além do esperado, e a
troca de coluna é um evento totalmente silencioso — não há registro de quem
moveu (IA ou humano), nem alerta quando algo foge do padrão (movimento manual,
salto de etapas). Este bloco fecha essas três lacunas: monitoramento periódico
de tickets travados (Regra 3), configuração de tempo máximo por coluna
(Regra 12), e detecção/alerta de migrações atípicas (Regra 13, parte que
ainda não foi coberta pelos Blocos 1-3).

**Decisões de arquitetura fechadas com o Leonardo (nesta sessão):**
- "Mensagem de migração como tipo distinto" (Regra 13) = distinção **interna**
  (tipo de alerta), não uma mensagem nova visível pro lead — a troca de coluna
  continua silenciosa do ponto de vista do lead.
- "Criticidade" (Regra 12) = só o tempo máximo de permanência configurado por
  coluna. Não existe um segundo campo de "nível de urgência" separado.
- Padrão de alerta da Regra 3 = 1 alerta por ticket travado (mesmo padrão já
  usado nos Blocos 1-3), não um alerta agregado por coluna.
- Auditoria de quem moveu (Regra 13) = novo campo `origem` no histórico.
- "Nunca pula colunas" (Regra 13) = só alerta, não bloqueia a movimentação.
- Notificação de migração (Regra 13) = só dispara em migração **manual**
  (feita por humano) e/ou que **pula coluna** — migração normal feita pela IA
  avançando o funil não gera alerta (seria ruído puro em operação normal).

## 2. Escopo

**Dentro do escopo:**
- Regra 3: comando novo, roda a cada 15 minutos, identifica tickets travados
  além do tempo máximo configurado da coluna, gera alerta interno.
- Regra 12: campo `tempo_maximo_permanencia_minutos` por coluna, configurável
  na UI de config da coluna.
- Regra 13 (parte nova): campo `origem` no histórico de coluna (quem moveu);
  alerta de migração atípica (manual e/ou salto de coluna); guardrail de
  salto não-bloqueante.

**Fora do escopo:**
- Qualquer mensagem nova visível pro lead na troca de coluna.
- Bloqueio de movimentação (o guardrail de salto é só alerta).
- Nível de criticidade/urgência separado do tempo máximo.
- Alertar em migração normal feita pela IA.

## 3. Modelo de dados

```
kanban_colunas
└── tempo_maximo_permanencia_minutos (integer, nullable)   [NOVO]
    Null = coluna não monitorada pela Regra 3. Preenchido = limiar em
    minutos que o comando de 15min usa pra decidir se um ticket travou.

kanban_coluna_historico
├── origem      (string, nullable: 'ia' | 'humano')        [NOVO]
│   Quem causou a entrada nessa coluna. Só marcado 'humano' nos dois
│   pontos de movimentação manual reais do sistema
│   (KanbanController::mover() e moverParaOutros(), drag-and-drop do
│   board) — todo o resto (token de coluna da IA, followup automático,
│   webhook, botões de ação) não passa por esses endpoints, então o
│   hook assume 'ia' por padrão. Linhas criadas antes deste bloco ficam
│   com origem nula (sem backfill — só passa a valer daqui pra frente).
└── alertado_em (timestamp, nullable)                      [NOVO]
    Marca que o comando de 15min já gerou o alerta de "travado" pra
    essa permanência específica na coluna. Dedup natural: quando o
    ticket sai e volta pra mesma coluna, uma nova linha de histórico é
    criada com alertado_em nulo, podendo alertar de novo se travar de
    novo.
```

## 4. Mecanismo — Regra 3 (comando periódico, ticket travado)

Novo comando `kanban:monitorar`, mesmo padrão de `ReassumirAgente`
(`app/Console/Commands/ReassumirAgente.php`): query cross-tenant com
`withoutGlobalScopes()`, flag `--dry-run`, try/catch por candidato,
`AlertaInternoService` injetado no `handle()`.

A cada execução:
1. Busca a linha mais recente de `kanban_coluna_historico` por ticket (a
   "permanência atual") onde `alertado_em` é nulo.
2. Junta com `kanban_colunas.tempo_maximo_permanencia_minutos` pela chave da
   coluna. Ignora colunas sem esse campo configurado.
3. Se `now() - entrou_em > tempo_maximo_permanencia_minutos`: recarrega o
   ticket (mesmo padrão defensivo do `ReassumirAgente` — reconfere que ainda
   está na mesma coluna antes de agir), cria `AlertaInterno` tipo
   `ticket_travado` (título com nome do lead + coluna, corpo com o tempo
   parado em horas), marca `alertado_em = now()` na linha do histórico.

Agendamento em `routes/console.php`: `->everyFifteenMinutes()->withoutOverlapping()->appendOutputTo(...)`.

## 5. Mecanismo — Regra 13 (migração atípica)

Diferente da Regra 3 (periódica): isso é um evento, acontece no exato momento
da troca de coluna — não faz sentido esperar até 15min pra notificar. Vive no
`TicketAtendimento::updated()` hook já existente
(`app/Models/TicketAtendimento.php`), o único ponto de convergência de todos
os caminhos que mudam `coluna_kanban` hoje — mesmo raciocínio já usado pro
reset de `objetivos_cumpridos` e `aguardando_orientacao_em` nesse mesmo hook.

Ao criar a linha de `kanban_coluna_historico` (já acontece hoje), o hook
passa a:
1. Resolver `origem`: lê uma propriedade transiente não-persistida do model
   (`$ticket->origemMudancaColuna`, não é `$fillable`/coluna de banco) —
   `'humano'` se setada, senão `'ia'`. Só `KanbanController::mover()` e
   `moverParaOutros()` setam essa propriedade antes de chamar `->update()`.
2. Comparar a `ordem` da coluna anterior com a nova (via
   `KanbanColuna::doTenant($ticket->tenant_id)`) — salto = diferença
   absoluta de `ordem` maior que 1.
3. Se `origem === 'humano'` OU salto detectado: `AlertaInternoService::criar()`
   tipo `migracao_atipica`, com `$ticket->id`. Texto do alerta menciona o(s)
   motivo(s) que dispararam (movido manualmente e/ou pulou N colunas) — um
   alerta só, mesmo que os dois motivos se apliquem ao mesmo evento, pra não
   duplicar notificação da mesma migração.

O guardrail "nunca pula colunas" é só esse alerta — a movimentação em si
**não é bloqueada**, nem pela IA nem pelo humano (decisão fechada: evita
travar um caso legítimo, ex. pular direto pra Encerrado, por engano).

## 6. UI

**Config da coluna:** campo numérico novo "Tempo máximo de permanência
(minutos)", mesma seção onde já ficam os outros campos configuráveis por
coluna (`resources/views/kanban/config.blade.php`). Vazio = não monitorada.

**Painel de alertas:** já existe desde o Bloco 1, sem mudança estrutural —
só passa a exibir os 2 tipos novos (`ticket_travado`, `migracao_atipica`) na
lista, com o mesmo tratamento visual já dado aos tipos existentes.

## 7. Tratamento de erros e casos extremos

- Coluna sem `tempo_maximo_permanencia_minutos` configurado → nunca entra na
  varredura do comando de 15min (mesmo padrão do `timeout_reassuncao_ativo`
  no Bloco 2: campo nulo = feature desligada pra aquela coluna).
- `AlertaInternoService::criar()` falha ao gerar o alerta de `ticket_travado`
  ou `migracao_atipica` → mesmo padrão já usado nos Blocos 2/3: a operação
  principal (marcar `alertado_em`, ou a própria migração de coluna) não
  depende do alerta ter sido criado com sucesso — logar e seguir.
- Ticket travado é movido manualmente por um humano exatamente entre a
  varredura do comando e a criação do alerta → mesmo padrão defensivo do
  `ReassumirAgente` (achado 3 da revisão final do Bloco 2): recarrega o
  ticket e reconfere que a coluna não mudou antes de marcar `alertado_em`.
- Ticket criado antes deste bloco, cuja linha de histórico "atual" já existe
  sem os campos novos → `origem`/`alertado_em` nulos tratados como "ainda não
  alertado"/"origem desconhecida" (não é tratado como `'ia'` nem dispara
  falso-positivo de migração atípica, porque essas linhas não passam pelo
  hook de novo — só entram na varredura de `ticket_travado` normalmente).
- Migração de coluna feita por um script/comando administrativo fora dos dois
  endpoints humanos e fora do fluxo normal da IA (ex: um comando de
  manutenção futuro) → cai no default `'ia'` por não setar a propriedade
  transiente. Não é um caso real hoje (nenhum comando administrativo atual
  move `coluna_kanban`), documentado aqui como comportamento esperado caso
  surja no futuro.

## 8. Testes

- Ticket parado além do tempo máximo configurado → 1 alerta `ticket_travado`,
  `alertado_em` marcado, não repete na próxima execução do comando.
- Ticket sai e volta pra mesma coluna → nova linha de histórico com
  `alertado_em` nulo, pode alertar de novo se travar de novo.
- Coluna sem `tempo_maximo_permanencia_minutos` → nunca alerta, mesmo com
  ticket parado há muito tempo.
- `--dry-run` não altera nada (mesmo padrão do `ReassumirAgente`).
- Humano move ticket via `mover()` → linha de histórico com `origem = 'humano'`
  + alerta `migracao_atipica`.
- Humano move ticket via `moverParaOutros()` → mesmo comportamento acima.
- IA move ticket via token de coluna, movimento adjacente (`ordem` diferença
  1) → `origem = 'ia'`, sem alerta.
- Qualquer origem pulando mais de 1 posição de `ordem` → alerta
  `migracao_atipica`, movimentação não é bloqueada (`coluna_kanban` do
  ticket reflete o destino normalmente).
- Humano pulando colunas (os dois motivos se aplicam) → exatamente 1 alerta,
  não 2, com texto mencionando ambos os motivos.
- Ticket criado (linha de histórico inicial, `coluna_anterior` nulo) → nunca
  dispara `migracao_atipica` (não é uma migração, é a entrada inicial).

## 9. Fora de escopo — pendências explícitas

- Mensagem nova visível pro lead anunciando troca de etapa.
- Nível de criticidade/urgência separado do tempo máximo de permanência.
- Bloqueio de movimentação que pula colunas.
- Backfill de `origem`/`alertado_em` em linhas de histórico já existentes.
