# Análise de Qualidade da Ficha (GMB) — Dados de Location — Design

**Status:** aprovado pelo Leonardo em 2026-09-12.
**Contexto:** sub-projeto 2 do backlog "Análise de Qualidade da Ficha" (ver `docs/superpowers/specs/2026-09-12-analise-qualidade-ficha-gmb-design.md`
e a seção "Próximos planos" de `docs/superpowers/plans/2026-09-12-analise-qualidade-ficha-gmb.md`). A fundação (tabela
`gmb_qualidade_scores`, model, `GmbQualidadeService`, controller, rotas, view, coluna na listagem) e a categoria
**Atividade** já estão em produção.

Este spec cobre as **4 categorias que reaproveitam a mesma chamada à API do Google** (Business Information API v1,
`locations.get`): **Identidade**, **Localização**, **Presença Externa** e **Saúde/Risco**. As categorias
**Conteúdo** e **Reputação** (que exigem chamadas separadas — mídia e reviews) ficam para sub-projetos futuros.

## Pesquisa na documentação oficial (2026-09-12)

Confirmado em `developers.google.com/my-business/reference/businessinformation/rest/v1/locations`:

- Endpoint: `GET https://mybusinessbusinessinformation.googleapis.com/v1/{name=locations/*}` — o `name` é
  **`locations/{location_id}`**, sem prefixo `accounts/{account_id}` (diferente da v4 usada em `GmbPostPublishService`).
  Não é preciso buscar a lista de contas antes — uma chamada só.
- `readMask` é **obrigatório**: lista separada por vírgula dos campos a retornar (formato `FieldMask`).
- Campos confirmados no recurso `Location`: `categories` (objeto com `primaryCategory` e `additionalCategories`),
  `title` (string), `profile.description` (string), `storefrontAddress` (`PostalAddress`), `serviceArea` (objeto
  com `businessType` e `places`), `websiteUri` (string), `regularHours` (`BusinessHours` com `periods[]`),
  `specialHours`, `phoneNumbers`.
- O escopo OAuth já concedido aos tokens existentes (`https://www.googleapis.com/auth/business.manage`, ver
  `GoogleService::SCOPES`) cobre esta API — **nenhuma reconexão de conta é necessária**.

## Novo status de categoria: `erro`

Hoje `GmbQualidadeService` só produz `status: 'calculado'` (nota real) ou `status: 'pendente'` (categoria ainda
não implementada, mensagem fixa "chega em uma próxima atualização"). Este sub-projeto introduz um terceiro
status, **`erro`**, para quando a chamada ao Google falha por motivo alheio ao conteúdo da ficha (sem token, sem
`google_location_id`, API desativada, location não encontrada, quota excedida). Diferença semântica:

- `pendente` → "essa categoria ainda não existe no sistema".
- `erro` → "essa categoria existe e tentou calcular, mas não conseguiu buscar o dado agora — eis o motivo e como
  resolver".

Ambos ficam fora da média (`nota_geral` continua sendo a média apenas de `status === 'calculado'` — nenhuma
mudança nesse cálculo). Na view (`resources/views/gmb-qualidade/show.blade.php`), o badge cinza passa a cobrir
qualquer `status !== 'calculado'`: texto "Em breve" para `pendente`, "Indisponível" para `erro`. O diagnóstico de
uma categoria `erro` usa `tipo: 'erro'` com a mensagem explicando a causa real (reaproveitando o texto que o
Google/nosso código já produzem).

## Arquitetura

Um novo método privado em `GmbQualidadeService`:

```php
private function buscarDadosLocation(PerfilGmb $perfil): array
// Retorna ['sucesso' => true, 'dados' => array] ou ['sucesso' => false, 'motivo' => string]
```

Chamado **uma única vez** dentro de `avaliar()`, antes do loop de categorias. As 4 categorias novas recebem o
resultado (dados ou motivo do erro) e nunca fazem chamada própria à API.

### `buscarDadosLocation()` — passo a passo

1. Se `$perfil->google_location_id` estiver vazio → `['sucesso' => false, 'motivo' => "O perfil '{$perfil->nome}' não possui o 'ID do Perfil no Google' cadastrado. Acesse GMB → Perfis GMB, edite este perfil e preencha o ID da empresa no Google."]`.
2. Busca o token: `GoogleToken::withoutGlobalScopes()->where('tenant_id', $perfil->tenant_id)->first() ?? GoogleToken::withoutGlobalScopes()->first()` (mesmo padrão de `GmbPostPublishService::publicar()`).
3. Se não houver token → `['sucesso' => false, 'motivo' => 'Nenhuma conta Google conectada encontrada. Acesse o menu "Integrações" e conecte a conta Google que gerencia os perfis GMB.']`.
4. Se `$token->expires_at?->isPast()` → `app(GoogleService::class)->renovarToken($token)` e `$token->refresh()` (mesmo padrão já usado).
5. Monta `$locationId = preg_replace('#^locations/#', '', trim($perfil->google_location_id))` e chama:
   ```
   GET https://mybusinessbusinessinformation.googleapis.com/v1/locations/{$locationId}
       ?readMask=categories,title,profile,storefrontAddress,serviceArea,websiteUri,regularHours,specialHours,phoneNumbers
   ```
   com `Http::withToken($token->access_token)->timeout(15)`.
6. Tratamento de resposta:
   - `successful()` → `['sucesso' => true, 'dados' => $res->json()]`.
   - status `403` e corpo contém `SERVICE_DISABLED` ou `has not been used in project` → motivo: `'A API "Business Information API" precisa ser ativada no Google Cloud Console: https://console.developers.google.com/apis/api/mybusinessbusinessinformation.googleapis.com/overview?project=159179119828'`.
   - status `403` (outro motivo) → motivo: `'Permissão do Google Meu Negócio pendente para ler dados da ficha. Reconecte a conta Google em "Integrações". Detalhes: ' . ($erroGoogle)`.
   - status `404` → motivo: `"Google retornou 404: Localização não encontrada para o ID '{$locationId}'. Verifique o ID do Perfil da Empresa em GMB → Perfis GMB."`.
   - status `429` ou corpo contém `Quota exceeded` → motivo: `'Google retornou 429 (Quota excedida). Tente novamente em alguns instantes.'`.
   - qualquer outro erro → motivo: `"Erro Google ({$status}): {$erroGoogle}"`.
   - Toda chamada falha também grava `Log::warning('Business Information API falhou', [...])` (mesmo padrão de `enviarParaGoogleApi`).

### Fluxo em `avaliar()`

```php
$location = $this->buscarDadosLocation($perfil);

foreach (['identidade', 'localizacao', 'presenca_externa', 'saude_risco'] as $chave) {
    $categorias[$chave] = $location['sucesso']
        ? $this->{'avaliar' . Str::studly($chave)}($location['dados'])
        : $this->categoriaErro(self::CATEGORIAS_LABELS[$chave], $location['motivo']);
}
```

(nomes reais dos métodos definidos no plano de implementação, sem `Str::studly` mágico — cada categoria chama
seu método explícito; o pseudocódigo acima é só ilustrativo da ordem de execução).

Novo helper, espelhando `categoriaPendente()`:

```php
private function categoriaErro(string $label, string $motivo): array
{
    return [
        'nota'   => null,
        'status' => 'erro',
        'label'  => $label,
        'diagnosticos' => [[
            'tipo'       => 'erro',
            'mensagem'   => $motivo,
            'acao_label' => null,
            'acao_url'   => null,
        ]],
    ];
}
```

## Critérios de nota por categoria

Todas somam exatamente 100 quando cada sub-critério está no ideal. Todos os campos lidos vêm do payload retornado
por `buscarDadosLocation()` (chaves conforme a Business Information API v1).

### Identidade

| Sub-critério | Condição | Pontos |
|---|---|---|
| Categoria primária | `categories.primaryCategory.displayName` não vazio | +40 (senão +0) |
| Categorias secundárias | `count(categories.additionalCategories)` entre 3 e 5 | +20 |
| | 1 ou 2 | +10 |
| | 0 | +0 |
| | 6 ou mais | +15 |
| Nome sem *keyword stuffing* | `title` NÃO contém nenhum de `\|`, `•`, `:`, `" - "` | +20 (senão +0) |
| Descrição | `strlen(profile.description)` ≥ 150 | +20 |
| | entre 1 e 149 | +10 |
| | vazia/ausente | +0 |

Diagnósticos (um por sub-critério que não atingiu o máximo, mais um "ok" geral se tudo perfeito):
- Categoria ausente → `erro`: "Categoria principal não definida na ficha. É o critério de ranqueamento mais
  importante do Google." + ação: `acao_label: 'Abrir Google Business Profile Manager'`, `acao_url: 'https://business.google.com/'`.
- Secundárias fora do ideal → `aviso`: "Você tem N categoria(s) secundária(s); o ideal é entre 3 e 5." (mesma ação acima).
- Nome com possível *stuffing* → `aviso`: "O nome da ficha parece conter termos extras além do nome real do
  negócio (ex: separadores como '\|', '•', ':' ou ' - '). O Google pode suspender fichas com nome fora do
  padrão." (mesma ação acima).
- Descrição vazia → `erro`: "Descrição ausente. Escreva uma descrição de pelo menos 150 caracteres direto no
  Google Business Profile Manager." + ação: `acao_label: 'Abrir Google Business Profile Manager'`,
  `acao_url: 'https://business.google.com/'`.
- Descrição curta (1-149 caracteres) → `aviso`: "Descrição com apenas N caractere(s); o ideal é pelo menos 150."
  + mesma ação acima. (Geração de descrição por IA fica fora de escopo desta v1 — não existe endpoint pronto
  para isso; ver "Fora de escopo".)
- Tudo no ideal → `ok`: "Categoria, categorias secundárias, nome e descrição bem preenchidos."

### Localização

Detecta o tipo de perfil pelos campos presentes:

```php
$temStorefront   = !empty($dados['storefrontAddress']['addressLines']) || !empty($dados['storefrontAddress']['locality']);
$temServiceArea  = !empty($dados['serviceArea']);
```

- **Nenhum dos dois presente** → nota 0, diagnóstico `erro`: "Nenhum endereço público nem área de atendimento
  configurados nesta ficha." + ação Google Business Profile Manager.
- **Loja física** (`$temStorefront`, tem prioridade se ambos vierem preenchidos): 5 subcampos, 20 pts cada —
  `addressLines` (não vazio), `locality`, `administrativeArea`, `postalCode`, `regionCode`. Diagnóstico lista os
  campos faltantes nominalmente: "Endereço incompleto — faltam: {lista}." (`aviso`, mesma ação); nota 100 →
  `ok`: "Endereço completo."
- **Área de atendimento** (só `$temServiceArea`): `serviceArea.businessType` presente → +50; `serviceArea.places.placeInfos` não vazio → +50. Diagnóstico se faltar algo: "Área de atendimento incompleta — faltam:
  {lista}." (`aviso`); nota 100 → `ok`: "Área de atendimento completa."

### Presença Externa

- `websiteUri` não vazio → nota 100, diagnóstico `ok`: "Site vinculado à ficha."
- vazio/ausente → nota 0, diagnóstico `erro`: "Nenhum site cadastrado na ficha." + ação:
  `acao_label: 'Adicionar site no Google Business Profile Manager'`, `acao_url: 'https://business.google.com/'`.
- **Sempre**, independente da nota, um diagnóstico extra fixo tipo `info` (não soma nem subtrai pontos):
  "Lembrete: adicione o Schema.org (LocalBusiness) no site vinculado para reforçar a ficha para o Google." +
  ação: `acao_label: 'Ver exemplo na Apostila'`, `acao_url: route('admin.gmb-apostila.index')`.

### Saúde/Risco

- `regularHours.periods` não vazio → nota 100, diagnóstico `ok`: "Horário de funcionamento cadastrado."
- vazio/ausente → nota 0, diagnóstico `erro`: "Horário de funcionamento não cadastrado na ficha." + ação:
  `acao_label: 'Abrir Google Business Profile Manager'`, `acao_url: 'https://business.google.com/'`.
- **Sempre**, independente da nota, um diagnóstico extra fixo tipo `info` (não soma nem subtrai pontos):
  "Verifique periodicamente se há edições sugeridas por terceiros pendentes de revisão no Google Business
  Profile Manager." + ação: `acao_label: 'Abrir Google Business Profile Manager'`, `acao_url: 'https://business.google.com/'`.
  (Fica manual até o webhook da Notifications API existir — backlog item 7, já registrado em `_docs/TAREFAS.md`.)

## Mudança na view (`resources/views/gmb-qualidade/show.blade.php`)

Linha 53 hoje: `@if($categoria['status'] === 'pendente')`. Passa a:

```blade
@if($categoria['status'] !== 'calculado')
    <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">
        {{ $categoria['status'] === 'erro' ? 'Indisponível' : 'Em breve' }}
    </span>
@else
    ... (inalterado)
@endif
```

Nenhuma outra mudança na view — o loop de diagnósticos já funciona igual para `erro` (usa `tipo: 'erro'` que já
tem estilo `border-red-400` definido).

## Testes

`Http::fake()` simulando `mybusinessbusinessinformation.googleapis.com/*` (wildcard). Casos:

1. **Perfil "bom"** — payload completo e ideal → Identidade 100, Localização 100 (loja física completa),
   Presença Externa 100, Saúde/Risco 100.
2. **Perfil "incompleto"** — sem categoria secundária, nome com `" - "`, descrição vazia, sem endereço nem área
   de atendimento, sem site, sem horário → notas baixas com os diagnósticos certos (mensagens e `acao_url`
   verificados).
3. **Perfil com área de atendimento** (sem `storefrontAddress`) — confirma que o ramo de área de atendimento é
   escolhido e pontua certo.
4. **Sem `google_location_id`** → as 4 categorias voltam `status: 'erro'` com a mensagem do passo 1.
5. **Sem token Google** → `status: 'erro'` com a mensagem do passo 3.
6. **403 SERVICE_DISABLED** → mensagem com link de ativação da API.
7. **404** → mensagem com o ID da localização.
8. **429 / "Quota exceeded"** → mensagem de quota.
9. **View:** `status: 'erro'` renderiza badge "Indisponível" (mantém `status: 'pendente'` renderizando "Em
   breve" — teste de regressão pras categorias Conteúdo/Reputação que continuam pendentes).

## Fora de escopo deste sub-projeto

- Categorias **Conteúdo** e **Reputação** — sub-projetos futuros (endpoints separados: mídia e reviews).
- Geração de descrição por IA (menção no diagnóstico, mas sem botão de ação nesta v1).
- Escrever direto na ficha do Google (editar categoria/nome/endereço/horário pelo painel) — depende do backlog
  item 2, ainda não construído. Todas as ações desta categoria linkam para o Google Business Profile Manager
  (`https://business.google.com/`) para edição manual.
- Verificação automática de Schema.org no site do cliente (exigiria scraping) — fica como lembrete de texto.
- Webhook de Notifications API para sugestões de terceiros — backlog item 7.
