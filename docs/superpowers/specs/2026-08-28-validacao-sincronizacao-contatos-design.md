# Validação e Sincronização de Cadastros de Contatos — Design

> **Status:** Design concluído, aguardando revisão do Leonardo antes do plano de implementação.
> **Contexto:** repo `leadcerto-app`. Pedido do Leonardo (2026-08-28), depois de comparar
> cadastros reais no Google Contatos do Frete Rio e achar inconsistências.

## 1. Problema

Investigando por que aposentar o botão legado "Atualizar Google"
(`atualizarGoogleSobrenome`), o Leonardo comparou dois contatos reais no
Google Contatos do Frete Rio e achou duas inconsistências que levaram a uma
investigação maior na base de Contatos (só leitura, nada alterado até este
design ser aprovado):

1. Um contato ("Raissa Maycon 9537") tem no campo "Nome do meio" do Google
   não o ID dela no banco (convenção atual), e sim o final do telefone —
   resquício de uma convenção mais antiga do código, nunca corrigida nos
   registros já existentes.
2. Outro contato ("Ademir Nunes") tem **5 cadastros duplicados** no banco,
   cada um com o telefone grafado de um jeito ligeiramente diferente, dois
   deles com o campo Nome contaminado com ID de outro registro grudado
   ("Ademir Nunes 17278 17423").

Confirmação do Leonardo sobre o real total de contatos do Frete Rio no
Google: **21.363** — contra **29.732** no banco da Lead Certo. A diferença
(~8.400) é duplicidade real, não ruído de query.

Pedido do Leonardo: *"criar um passo a passo definitivo de validação e
sincronização destes cadastros"* — e que esse passo a passo já nasça como
processo repetível pra qualquer empresa nova que entrar no sistema, não só
um ajuste pontual no Frete Rio.

## 2. Escala do problema (medido no banco, 2026-08-28)

- **29.732 contatos no total.**
- **13.711 contatos (46%)** foram criados num único lote, no mesmo minuto:
  `2026-07-04 15:52` — nomes com prefixo "Frt", claramente o onboarding do
  Frete Rio (importação em massa da agenda/CRM anterior).
- **3.739 contatos** têm nome real com dígitos vazados grudados no final
  (padrão "Nome Sobrenome NNNN") — bug distinto de "Sem Nome NNNN"
  (**1.204 contatos**), que é convenção legítima pra lead ainda sem nome
  identificado (`Contato::semNomeReal()`).
- **~8.454 grupos de quase-duplicata por telefone** (mesmo número real,
  grafado diferente), envolvendo bem mais da metade da base.
- **Zero duplicatas por telefone EXATO** — por isso nada disso nunca foi
  pego automaticamente: cada variante tem uma grafia ligeiramente
  diferente, nunca colide como string idêntica.
- **7.881 contatos não começam com "55"** — a grande maioria (7.816) é só o
  padrão brasileiro sem o "55" na frente (já coberto pela correção de
  formato existente). Uma fatia menor é **internacional de verdade**:
  confirmados códigos de país como `351` (Portugal), `44` (Reino Unido),
  `39` (Itália), `49` (Alemanha), `52` (México), `54` (Argentina) — pessoas
  reais, não brasileiro malformado.
- Um resíduo de **159 contatos** com telefone mal formatado criados depois
  do onboarding (16/07 em diante), incluindo um pico de 1.038 em 19/08 que
  bate com o incidente já documentado (`SincronizarAgendaWhatsAppJob`
  importando agenda do celular, 998 tickets falsos) — não é um bug novo.

**Causa raiz de por que isso nunca foi limpo:** o comando que já existe
(`contatos:mesclar-duplicatas` / `ContatoMergeService`) só cobre 2 padrões
de telefone malformado (12 dígitos sem o "9", 11 dígitos sem o "55"),
comparando contra um candidato de 13 dígitos **exato**. Não cobre o padrão
"0" espúrio na frente, nem o padrão de "55 duplicado" (achado nesta
investigação — ver seção 4), nem variações de tamanho maiores. Também não
está agendado (`routes/console.php`) — só roda manual.

## 3. Princípio fundamental (definido pelo Leonardo)

> "um contato só tem um número de telefone. se o número é válido ele é um
> contato... se tiver dois Paulo Cesar mas com números de telefone
> diferentes, são dois contatos com o mesmo nome, só isso... o que manda é
> o número de telefone completo depois de corrigido"

**O telefone (depois de corrigido pro formato canônico) é a única chave de
deduplicação. Nome nunca decide — nem pra confirmar, nem pra negar uma
duplicata.** Dois contatos com nomes parecidos ou idênticos mas telefones
genuinamente diferentes são dois contatos reais, ponto final.

Isso foi validado com dado real durante o design: um agrupamento inicial
por "últimos 8 dígitos parecidos" quase juntou "Pablo Cesar Da Silva" (DDD
19) com "Paulo Cesar" (DDD 21) — pessoas diferentes, coincidência de
sufixo. A regra corrigida (candidato de reparo **exato**, nunca
aproximado) nunca cruza os dois.

## 4. Regras de reparo de telefone (candidato exato, nunca aproximado)

Pra um telefone malformado ser considerado "a mesma pessoa" que outro
registro, o reparo tem que produzir um número que bate **exatamente**
(string idêntica) com o outro registro. Nunca por semelhança, nunca por
sufixo, nunca por nome.

Padrões confirmados com dado real do Frete Rio:

1. **Já canônico:** `55` + DDD (2 díg.) + `9` + 8 dígitos = 13 dígitos.
2. **12 dígitos, falta o "9" do celular:** `55` + DDD + 8 dígitos
   (começando 6/7/8/9) → insere `9` na 5ª posição.
3. **11 dígitos, falta o "55":** DDD + 9 dígitos (começando 9) → prefixa
   `55`.
4. **Prefixo "0" espúrio:** remove o "0" inicial e tenta os padrões acima
   recursivamente (ex.: `0212124460642` → `212124460642` → ainda não bate
   → segue pra próxima regra ou LEAD INVALIDO).
5. **"55" duplicado (achado nesta investigação):** quando um número já
   malformado passa de novo por um processo que prefixa "55" sem checar se
   já tinha — produz `5555...`. Regra: se remover um "55" da frente ainda
   sobra um candidato de 11-13 dígitos plausível, tenta os padrões 1-3
   nesse resultado também. Exemplo real: `5555481126376` → remove um `55`
   → `55481126376` (ainda não canônico, mas correto seguir tentando as
   outras regras nesse resultado).
6. **Código de país estrangeiro reconhecido:** se o número já começa com
   um código de país diferente de `55` de uma lista reconhecida (`351`,
   `44`, `39`, `49`, `52`, `54`, e outros comuns) e tem um tamanho
   plausível pra esse país, é tratado como **já válido no formato próprio**
   — nunca tenta forçar em molde brasileiro.

**Se nenhuma regra produzir um candidato válido (nem brasileiro, nem
internacional reconhecido), e não há ambiguidade a resolver — o registro
vai pra revisão manual.** Não existe tentativa de "adivinhar" um dígito
perdido.

### Empate real (2+ candidatos com formato válido)

Confirmado com dado real que isso acontece por coincidência de sufixo
(Pablo/Paulo). Quando dois ou mais registros de um mesmo "grupo suspeito"
já têm formato canônico válido **e são números diferentes de verdade** —
não é mesclagem, são contatos distintos, cada um segue seu próprio caminho
de validação normalmente. Não há "empate" de fato nesse caso — a regra do
candidato exato já os separa corretamente (ver seção 3).

Um empate de verdade só existiria se dois candidatos de reparo **exatos e
idênticos** apontassem pra registros diferentes — situação não observada
na amostra real, mas se acontecer, cai em LEAD INVALIDO por segurança.

## 5. As 4 etiquetas (estado de validação, via Google Contact Groups)

Mecanismo igual ao já implementado para "Lead Certo - Lead" / "Lead Certo -
Pessoal" (`ProvisionarEtiquetasGoogleJob`, hook `GoogleToken::booted()`) —
**mas eixo diferente e independente**. Aquelas etiquetas respondem "isso é
um lead comercial ou não" (inclusão no funil). Estas 4 respondem "esse
cadastro está validado ou não" (qualidade do dado). Um contato pode ter
etiquetas dos dois grupos ao mesmo tempo.

| Etiqueta | Quando aplica |
|---|---|
| 🚩 **NOVOS LEADS** | Todo lead criado a partir de agora, fluxo normal do dia a dia |
| 🚩 **LEADS EM ANÁLISE** | Toda a base pré-existente de uma empresa, no momento em que ela conecta o Google — ponto de partida, antes de qualquer validação rodar |
| 🚩 **LEAD CERTO** | Telefone canônico (brasileiro ou internacional reconhecido) **e** sem duplicata não resolvida — critério único, nome não entra na conta |
| 🚩 ⚠️ **LEAD INVALIDO** | Telefone não resolve por nenhuma regra conhecida, ou ambiguidade real não resolvida automaticamente — revisão manual |

**As 4 etiquetas são mutuamente exclusivas — um contato passa PELO estado,
não acumula.** Ao sair de NOVOS LEADS ou LEADS EM ANÁLISE pra LEAD CERTO ou
LEAD INVALIDO, a etiqueta de origem é removida. Nunca fica um contato com
"NOVOS LEADS" e "LEAD CERTO" ao mesmo tempo.

### Fluxo de decisão por contato

1. Telefone já canônico (BR ou internacional reconhecido) e único no banco
   → **LEAD CERTO** direto.
2. Telefone malformado, mas existe candidato de reparo **exato** batendo
   com outro registro → mescla de verdade via `ContatoMergeService`
   (já migra tickets, notas, chamadas perdidas, envios de formulário,
   auditoria, vínculos de tenant, enriquece campos vazios) → sobrevivente
   vira **LEAD CERTO**.
3. Telefone malformado, mas é o único registro daquele número (nenhum
   candidato de reparo bate com outro registro existente) → corrige o
   formato no próprio registro (sem mesclar, não há com quê) → **LEAD
   CERTO**.
4. Não resolve por nenhuma regra conhecida → **LEAD INVALIDO**, revisão
   manual do Leonardo/time na tela do Google Contatos mesmo.

## 6. Processo para o Frete Rio (limpeza histórica)

1. Marcar toda a base atual (~29.732, menos o que já for claramente
   "novo" a partir de hoje) como **LEADS EM ANÁLISE**.
2. Rodar a validação (seção 5) em lote sobre toda a base.
3. Resultado esperado: maioria resolve automaticamente pra **LEAD CERTO**
   (mesclado ou autocorrigido); uma fatia cai em **LEAD INVALIDO** pra
   revisão manual do Leonardo — inclusive os casos de telefone
   genuinamente corrompido além de reparo mecânico (achado real: um dos 5
   registros do Ademir tem um dígito perdido que nenhuma regra reconstrói
   com segurança).
4. **Antes de rodar em produção:** validar o motor de regras contra uma
   amostra real maior (o design já testou ~50 contatos reais de 11 grupos
   — resultado limpo, sem falso positivo) e apresentar um dry-run completo
   pro Leonardo revisar antes de aplicar mesclagens de verdade.

## 7. Processo para empresa nova (generalização)

No mesmo gancho já existente (`GoogleToken::booted()` →
`ProvisionarEtiquetasGoogleJob`):

1. Provisiona as 4 etiquetas novas (além de Lead/Pessoal já existentes).
2. Marca toda a base existente do tenant, no momento da conexão, como
   **LEADS EM ANÁLISE**.
3. A validação (seção 5) roda em cima disso — mesmo processo do Frete Rio,
   mas automático desde o primeiro dia, sem precisar de intervenção manual
   de setup.
4. Leads novos, dali em diante, entram em **NOVOS LEADS** e passam pela
   mesma validação.

## 8. Fora de escopo deste design

- Não mexe nas etiquetas que o **cliente** já usa no Google dele (mesma
  garantia do design de sync bidirecional de 2026-08-26).
- Não decide o que fazer com os contatos que caem em LEAD INVALIDO além de
  marcá-los — a ação (mesclar manualmente, corrigir, descartar) é do
  Leonardo/time, feita na própria tela do Google Contatos.
- Não cobre validação de e-mail, nome, ou qualquer outro campo além de
  telefone — critério de LEAD CERTO é só telefone canônico + sem
  duplicata, por decisão explícita do Leonardo.
