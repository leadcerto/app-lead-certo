# Idioma Multilíngue no Atendimento — Spec de Design

## Contexto

Leonardo trouxe uma conversa completa que teve com outra IA sobre identificação
de idioma no atendimento WhatsApp — já com objetivo, conceitos, as 4 camadas de
detecção, regras de prioridade/anti-oscilação, modelo de dados sugerido e uma
lista extensa de refinamentos (glossário por tenant, preservação de dados
sensíveis, fallbacks, privacidade, métricas). Este documento organiza essa ideia
como especificação formal, reconciliando com o que já foi construído em
2026-08-20 e com decisões de arquitetura deste projeto.

**Substitui a seção 4 do `specs/SPEC-012-expansao-internacional-cadastro-empresa.md`**
— aquela seção tratava idioma como parte da expansão pra Portugal/Espanha;
esta spec é mais completa e geral: vale pra **qualquer tenant que atenda
cliente de fora do próprio país**, não só operação internacional. SPEC-012
fica só com país/moeda/gateway/RGPD do cadastro da empresa; onde citar idioma,
aponta pra cá.

## Separação de conceitos (decisão fundamental, evita a maior parte dos bugs futuros)

Quatro idiomas diferentes, nunca confundir:

1. **Idioma do atendente** — escolhido por ele na plataforma (`users.idioma`).
2. **Idioma do tenant** — padrão da interface/prompts institucionais
   (`tenants.locale`, já citado no SPEC-012 seção 4).
3. **Idioma preferido do cliente** — definido por escolha explícita, comando
   manual, ou detecção consistente (`tickets_atendimento.idioma_lead`, campo
   que já existe).
4. **Idioma detectado na mensagem atual** — resultado da análise daquela
   mensagem específica, pode divergir do preferido sem necessariamente mudá-lo
   (ver regra anti-oscilação).

O DDI do telefone é **só um sinal inicial**, nunca confirmação — número
espanhol não significa que a pessoa fala espanhol nem está na Espanha.

## O que já existe (não precisa ser criado)

Construído em 2026-08-20 (item 11 do roteiro daquela sessão):

- `tickets_atendimento.idioma_lead` — idioma da conversa.
- `mensagens.idioma` / `mensagens.conteudo_pt` — idioma detectado por
  mensagem + tradução pro português.
- `TraducaoService` (`detectarIdioma()`/`traduzir()`, via IA/OpenRouter).
- Detecção de entrada: webhooks Uazapi/Covercut chamam `detectarIdioma()` na
  1ª mensagem de texto/áudio substancial de cada ticket (1x, não a cada
  mensagem).
- Tradução de saída: `SdrResponderService` (bot) e
  `KanbanController::enviarMensagem` (atendente humano) traduzem antes de
  enviar.
- UI: selo de idioma no card do Kanban; chat mostra a tradução em português
  como conteúdo principal, com o original disponível como referência — essa
  decisão já foi tomada e construída, mantida aqui (ver seção "Exibição pro
  atendente" abaixo).
- **Isso cobre só a Camada 3** (detecção por IA) do desenho de 4 camadas
  abaixo — as outras 3 (DDI, botão, comando manual), o idioma do
  atendente/tenant, e a origem/confiança da detecção não existem ainda.

**Infra reaproveitável, já em produção** (achado ao levantar este documento):
`KanbanBotaoActionService::enviarBotoes()` — já envia botões interativos reais
do WhatsApp (usado hoje pelas Sequências, `button_settings`). A Camada 2
(escolha de idioma por botão) reaproveita isso — **não é infraestrutura nova**.

## Modelo de dados

**`users`** — novo campo:
- `idioma` (string, ex.: `pt-BR`) — idioma do atendente.

**`tenants`** — novo campo:
- `locale` (string) — idioma padrão da operação, usado nos prompts
  institucionais quando não há conversa específica ainda (mesmo conceito já
  citado no SPEC-012 seção 4, formalizado aqui).

**`tickets_atendimento`** — novos campos, complementando `idioma_lead` que já
existe:
- `idioma_pais_ddi` (string, nullable) — país sugerido pelo DDI do telefone
  do contato, calculado 1x na criação do ticket.
- `idioma_origem` (enum: `ddi`, `botao`, `ia`, `manual`) — de onde veio o
  `idioma_lead` atual.
- `idioma_confianca` (decimal, nullable) — nível de confiança da última
  detecção por IA (não se aplica a `botao`/`manual`, que são sempre 1.0).
- `idioma_atualizado_em` (timestamp, nullable).
- `idioma_aguardando_escolha` (boolean, default false) — true entre o botão
  de escolha ser enviado (Camada 2) e o cliente responder; evita mandar o
  botão de novo a cada mensagem enquanto ele não decide.

**Sem tabela de histórico separada no v1.** A ideia original sugeria uma
tabela de auditoria com cada troca de idioma registrada — decisão aqui é
**não criar agora**: `mensagens.idioma` já guarda o idioma detectado por
mensagem, o que já dá rastreabilidade suficiente pra investigar um caso
específico. Uma tabela dedicada de histórico só se justifica se aparecer um
caso real de suporte que precise dela — YAGNI.

## As 4 camadas de detecção

**Camada 1 — DDI do telefone.** Grátis, sem custo de IA. Extrai o país do DDI
do contato, resolve `idioma_pais_ddi`. Se bate com `tenants.locale`, assume
direto sem perguntar nada (>97% dos casos hoje, segundo o Leonardo) — pura
otimização, nunca é a fonte de verdade final.

**Camada 2 — Botão de escolha, quando o DDI diverge do idioma do tenant.**
Envia via `KanbanBotaoActionService::enviarBotoes()`:
> 🌍 Notamos que seu número é de outro país. Em qual idioma você prefere ser
> atendido?
> [Español] [Português] [English]

Lista de botões limitada aos idiomas suportados pela operação (não todos os
idiomas do mundo). Marca `idioma_aguardando_escolha = true` ao enviar. Se o
cliente não responder, a conversa segue no idioma sugerido pelo DDI — sem
repetir o botão automaticamente (o atendente pode reenviar via comando manual
se precisar, Camada 4).

**Camada 3 — Detecção por IA (já existe, agora é uma de quatro, não a
única).** Roda em texto e em áudio transcrito. Só sugere mudar
`idioma_lead` quando a regra anti-oscilação (abaixo) permitir.

**Camada 4 — Comando manual do atendente.** Reaproveita o padrão de
Respostas Prontas (`/código` já existe — ver `RespostaProntaController`).
Um código dedicado (ex.: `/idioma`) reenvia o botão da Camada 2 a qualquer
momento, por decisão do atendente — cobre o caso que nenhum sinal automático
pega (turista com chip local, ambiguidade que só um humano percebe).

## Regra de prioridade

Quando dois sinais conflitam, vale, em ordem:

1. Escolha explícita do cliente (botão).
2. Alteração manual confirmada pelo atendente.
3. Detecção por IA com alta confiança (ver regra anti-oscilação).
4. Sugestão do DDI.
5. Idioma padrão do tenant.

## Regra anti-oscilação (evita trocar idioma por uma mensagem isolada)

A IA (Camada 3) só pode **mudar** `idioma_lead` (não apenas registrar
`idioma_detectado_mensagem`, que sempre é gravado por mensagem) quando:

- confiança alta **e** duas ou mais mensagens consecutivas no novo idioma, OU
- confiança alta **e** uma mensagem longa (acima de um limiar de
  caracteres/palavras, a definir na implementação) claramente no novo idioma.

Mensagem curta isolada (ex.: "ok", "thanks", "hola") nunca muda o idioma
sozinha. Se o idioma já foi definido por escolha explícita (Camada 2) ou
manual (Camada 4), a IA não sobrepõe silenciosamente — só sinaliza a
divergência (ex.: alerta interno) e deixa o atendente decidir.

## Fluxo de tradução (3 sub-fluxos, já implementados na Camada 3 — sem mudança de mecanismo, só de quando disparam)

1. **Texto/áudio do cliente → atendente**: detecta idioma, traduz pro idioma
   do **atendente** (não mais fixo em português — usa `users.idioma`).
2. **Texto do atendente → cliente**: traduz pro `idioma_lead` do ticket.
3. **Mensagens automáticas do bot/sequências → cliente**: já usam
   `idioma_lead` (mecanismo existente); passam a também considerar
   `tenants.locale` como base do prompt quando ainda não há `idioma_lead`
   definido (ticket novo, primeira mensagem automática antes de qualquer
   detecção).

## Exibição pro atendente

Decisão já tomada e construída em 2026-08-20, mantida: tradução como conteúdo
principal, original disponível como referência (não escondido, não é preciso
expandir) — evita o atendente perder contexto de nomes próprios, endereços,
números que a tradução pode alterar sutilmente.

## Preservação de dados sensíveis na tradução

A tradução (via `TraducaoService`) não deve alterar: números de
pedido/ticket, valores monetários, datas, horários, endereços, telefones,
URLs, nomes próprios e de produtos. Isso já é parcialmente coberto pelo fato
de a tradução ser feita por IA com instrução explícita — reforçar a
instrução do prompt de tradução pra listar essas categorias como "nunca
altere" é ajuste de prompt, não mudança de arquitetura.

## Falhas e fallback

- Áudio inaudível/transcrição falha: mantém mensagem original, avisa o
  atendente que não deu pra processar (mesmo padrão já usado hoje quando a
  transcrição falha por outros motivos).
- IA indisponível pra detectar/traduzir: mantém o idioma atual, não bloqueia
  o envio (mesmo comportamento já garantido pelo `TraducaoService` hoje —
  "falha de tradução nunca bloqueia envio").
- Cliente não responde ao botão de escolha: segue no idioma sugerido pelo
  DDI, sem repetir automaticamente.

## Fora de escopo v1 (registrado, não descartado)

- **Dublagem de áudio (TTS)** — traduzir E gerar novo áudio no idioma do
  cliente. Custo alto (3 chamadas de IA por áudio: transcrição + tradução +
  síntese de voz) e depende de uma feature que nem existe ainda (enviar
  áudio nas Sequências, pendência separada no `TAREFAS.md`).
- **Glossário por tenant** (termos que nunca devem ser traduzidos, ex. nome
  de plano/produto) — valioso, mas fica pra quando houver demanda real de um
  cliente reclamando de tradução inconsistente de um termo específico.
- **Localização completa de todos os textos fixos da interface** (botões,
  telas, e-mails transacionais) — esforço grande e mecânico, listado como
  pendência própria no SPEC-012 seção 7.
- **Moeda/data/hora formatados por região** — parte do SPEC-012, não deste
  documento.
- **Revisão de RGPD** (onde ficam armazenados áudio/transcrição de cliente
  europeu) — SPEC-012 seção 7 já lista isso.
- **Métricas de qualidade** (% de idiomas detectados corretamente, trocas
  manuais, etc.) — vale revisitar depois que a feature estiver rodando de
  verdade com volume.

## Casos de teste / critérios de aceite

1. DDI do cliente bate com `tenants.locale` → segue direto, sem botão.
2. DDI diverge → botão enviado; cliente escolhe → `idioma_lead` atualizado,
   `idioma_origem = 'botao'`, `idioma_aguardando_escolha = false`.
3. DDI bate com o tenant, mas cliente escreve 2 mensagens seguidas em outro
   idioma → `idioma_lead` muda, `idioma_origem = 'ia'`.
4. Mesma situação do caso 3, mas só 1 mensagem curta ("ok") → `idioma_lead`
   **não** muda.
5. Cliente escolheu idioma manualmente (Camada 2 ou 4) → uma mensagem
   isolada em outro idioma **não** sobrepõe a escolha.
6. Atendente digita `/idioma` → botão de escolha reenviado, independente do
   DDI.
7. Cliente não responde ao botão → conversa segue no idioma sugerido pelo
   DDI, sem repetir o botão sozinho.
8. Transcrição de áudio falha → mensagem original mantida, atendente
   avisado, nada quebra.
9. Atendente com `users.idioma = 'en-US'` recebe a tradução das mensagens do
   cliente em inglês, não mais fixo em português.

## Pendências de implementação (a decidir durante o plano, não aqui)

- Limiar exato de "mensagem longa" pra regra anti-oscilação (caracteres ou
  palavras).
- Lista de idiomas suportados por tenant — campo novo ou fixo (pt-BR, pt-PT,
  es-ES, en-US pra começar)?
- De onde vem o valor inicial de `users.idioma` pra atendentes já
  cadastrados (backfill) — provavelmente `pt-BR` como default, com opção de
  trocar nas Configurações.
