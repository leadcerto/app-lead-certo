# Análise de Qualidade da Ficha (GMB) — Reputação + Presença Externa v2 — Design

**Status:** aprovado pelo Leonardo em 2026-09-13.
**Contexto:** sub-projeto 3 do backlog "Análise de Qualidade da Ficha" (ver
`docs/superpowers/specs/2026-09-12-analise-qualidade-ficha-gmb-design.md` e
`docs/superpowers/specs/2026-09-12-gmb-qualidade-dados-location-design.md`, já em produção). Aquele sub-projeto 2
deixou explícito que **Reputação** ficaria para um sub-projeto futuro por exigir uma chamada separada (reviews) —
este spec é esse sub-projeto, mais um refinamento da categoria **Presença Externa** (hoje só checa `websiteUri`
vazio/preenchido) pedido junto pelo Leonardo.

## Referência: Apostila (`/admin/gmb/apostila`)

O item 03 da apostila ("Avaliações — volume, nota, frequência e palavras") já documenta os critérios de negócio
usados abaixo, escritos pelo Leonardo antes deste spec:

> **Analisado:** Quantidade de notas, média, constância de novos comentários, termos usados no texto do review.
> **Boas práticas:** Responder 100% das avaliações em até 24–48h, usando termos locais e nomes de serviço nas
> respostas. Estimular o cliente a citar o serviço e postar foto.
> **IA:** Critério de ouro — a IA lê o texto dos comentários pra responder dúvida subjetiva.

E na seção de disputa de ranking: "Acelerar o ritmo de **novas** avaliações — o Google valoriza recência mais que
volume total; 80 avaliações recebendo 5/semana passa na frente de 300 paradas no tempo."

## Pesquisa na documentação oficial (2026-09-13)

Confirmado em `developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews` e
`.../accounts.locations.reviews/list`:

- Endpoint: `GET https://mybusiness.googleapis.com/v4/{parent=accounts/*/locations/*}/reviews` — **mesma família
  v4** já usada em `GmbPostPublishService::enviarParaGoogleApi()` (que já resolve `accounts/{account}` chamando
  `mybusinessaccountmanagement.googleapis.com/v1/accounts` antes). Este recurso **não tem data de sunset
  anunciada** (diferente de Q&A API e Calls API, essas sim descontinuadas).
- Query params: `pageSize` (máx. 50), `pageToken`, `orderBy` (`rating`, `rating desc`, `updateTime desc` — default
  `updateTime desc`, não existe `createTime desc`).
- **Resposta já vem com `averageRating` (1-5, decimal) e `totalReviewCount` (int) prontos** — não precisa somar
  manualmente nem fazer chamada extra pra esses dois números.
- Resource `Review`: `reviewId`, `reviewer`, `starRating` (enum `ONE`..`FIVE`, não usado diretamente — usamos o
  `averageRating` agregado), `comment` (texto), `createTime`, `updateTime`, `reviewReply { comment, updateTime }`
  (ausente quando o dono nunca respondeu).
- Mesmo escopo OAuth já concedido (`business.manage`) cobre este endpoint — nenhuma reconexão de conta.
- **Fora de escopo, já decidido antes** (`2026-09-12-analise-qualidade-ficha-gmb-design.md`): responder avaliação
  via API (`reviews.updateReply`, que existe e funciona, mas é ação sensível e não entra nesta v1). Este spec é
  só leitura — nunca escreve na ficha do Google.

## Categoria Reputação — arquitetura

### Novo método `buscarDadosReviews(PerfilGmb $perfil, array $dadosLocation): array`

Chamado só quando a categoria Reputação é avaliada (não reaproveita `buscarDadosLocation()` porque é uma API
diferente — v4, não v1 — mas **reaproveita o resultado de `buscarDadosLocation()`** só pra ler
`categories.primaryCategory`/`additionalCategories` como fonte das palavras-chave, ver abaixo). Retorna
`['sucesso' => true, 'dados' => [...]]` ou `['sucesso' => false, 'motivo' => string]`, mesmo contrato de
`buscarDadosLocation()`.

Passo a passo (espelha `GmbPostPublishService::enviarParaGoogleApi()`, duplicando a lógica de resolução de
conta — ver "Débito técnico aceito" no fim):

1. Reaproveita o mesmo token resolvido para `buscarDadosLocation()` (passado como parâmetro, não busca de novo).
2. Busca a conta: `GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts` com o token — usa
   `accounts[0].name`. Erros (403/429/sem conta) retornam `sucesso: false` com motivo análogo ao já usado em
   `GmbPostPublishService` (reaproveitar as mesmas mensagens de erro 403/429 já escritas lá, adaptando o texto
   pro contexto de reviews).
3. Chama `GET {accountName}/locations/{locationId}/reviews?pageSize=20&orderBy=updateTime desc` com o token.
4. `successful()` → `['sucesso' => true, 'dados' => $res->json()]` (contém `reviews[]`, `averageRating`,
   `totalReviewCount`, opcionalmente `nextPageToken` — nunca paginamos além da primeira página, ver "Fora de
   escopo").
5. Erro → mesmo padrão de tratamento de status (403 SERVICE_DISABLED / 403 genérico / 404 / 429-quota / outro)
   já usado em `buscarDadosLocation()`, adaptado pro texto de reviews. `Log::warning('Reviews API falhou', [...])`.

### `avaliarReputacao(array $dadosReviews, array $dadosLocation, PerfilGmb $perfil): array`

(`$perfil` é necessário aqui — diferente das outras categorias — porque a lista de palavras-chave usa
`$perfil->city`, não só dados vindos do Google.)

Só chamada quando **tanto** `buscarDadosLocation()` quanto `buscarDadosReviews()` tiveram sucesso — se qualquer
um falhar, a categoria vira `categoriaErro('Reputação', $motivo)` (motivo do que falhou primeiro: location tem
prioridade, já que reviews depende dele pro `locationId`).

**Palavras-chave** (reaproveitadas nos itens 3 e 4): `array_filter([$dadosLocation['categories']['primaryCategory']['displayName'] ?? null, ...array_column($dadosLocation['categories']['additionalCategories'] ?? [], 'displayName'), $perfil->city])` — comparação via `mb_stripos($texto, $palavra) !== false` (case-insensitive, substring simples, sem normalização de acento pra manter v1 simples).

**Caso especial — zero avaliações** (`totalReviewCount === 0`): nota 0, um único diagnóstico `erro`: "Nenhuma
avaliação registrada nesta ficha ainda. Comece a coletar avaliações de clientes reais — ver a seção 'Avaliações'
na Apostila." com ação `acao_label: 'Ver na Apostila'`, `acao_url: route('admin.gmb-apostila.index') . '#pilares'`.
Pula os 4 sub-critérios abaixo (não fazem sentido sem dados).

Caso contrário, 4 sub-critérios somando 100 pts, **cada um sempre gera diagnóstico** (`ok` quando ideal, senão
`aviso`/`erro`) — mesmo padrão "auditorias aprovadas visíveis" já adotado em Identidade/Localização:

| # | Sub-critério | Fonte | Condição | Pontos |
|---|---|---|---|---|
| 1 | Volume de avaliações | `totalReviewCount` | ≥ 50 | +25 |
| | | | 10–49 | +15 |
| | | | 1–9 | +5 |
| 2 | Recorrência | `max(createTime)` entre os `reviews[]` retornados (até 20, ordenados por `updateTime desc` — ver nota de aproximação abaixo) | ≤ 7 dias atrás | +25 |
| | | | > 7 dias atrás | +0 |
| 3a | Nota média | `averageRating` | ≥ 4.5 | +15 |
| | | | 4.0–4.4 | +10 |
| | | | < 4.0 | +0 |
| 3b | Menção a categoria/cidade no texto | % de `reviews[].comment` (até 20) que contém ≥1 palavra-chave | ≥ 50% | +10 |
| | | | < 50% | +0 |
| 4a | Taxa de resposta | % de `reviews[]` (até 20) com `reviewReply` presente | ≥ 80% | +10 |
| | | | 1–79% | +5 |
| | | | 0% | +0 |
| 4b | Prazo médio de resposta | média de (`reviewReply.updateTime - createTime`) nas avaliações respondidas | ≤ 48h | +10 |
| | | | > 48h ou nenhuma respondida | +0 |
| 4c | Termos nas respostas | ≥1 `reviewReply.comment` (entre as respondidas) contém ≥1 palavra-chave | sim | +5 |
| | | | não ou nenhuma respondida | +0 |

**Nota de aproximação (registrar no código como comentário):** a API só ordena por `updateTime desc` (não existe
`createTime desc`). Usamos `max(createTime)` dentro da página de até 20 retornada em vez de confiar que o
`reviews[0]` é o mais recente por criação — mitiga o caso de uma avaliação antiga só editada recentemente
aparecer primeiro, mas ainda pode errar se houver mais de 20 avaliações não capturadas na página E a mais nova
delas não estiver entre as 20 mais recentemente atualizadas (extremamente improvável na prática). Aceito como
limitação de v1 — não pagina além da primeira página.

**Diagnósticos** (uma linha por sub-critério, sempre; ação em todos os `aviso`/`erro`:
`acao_label: 'Abrir Google Business Profile Manager'`, `acao_url: 'https://business.google.com/'`, exceto onde
citado):

- Volume: `ok` "N avaliações no total." / `aviso` "N avaliações no total; o ideal é pelo menos 50." (10-49) /
  `aviso` "Só N avaliação(ões); ideal é pelo menos 50." (1-9).
- Recorrência: `ok` "Avaliação mais recente há N dia(s) — dentro do ideal (a cada 7 dias)." / `aviso` "Avaliação
  mais recente há N dias. O Google valoriza recência mais que volume total — priorize pedir novas avaliações."
- Nota média: `ok` "Nota média N,N — acima de 4.5." / `aviso` "Nota média N,N; o ideal é 4.5 ou mais." (4.0-4.4) /
  `erro` "Nota média N,N — abaixo de 4.0. Revise o atendimento antes de acelerar o volume de avaliações." (<4.0).
- Menção a categoria/cidade: `ok` "N% das avaliações recentes citam o serviço ou a cidade." / `aviso` "Só N% das
  avaliações recentes citam o serviço ou a cidade prestado; estimule o cliente a mencionar o que foi feito e
  onde." (sem ação de link — é orientação de processo, não de configuração no Google).
- Taxa de resposta: `ok` "N% das avaliações recentes têm resposta do dono." / `aviso`/`erro` "Só N% das
  avaliações recentes foram respondidas; o ideal é responder 100%."
- Prazo de resposta: `ok` "Tempo médio de resposta: Nh — dentro do ideal (até 48h)." / `aviso` "Tempo médio de
  resposta: Nh; o ideal é até 48h." / (nenhuma respondida) `erro` "Nenhuma avaliação recente foi respondida.
  Responda o quanto antes — o ideal é em até 48h." (sem ação de link).
- Termos na resposta: `ok` "Pelo menos uma resposta recente cita o serviço ou a cidade." / `aviso` "Nenhuma
  resposta recente cita o serviço ou a cidade prestado — respostas genéricas perdem força. Cite o serviço e o
  nome do cliente quando possível." (sem ação de link).

## Categoria Presença Externa v2 — arquitetura

`avaliarPresencaExterna(array $dados, PerfilGmb $perfil): array` — assinatura muda (hoje só recebe `array
$dados`) porque passa a precisar de `$perfil->tenant` (confirmado: `PerfilGmb::tenant()` já existe,
`app/Models/PerfilGmb.php:34`). Reaproveita 100% o `$location['dados']` já buscado — nenhuma chamada nova à
API.

100 pts, 3 sub-critérios, cada um sempre gera diagnóstico:

| Sub-critério | Condição | Pontos |
|---|---|---|
| Site vinculado | `websiteUri` tem path além de `/` (`parse_url($url, PHP_URL_PATH)` não vazio e diferente de `/`) | +40 |
| | `websiteUri` presente mas só a raiz (path vazio ou `/`) | +25 |
| | `websiteUri` ausente | +0 |
| WhatsApp cadastrado | `Tenant.whatsapp_phone` não vazio | +30 (senão +0) |
| Rede social cadastrada | pelo menos 1 de `instagram_url`/`facebook_url`/`youtube_url`/`linkedin_url` não vazio | +30 (senão +0) |

Diagnósticos:
- Site com página própria → `ok`: "Site vinculado aponta para uma página própria (não a home genérica)."
- Site só a home → `aviso`: "O site vinculado aponta para a página inicial, não para uma página própria desta
  localização. Uma página dedicada (com endereço, telefone e serviços desta unidade) vale mais para o Google e
  para o cliente." (mesma ação `acao_label: 'Abrir Google Business Profile Manager'`).
- Sem site → mantém o `erro` já existente hoje ("Nenhum site cadastrado na ficha.").
- WhatsApp presente → `ok`: "WhatsApp cadastrado no sistema." / ausente → `aviso`: "WhatsApp não cadastrado no
  sistema." **Sem ação de link** — decisão explícita: a única tela que edita esse campo do `Tenant` é
  `admin.empresas.edit`, com middleware `role:admin` ("não é autosserviço", comentário já existente em
  `routes/web.php:282`). O Dono de um tenant cliente não tem acesso a essa rota — linkar pra lá seria um 403 pra
  quem mais precisa do diagnóstico. Fica só o texto informativo por ora; uma tela de autosserviço pro Dono
  editar isso é decisão de produto fora deste sub-projeto.
- Rede social presente → `ok`: "Pelo menos uma rede social cadastrada no sistema." / ausente → `aviso`:
  "Nenhuma rede social cadastrada no sistema." Sem ação de link, mesmo motivo acima.
- Mantém o diagnóstico `info` fixo do Schema.org que já existe hoje, sem mudança.

**Importante:** WhatsApp e redes sociais **não são campos da ficha do Google** (Business Profile não tem esses
campos nativos) — este sub-critério audita o **nosso próprio cadastro** (`Tenant`), não o que está visível na
ficha do Google. Isso deve ficar claro pro usuário: a mensagem de diagnóstico fala em "cadastrado no sistema",
nunca "na ficha do Google", pra não confundir as duas coisas.

## Mudança em `avaliar()`

```php
$location = $this->buscarDadosLocation($perfil);

// ... identidade/localizacao/presenca_externa/saude_risco como já é hoje ...

$categorias['reputacao'] = $location['sucesso']
    ? (function () use ($perfil, $location) {
        $reviews = $this->buscarDadosReviews($perfil, $location['dados']);
        return $reviews['sucesso']
            ? $this->avaliarReputacao($reviews['dados'], $location['dados'], $perfil)
            : $this->categoriaErro(self::CATEGORIAS_LABELS['reputacao'], $reviews['motivo']);
    })()
    : $this->categoriaErro(self::CATEGORIAS_LABELS['reputacao'], $location['motivo']);

$categorias['presenca_externa'] = $location['sucesso']
    ? $this->avaliarPresencaExterna($location['dados'], $perfil)
    : $this->categoriaErro(self::CATEGORIAS_LABELS['presenca_externa'], $location['motivo']);
```

(Pseudocódigo ilustrativo — o plano de implementação define a assinatura exata; closure acima é só pra deixar
clara a dependência em cascata: sem location não tem review, sem review não tem reputação.)

## Testes

`Http::fake()` simulando `mybusinessbusinessinformation.googleapis.com/*` (já existe), + novo wildcard
`mybusinessaccountmanagement.googleapis.com/*` (contas) e `mybusiness.googleapis.com/v4/*/reviews*` (reviews).

**Reputação:**
1. Perfil "bom": ≥50 reviews, `averageRating` 4.8, review mais recente há 1 dia, 100% respondidas em <48h,
   comentários e respostas citando a categoria → nota 100.
2. Perfil "incompleto": `totalReviewCount` baixo (5), nota 3.5, review mais antiga (30 dias), 0% respondidas,
   nenhum comentário cita a categoria → nota baixa com os 7 diagnósticos certos (um por sub-critério: volume,
   recorrência, nota média, menção, taxa de resposta, prazo — aqui como "nenhuma respondida" — e termos).
3. `totalReviewCount: 0` → nota 0, 1 diagnóstico só, mensagem específica de "nenhuma avaliação".
4. Falha em `buscarDadosLocation()` (sem `google_location_id`) → Reputação também vira `erro` (cascata).
5. `buscarDadosLocation()` ok mas conta/reviews falha (403/404/429) → Reputação `erro` com motivo específico de
   reviews, as outras 4 categorias continuam `calculado` normalmente (não-cascata no sentido inverso).
6. Cálculo de prazo médio de resposta com mistura de respondidas/não respondidas (confirma que só entram na
   média as respondidas).
7. `mb_stripos` mesmo com acentuação (ex.: categoria "Logística" aparecendo como "logistica" sem acento no
   comentário — v1 não normaliza, teste documenta essa limitação explicitamente, não é bug).

**Presença Externa v2:**
8. Site com path (`https://frete.rio.br/barra-da-tijuca`) → 40 pts, diagnóstico `ok` de página própria.
9. Site só raiz (`https://frete.rio.br/` e `https://frete.rio.br`, os dois formatos) → 25 pts, diagnóstico de
   aviso.
10. `Tenant.whatsapp_phone` e pelo menos uma rede social preenchidos → +60 pts somados aos do site.
11. Nenhum dos dois → só os pontos do site.

## Fora de escopo deste sub-projeto

- Responder avaliação via API (`reviews.updateReply`) — decisão já registrada, continua fora.
- Paginar além da primeira página de reviews (até 50, aqui usamos 20) — perfis com centenas de reviews terão os
  sub-critérios 3b/4a/4b/4c calculados só sobre a amostra mais recente, não o histórico completo. Volume (1) e
  nota média (3a) usam os agregados corretos (`totalReviewCount`/`averageRating`), não são afetados por isso.
- Normalização de acentuação na busca de palavras-chave (ver teste 7) — fica pra v2 se o Leonardo achar que gera
  falso-negativo relevante na prática.
- Verificação automática se o WhatsApp/rede social cadastrados no `Tenant` estão de fato **visíveis** na ficha do
  Google (não são campos nativos da ficha — não tem o que verificar lá; ver nota acima).
- Sugestão de resposta pronta por IA pras avaliações mal respondidas — a apostila já cita "a IA lê o texto dos
  comentários", mas gerar a resposta em si fica pra v2 (mesmo padrão de "geração de descrição por IA" já adiado
  no sub-projeto 2).

## Débito técnico aceito

- `buscarDadosReviews()` duplica a lógica de resolução de conta (`accounts[0].name`) que já existe em
  `GmbPostPublishService::enviarParaGoogleApi()`. Extrair um helper compartilhado é uma melhoria válida, mas não
  bloqueia este sub-projeto — os dois call sites já toleram esse padrão desde o sub-projeto anterior (ver
  "Débito técnico" equivalente no spec de Location, que também aceitou duplicação pontual do fallback de token).
