# Leitura de E-mail da Adriana — Spec de Design

## Contexto

A Adriana (Gerente de Suporte, tenant Lead Certo id=2 — ver
[[project-persona-adriana-gestor-plataforma]] e
`docs/superpowers/specs/2026-08-20-adriana-gestor-suporte-design.md`) hoje só
consegue **enviar** e-mail (`adrianaaviag@gmail.com`, via Gmail API dentro de
`GoogleService::enviarEmail()`). Ela não tem como ler a caixa de entrada — todo
e-mail que um cliente manda pra ela hoje é invisível pro sistema. Este trabalho
adiciona a leitura, unificada no mesmo Kanban que ela já usa pro WhatsApp
(decisão do Leonardo, 2026-08-20/21): **e-mail vira ticket na mesma fila**
("Novo" → "Aguardando Adriana" → "Resolvido"), sem tela separada.

**Achado bloqueante, separado deste trabalho:** o `GoogleToken` do tenant 2
(`adrianaaviag@gmail.com`) está com o refresh_token revogado
(`invalid_grant: Token has been expired or revoked`, log de produção
2026-08-21 00:02 UTC), criado só ~22h antes de falhar — não é o ciclo normal de
expiração, cheira a revogação manual. **Ela precisa reconectar em
`/google/autorizar` antes de qualquer teste de ponta a ponta funcionar** — isso
não é resolvido por este trabalho, é ação humana fora do código.

## Decisões já tomadas (não reabrir sem motivo novo)

1. **E-mail e WhatsApp do mesmo contato nunca se fundem num ticket só** — cada
   canal sempre abre/mantém um ticket separado, mesmo que seja a mesma pessoa.
2. **`Contato.telefone` vira opcional** — hoje é `NOT NULL UNIQUE` desde a
   primeira migration do projeto (`0003_create_consumidores_table`). Um
   remetente de e-mail que nunca mandou WhatsApp não tem telefone. Coluna única
   já aceita múltiplos `NULL` nativamente (MySQL/Postgres) — não precisa mexer
   no índice, só relaxar a constraint de nulidade.
3. **Canal novo é um model próprio (`CanalEmail`)**, não um apêndice do
   `GoogleToken` — mesmo padrão do `WhatsappCanal` (liga em qual `Kanban` o
   ticket cai, tem `status` próprio pra ativar/desativar independente de outras
   integrações Google do mesmo tenant como Contacts/GMB/Calendar).
4. **Polling a cada 5 minutos** — Gmail não empurra evento sem infraestrutura
   de Pub/Sub (exige verificação do app no Google Cloud, fora de escopo).
   Mesmo padrão de comando agendado já usado no projeto.

## O que já existe (não precisa ser criado)

- **`GoogleToken`** (`app/Models/GoogleToken.php`) — token OAuth por tenant, já
  com escopo `https://mail.google.com/` completo (leitura+escrita), renovação
  automática via `GoogleService::tokenValido()`, sinalização de falha de
  renovação via `falha_renovacao_em`.
- **`GoogleService::enviarEmail()`** — já manda e-mail via Gmail API
  (`users.messages.send`), mesmo endpoint que a resposta desta feature vai
  reutilizar/estender.
- **Padrão de canal por tenant com Kanban associado** — `WhatsappCanal` +
  `kanban_whatsapp_canais` (pivot). `CanalEmail` segue o mesmo espírito, mas
  com relação simples `kanban_id` (não pivot — ver "Fora de escopo").
- **Reabertura de ticket encerrado** — mecanismo já usado no WhatsApp
  (`ReaberturaService`/`reabrirSeNecessario`), reaproveitado aqui pro caso de
  e-mail novo chegar numa thread cujo ticket já foi encerrado.
- **`KanbanColuna::chaveDeEntrada($tenantId)`** — já resolve a coluna de
  entrada de qualquer tenant, independente de nome/chave.
- **Trava de corrida `Cache::lock()`** — mesmo padrão já usado nos outros
  pontos de concorrência corrigidos nesta sessão (dois webhooks quase
  simultâneos criando ticket duplicado).
- **Selo de canal na tela do Kanban** — mesma ideia já implementada pro selo de
  idioma (`idioma_lead`) nesta sessão; este trabalho só adiciona mais um selo,
  não cria o mecanismo de selo.

## O que este trabalho adiciona

### 1. Migrations

- `contatos.telefone` → `nullable()` (mantém `unique()`).
- Nova tabela `canais_email`: `tenant_id`, `google_token_id` (FK), `kanban_id`
  (FK, nullable — se nulo, usa o kanban padrão do tenant), `status`
  (`ativo`/`inativo`, default `inativo` — precisa ser ligado explicitamente),
  `gmail_history_id` (string, nullable — cursor de sincronismo Gmail),
  `ultimo_poll_em` (timestamp, nullable), timestamps.
- `tickets_atendimento` ganha: `canal_tipo` (enum `whatsapp`/`email`, default
  `whatsapp` — todos os tickets existentes continuam válidos sem migração de
  dado), `canal_email_id` (FK nullable), `email_thread_id` (string nullable,
  indexado — é o `threadId` do Gmail, âncora pra saber se uma mensagem nova
  pertence a um ticket já existente). `whatsapp_canal_id` permanece como está
  (já é nullable hoje — confirmar no build).
- `mensagens` ganha: `email_message_id_externo` (string nullable — o
  `Message-ID` RFC do e-mail, necessário pro header `In-Reply-To` da próxima
  resposta), `email_assunto` (string nullable).

### 2. `GoogleService` — 3 métodos novos (Gmail API)

- `listarHistoricoEmail(GoogleToken $token, ?string $historyId): array` —
  `users.history.list`, filtra `messagesAdded` com label `INBOX`. Se
  `$historyId` for nulo (primeiro sync de um canal novo), busca só as
  mensagens recentes do INBOX (não o histórico morto) e retorna o
  `historyId` atual como ponto de partida — não importa e-mail antigo.
- `obterEmail(GoogleToken $token, string $messageId): ?array` —
  `users.messages.get` (`format=full`), extrai `From`, `Subject`,
  `Message-ID`, `threadId`, e o corpo (prioriza a parte `text/plain`; sem
  isso, tenta `text/html` convertido pra texto simples; sem nenhuma das duas,
  retorna corpo vazio — quem chama decide o marcador de fallback).
- `enviarRespostaEmail(GoogleToken $token, string $threadId, string $para,
  string $assunto, string $corpo, ?string $inReplyTo, ?string $references):
  bool` — monta o RFC822 com os headers `In-Reply-To`/`References` corretos e
  `threadId` no payload, pra manter o tópico na caixa do cliente (não abrir um
  e-mail novo a cada resposta).

### 3. `EmailAtendimentoService` (novo)

- `sincronizar(CanalEmail $canal): void` — `Cache::lock()` por
  `canal_email_id` (evita corrida entre cron atrasado e execução manual);
  pula silenciosamente + loga se o token estiver inválido/sem renovação
  possível; busca o delta via `listarHistoricoEmail()`; ignora mensagens com
  label `SENT` (a própria Adriana respondendo não deve virar "mensagem do
  lead"); pra cada mensagem nova do INBOX: resolve `Contato` por e-mail (cria
  com `telefone = null` se não achar), resolve `TicketAtendimento` pelo
  `email_thread_id` dentro do tenant (cria na coluna de entrada se não
  existir; reabre — mesmo padrão do `ReaberturaService` — se existir mas
  estiver encerrado), cria `Mensagem` (`remetente = 'lead'`, corpo extraído ou,
  se `obterEmail()` não achou nenhum corpo de texto,
  `"[E-mail sem conteúdo legível]"` — mesmo espírito do placeholder já usado
  hoje pra PDF no WhatsApp); atualiza `gmail_history_id`/`ultimo_poll_em` do
  canal ao final.
- `responder(TicketAtendimento $ticket, string $texto): bool` — busca o
  `GoogleToken` do canal do ticket, pega o `email_message_id_externo` da
  última `Mensagem` do lead nesse ticket (pro header), chama
  `enviarRespostaEmail()`, persiste a `Mensagem` de saída
  (`remetente = 'humano'`) só se o envio confirmar sucesso — mesmo contrato
  que `KanbanController::enviarMensagem()` já espera hoje pro WhatsApp (não
  grava mensagem de envio que falhou).

### 4. Command `email:sincronizar` (agendado a cada 5 min)

Itera `CanalEmail::where('status', 'ativo')->get()`, chama `sincronizar()`
pra cada um dentro de um `try/catch` por canal — falha num tenant nunca
impede o sync dos outros. Log de erro por canal (mesmo padrão de
`AtualizarModelosOpenRouter`/outros commands agendados do projeto).

### 5. `KanbanController::enviarMensagem` — branch por canal

```php
if ($model->canal_tipo === 'email') {
    $enviado = app(EmailAtendimentoService::class)->responder($model, $textoParaEnviar);
} else {
    // caminho WhatsApp existente, sem alteração
}
```

A tradução (item 11 do roteiro de 2026-08-20) e o resto do fluxo
(`assumirAutomaticamente()`, persistência da `Mensagem`) continuam
exatamente como estão — só a chamada de envio de fato muda de canal.

### 6. Ativação (painel)

Toggle simples em Configurações → Integrações, ao lado do "Conectar Google"
que já existe: cria/ativa o `CanalEmail` do tenant (exige `GoogleToken` já
conectado com o escopo de Gmail — se não tiver, mostra a mensagem pra
conectar primeiro). Sem tela nova de configuração além disso — v1 não tem
opção de escolher Kanban de destino diferente do padrão do tenant (ver Fora
de escopo).

## Fora de escopo v1 (YAGNI)

- **Anexos** (imagem/PDF/documento anexado ao e-mail) — vira texto puro só;
  anexo é ignorado nesta fatia (mesmo espírito do placeholder já usado hoje
  pra PDF no WhatsApp, sem inventar extração nova aqui).
- **Múltiplos `CanalEmail` por tenant** — só um e-mail conectado por vez,
  1:1 com o `GoogleToken`.
- **Resposta em HTML rico** — texto puro.
- **Fusão de ticket entre canais** — decisão já fechada, sempre separado.
- **Notificação em tempo real (Pub/Sub)** — fica no polling de 5 min.
- **Pivot `CanalEmail`↔`Kanban` (múltiplos boards)** — v1 usa `kanban_id`
  direto (nullable = kanban padrão do tenant), sem tabela pivot como o
  `WhatsappCanal` tem. Only reavaliar se algum tenant precisar de e-mail
  alimentando mais de um board — não é o caso da Adriana hoje.

## Testes

Seguindo o padrão do projeto (PHPUnit, `RefreshDatabase`, `Http::fake()` pros
endpoints do Gmail):

- `GoogleService`: `listarHistoricoEmail` (com e sem cursor prévio),
  `obterEmail` (parse de headers, corpo `text/plain`, fallback `text/html`,
  corpo vazio), `enviarRespostaEmail` (headers `In-Reply-To`/`References`
  corretos, `threadId` no payload).
- `EmailAtendimentoService::sincronizar`: mensagem nova cria Contato (sem
  telefone) + Ticket; mensagem em thread existente vira `Mensagem` no ticket
  certo; ticket encerrado reabre; mensagem com label `SENT` é ignorada; token
  inválido não quebra o sync dos outros canais, só loga; dois pollings
  simultâneos do mesmo canal não duplicam ticket (trava).
- `EmailAtendimentoService::responder`: envia com `threadId`/headers
  corretos; falha de envio não grava `Mensagem`.
- `KanbanController::enviarMensagem`: ticket com `canal_tipo = 'email'` chama
  `EmailAtendimentoService` em vez do caminho WhatsApp; ticket
  `canal_tipo = 'whatsapp'` continua no caminho de sempre (regressão).
- Migration: `Contato` sem telefone (só e-mail) salva sem erro.
- Command `email:sincronizar`: falha num canal não impede o sync dos demais.
