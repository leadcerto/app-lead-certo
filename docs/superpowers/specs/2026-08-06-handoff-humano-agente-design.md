# Handoff Humano ↔ Agente — Design Técnico

> Bloco 2 de 4, decompostos a partir de `2026-08-06-regras-atendimento-ia-humano-contexto.md`
> (Regras 1, 4 e 8). Depende do Bloco 1 (`2026-08-06-alerta-interno-agente-design.md`,
> já em produção) para notificar o humano quando o agente reassume.

## 1. Contexto e problema

Regra 4 (trava total de fala quando `agente_responsavel = 'humano'`) **já está em
produção hoje** — `UazapiWebhookController.php:394` só dispara resposta do bot se
`agente_responsavel === 'bot'`. Este bloco não constrói essa trava, só adiciona a
exceção da Regra 1: quando o humano assume e depois some (sem responder nem o lead
insistir), o agente precisa retomar sozinho depois de um tempo configurado, em vez
de deixar o lead esperando pra sempre.

O sistema já tem um mecanismo quase idêntico pro problema inverso — bot ativo, lead
some — em `FollowupConversas` (comando `conversas:followup`, a cada 5 min): calcula
"silêncio = tempo desde a última mensagem da conversa, de qualquer remetente" e age
por coluna configurável. Este bloco espelha esse padrão pra direção oposta
(`agente_responsavel = 'humano'` → volta pra `'bot'`).

## 2. Escopo

**Dentro do escopo:**
- Timeout configurável por coluna do Kanban (`kanban_coluna_configs`), mesmo padrão
  de `auto_mover_ativo`/`auto_mover_segundos`.
- Comando agendado novo que detecta o timeout e reassume o controle.
- Notificação ao humano via `AlertaInternoService::criar()` (Bloco 1).

**Fora do escopo:**
- A trava de fala em si (Regra 4) — já implementada.
- Qualquer mensagem proativa ao lead na reassunção — decisão confirmada: silenciosa,
  sem mensagem (evita soar artificial tipo "oi, voltei").
- Escrita em campos de conhecimento textual (`ia_contexto`/`conhecimento_geral`) — o
  próprio `AlertaInterno` criado já conta como registro do evento. O loop de
  aprendizado de verdade (uma resposta virar conhecimento reaproveitável por outros
  leads) é a Frente 3 da base de conhecimento, ainda sem design.
- Tratamento especial pra colunas com `etapa_ia = 'handoff'` — um gate separado que
  já existe hoje (`Internal\TicketController.php:76`) impede o bot de responder
  nessas colunas independente de `agente_responsavel`; o controle de "quero
  reassunção automática nesta coluna" já é o próprio toggle por coluna.

## 3. Modelo de dados

```
kanban_coluna_configs
├── timeout_reassuncao_ativo       (boolean, default false)   [NOVO]
└── timeout_reassuncao_segundos    (unsigned int, nullable)    [NOVO]
```

Mesmo padrão de `auto_mover_ativo`/`auto_mover_segundos` — dois campos em vez de um
"valor + ativo" combinado, seguindo a convenção já estabelecida no resto do arquivo.

## 4. Mecanismo

Comando novo `conversas:reassumir-agente`, agendado a cada 5 minutos
(`Schedule::command(...)->everyFiveMinutes()->withoutOverlapping()`, mesma cadência
de `conversas:followup`), suporta `--dry-run`.

**Candidatos:** tickets com `agente_responsavel = 'humano'` e `status = 'aberto'`,
join com a última mensagem da conversa (de qualquer remetente) — mesma subquery já
usada em `FollowupConversas` (`MAX(id)` agrupado por `ticket_id`).

**Para cada candidato:**
1. Busca `KanbanColunaConfig` do tenant+coluna. Se `timeout_reassuncao_ativo` for
   falso ou o campo não existir, pula.
2. Calcula silêncio: `now()->diffInSeconds(última_mensagem, absolute: true)`.
3. Se silêncio ≥ `timeout_reassuncao_segundos`:
   - `$ticket->update(['agente_responsavel' => 'bot'])`
   - `AlertaInternoService::criar($ticket->tenant_id, 'reassuncao_automatica', <titulo>, <conteudo>, $ticket->id)`
     — título curto (ex: "Agente reassumiu após Xh de silêncio"), conteúdo com nome
     do contato e desde quando o humano estava em silêncio.
   - Nenhuma mensagem é enviada ao lead.

**Auto-limitante:** assim que `agente_responsavel` vira `'bot'`, o ticket sai do
filtro da próxima execução — sem risco de alerta duplicado ou reassunção repetida
pro mesmo silêncio.

## 5. UI

Em `kanban/config.blade.php`, na seção "Agente de IA" de cada coluna (mesmo bloco
visual dos Estágios de Silêncio e do Auto-mover): checkbox "Reassumir
automaticamente após silêncio do atendente" + campo de tempo (mesmo padrão de input
já usado pros outros timeouts da mesma seção).

## 6. Tratamento de erros e casos extremos

- Coluna sem `KanbanColunaConfig` ou com `timeout_reassuncao_ativo = false` → sem
  reassunção (padrão desligado, opt-in explícito por coluna).
- Ticket sem nenhuma mensagem ainda → não aparece como candidato (o `JOIN` com a
  última mensagem exige pelo menos uma — mesmo comportamento já existente em
  `FollowupConversas`).
- Coluna com `etapa_ia = 'handoff'` → reassume o `agente_responsavel` normalmente
  (não há motivo técnico pra bloquear), mas o gate separado já existente impede o
  bot de responder ali de qualquer forma — sem efeito prático indesejado.
- Falha ao criar o `AlertaInterno` (ex: erro de banco) → não deve impedir a
  reassunção em si; logar o erro e seguir (mesmo padrão de `try/catch` +
  `Log::warning` já usado em `FollowupConversas` pra cada ação individual, uma
  falha não derruba o comando inteiro nem trava os outros tickets do lote).

## 7. Testes

- Silêncio insuficiente → não reassume, sem alerta criado.
- Silêncio suficiente + toggle ativo → reassume (`agente_responsavel = 'bot'`),
  `AlertaInterno` criado com `ticket_id` correto.
- Toggle desligado, mesmo com silêncio longo → não reassume.
- Coluna sem config nenhuma → não reassume (comportamento padrão seguro).
- Isolamento: tickets de tenants diferentes, cada um só reassume conforme sua
  própria config de coluna.
- `--dry-run` não altera nenhum ticket nem cria alerta.
- Ticket que já reassumiu não aparece de novo na próxima execução do comando
  (filtro por `agente_responsavel = 'humano'` já exclui naturalmente).

## 8. Fora de escopo — pendências explícitas

- Bloco 3 (guardrails de resposta — Regras 2, 5, 6, 7, 9) e Bloco 4 (monitoramento
  proativo — Regras 3, 12, 13-parte-nova) — nenhum ainda tem spec própria.
- Loop de aprendizado contínuo (Frente 3 da base de conhecimento) — o evento de
  reassunção não vira conhecimento reaproveitável ainda, só um alerta pontual.
