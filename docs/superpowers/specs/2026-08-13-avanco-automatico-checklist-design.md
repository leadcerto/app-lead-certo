# Avanço Automático de Coluna por Checklist — Spec de Design

## Contexto

Levantado pelo Leonardo em 2026-08-13, puxando o fio da pendência "Kanban não anda sozinho" (já parcialmente resolvida nesta mesma sessão: badge do card corrigido pra medir última mensagem em vez de idade do ticket, e o auto-mover por silêncio passou a valer também pra ticket assumido por humano — ver `TAREFAS.md`).

Relato original: "a IA não está acompanhando a conversa e identificando qual o ponto atual do card." Exemplo concreto dado: identificou os endereços → deveria avançar pra próxima coluna; identificou a lista/fotos dos itens → deveria estar em "Aguardando Orçamento"; orçamento enviado → deveria estar em "Aguardando Lead" esperando a decisão do lead. Ele também descreveu o caso mais frequente na prática: quando ele mesmo (humano) assume a conversa e faz tudo manualmente — endereços, lista, orçamento — o card pula direto pra "Aguardando Lead" sem passar pelas colunas intermediárias, porque nada está observando o que ele escreveu.

## O que já existe (não precisa ser criado)

- **Checklist configurável por coluna** (`kanban_coluna_objetivos`: `tenant_id`, `coluna_kanban`, `texto`, `ordem`, `ativo`) — cada coluna pode ter uma lista de itens que precisam ser identificados nela (ex: "endereço de saída", "lista de itens").
- **Progresso por ticket** (`tickets_atendimento.objetivos_cumpridos`, array de ids) — já resetado automaticamente sempre que o ticket muda de coluna (hook existente em `TicketAtendimento`), então a checklist começa zerada em cada coluna nova.
- **Marcação pela IA** — quando o bot está no controle (`SdrResponderService::responder()`), a resposta pode incluir um ou mais tokens `[OBJETIVO_CUMPRIDO:<id>]`; o sistema já valida que o id existe e pertence à coluna atual, marca em `objetivos_cumpridos` e remove o token antes de mandar a resposta ao lead. **Hoje isso só alimenta o indicador "X/Y cumpridos" no card — não dispara nenhum avanço de coluna.**
- **Movimento de coluna pela IA** — separadamente, o bot já pode incluir um token `[NOME_DA_COLUNA]` pra mover o ticket quando julga apropriado, por conta própria (guiado só pelo prompt da coluna, sem depender da checklist).
- **Ordem das colunas** — `KanbanColuna::proximaChave($tenantId, $chaveAtual)` já retorna a chave da próxima coluna na ordem configurada (mesma ordem usada na tela de Configurações).
- **Papel das colunas** — `KanbanColuna::papelDe($tenantId, $chave)` retorna o papel (`Entrada`, `EmAndamento`, `Encerramento`, `TransferenciaHumana`) de qualquer coluna, mesmo renomeada.

## O que este trabalho adiciona

### 1. Avanço automático ao completar a checklist

Quando todos os objetivos **ativos** da coluna atual de um ticket estiverem marcados como cumpridos em `objetivos_cumpridos`, o ticket avança automaticamente para a próxima coluna (`KanbanColuna::proximaChave()`), **exceto** se a próxima coluna tiver papel `Encerramento` ou `TransferenciaHumana` — chegar nessas colunas continua exigindo um sinal explícito (o bot mandando `[ENCERRADO]`/movimento manual/reação de botão), porque encerrar ou transferir é uma decisão mais forte do que "terminei a checklist local". Uma coluna sem nenhum objetivo ativo configurado nunca avança sozinha por este mecanismo — não há o que julgar como completo (mesmo princípio já usado no guardrail de migração atípica do Bloco 4).

Se a próxima coluna, por sua vez, também tiver checklist completa por coincidência de dados legados, isso não causa um "efeito cascata" nesta mesma execução — o avanço automático roda no máximo uma vez por marcação de objetivo, movendo só um passo. A checklist da nova coluna começa zerada (hook de reset já existente) e será avaliada na próxima interação normalmente. Se o ticket já estiver na última coluna configurada (`proximaChave()` retorna `null`), simplesmente não há pra onde avançar — nada acontece.

### 2. Serviço compartilhado: `AvancoAutomaticoKanbanService`

Novo serviço (`app/Services/AvancoAutomaticoKanbanService.php`) com dois métodos públicos:

- `marcarObjetivos(TicketAtendimento $ticket, array $idsObjetivos): void` — valida os ids contra a coluna atual (mesma validação que já existe hoje dentro de `SdrResponderService`), atualiza `objetivos_cumpridos`, e — se isso completar a checklist — aplica o avanço de coluna descrito acima.
- `avancarSeCompleto(TicketAtendimento $ticket): bool` — checagem isolada (usada pelo job da Parte 3), retorna `true` se avançou.

`SdrResponderService::responder()` é alterado pra delegar a este serviço no lugar da lógica hoje inline na seção "4.5" (mesmo comportamento de marcação, ganha o avanço de coluna de graça). **Importante:** se a mesma resposta da IA já incluir um token explícito de movimento de coluna (`[NOME_DA_COLUNA]`, seção "4" do mesmo método, que roda antes), o avanço automático por checklist não é aplicado nessa chamada — o ticket já mudou de coluna por decisão explícita da IA, e aplicar os dois moveria duas vezes (ou moveria a partir da coluna errada).

### 3. Caminho do humano: avaliação em segundo plano

Quando uma mensagem de `remetente = 'humano'` é criada para um ticket cuja coluna atual tem checklist ativa e ainda incompleta, um job novo (`AvaliarObjetivosPorMensagemHumanaJob`) é despachado (fila `default`, sem delay) pra analisar a conversa e marcar o que já foi resolvido.

- **Ponto de disparo único:** hook `static::created()` no próprio model `Mensagem` (não em cada controller de webhook/painel) — cobre WhatsApp Oficial, não-oficial e o chat do painel de uma vez, sem risco do furo de paridade já visto várias vezes nesta sessão (uma funcionalidade que existe num canal e falta no outro).
- **Condições pra disparar:** `remetente === 'humano'`, a coluna atual do ticket tem pelo menos um objetivo ativo, nem todos ainda estão em `objetivos_cumpridos`, e `KanbanColunaConfig.ia_ativo` está ligado pra essa coluna (mesmo interruptor "Agente ativo nesta coluna" que já existe — se o franqueado desligou a IA na coluna, esta checagem em segundo plano também fica desligada).
- **O que o job faz:** monta a lista de objetivos ainda pendentes + as últimas mensagens do ticket (mesmo histórico que já é montado hoje pra IA, reaproveitando o helper existente), e faz **uma** chamada de IA (`OpenRouterService::chat()`, tier `'simples'`, mesmo padrão já usado nas outras classificações leves do sistema como a decisão de reabertura de ticket encerrado) pedindo pra listar quais ids de objetivo já estão satisfeitos pela conversa até agora. Aplica o resultado via `AvancoAutomaticoKanbanService::marcarObjetivos()`.
- **Falha da IA (erro de rede, resposta vazia/inválida):** loga um aviso e não marca nada — sem quebrar o envio da mensagem original (o job roda depois, em fila, então mesmo uma falha não afeta a entrega da mensagem do humano). A próxima mensagem do humano tenta de novo naturalmente.
- **Duas mensagens do humano quase simultâneas:** duas mensagens seguidas do humano despacham dois jobs, que poderiam ler o mesmo estado "checklist ainda incompleta" e cada um tentar avançar a coluna por conta própria — mesma família do bug de corrida achado nesta sessão (dois tickets criados pro mesmo lead por dois webhooks quase simultâneos). `AvancoAutomaticoKanbanService::marcarObjetivos()`/`avancarSeCompleto()` usa o mesmo padrão de trava (`Cache::lock()`, chave por `ticket_id`) já aplicado nos outros pontos de corrida corrigidos hoje — o segundo job espera o primeiro terminar antes de ler/gravar `objetivos_cumpridos`, evitando avançar duas vezes.

## Fora de escopo (YAGNI, não faz parte desta entrega)

- Confirmação/aprovação humana antes de aplicar o avanço automático — o Leonardo já tolera automação sem confirmação nos outros mecanismos deste mesmo Kanban (auto-mover por silêncio, migração atípica), não há indicação de que este precise ser diferente.
- Notificação especial quando o avanço automático acontece — o histórico de coluna já registra a mudança (mecanismo existente), e um card avançando é o comportamento esperado, não uma anomalia que precise de alerta.
- Editar/reverter a marcação de um objetivo manualmente pela UI — fora do pedido original, pode ser levantado depois se fizer falta na prática.
- Qualquer mudança na forma como a IA decide usar o token `[NOME_DA_COLUNA]` hoje — esse mecanismo continua existindo em paralelo, sem alteração de comportamento.

## Testes

Seguindo o padrão já estabelecido no projeto (PHPUnit clássico, `RefreshDatabase`, mock de `OpenRouterService`/`Http::fake` pra respostas de IA):

- `AvancoAutomaticoKanbanService`: marca objetivo → não avança se ainda faltam outros; marca o último → avança pra próxima coluna; próxima coluna com papel Encerramento/TransferenciaHumana → não avança; coluna sem objetivo ativo → não avança nunca; ids inválidos/de outra coluna são ignorados (mesma proteção que já existe hoje); já na última coluna → não quebra, não faz nada.
- `SdrResponderService`: resposta com `[OBJETIVO_CUMPRIDO:id]` completando a checklist avança a coluna; resposta que também inclui `[NOME_DA_COLUNA]` explícito não aplica o avanço automático por cima.
- `AvaliarObjetivosPorMensagemHumanaJob`: mensagem de humano com objetivos pendentes dispara o job e marca o que a IA identificar; `ia_ativo = false` não dispara; coluna sem checklist não dispara; falha da IA não quebra nada e não marca nada.
- Paridade: teste de mensagem humana chegando pelo Uazapi, pelo Covercut (echo) e pelo chat do painel — os três disparam o job (confirma que o hook único no model realmente cobre os três canais, não só documenta a intenção).
