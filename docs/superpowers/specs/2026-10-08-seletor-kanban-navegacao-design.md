# Seletor de Kanban na navegação (MVP)

**Data:** 08/10/2026
**Status:** Em revisão com o Leonardo

## 1. Contexto

Até hoje, todo tenant só tinha 1 Kanban (`tipo='vendas'`), então a barra lateral e todas as
telas de configuração do Kanban (`KanbanController`, `KanbanColunaController`,
`KanbanCanalController`, `KanbanInfoController`, `KanbanColunaConfigController`,
`KanbanColunaObjetivoController`) assumem implicitamente "o Kanban do tenant", sem nenhum
parâmetro — não existe conceito de "qual Kanban estou vendo".

Isso mudou: a fundação de `kanban_id` no ticket (ver
`docs/superpowers/plans/2026-10-08-ticket-kanban-id-fundacao.md`, já em produção) torna seguro
um tenant ter 2+ Kanbans — e a Imóveis Caixa vai precisar de um 2º Kanban dedicado ao funil de
qualificação (ver `imoveis-caixa/_docs/funil-qualificacao-leads.md`). Falta a navegação saber
disso.

**Decisão do Leonardo (08/10)**: entregar isso em 2 fases. **Fase A (este spec)**: a barra
lateral passa a ter um bloco por Kanban (com nome curto, "+" pra criar um novo), mas só
"Atendimentos" e "Configurações" entram dentro de cada bloco — as únicas telas já 100%
isoladas por Kanban hoje. "Variáveis", "Motivos de Encerramento", "Relatórios do Gestor",
"Documentação" e "Especificações Técnicas" continuam como estão, compartilhadas por todo o
tenant (viram genuinamente independentes por Kanban só numa Fase B futura, que exige adicionar
`kanban_id` a 3 tabelas que hoje só têm `tenant_id` — fora de escopo aqui).

## 2. Achados do código existente

- **8 controllers assumem "o Kanban" sem parâmetro nenhum**: `KanbanController::index()`
  (`routes/web.php:411`, o board), `KanbanColunaController` (index/papeis/store/update/
  destroy/reordenar, linhas 597-602), `KanbanCanalController` (index/update, linhas 604-605),
  `KanbanInfoController` (show/update, linhas 589-590), `KanbanColunaConfigController`
  (show/update, linhas 587-588, resolve a coluna só por `chave` string — ambíguo entre
  Kanbans, mesma classe de bug já corrigida no ticket), `KanbanColunaObjetivoController`
  (linhas 593-596). Todos buscam `Kanban::where('tenant_id',...)->where('tipo','vendas')`
  ou nem buscam Kanban nenhum (`KanbanColunaConfigController` só usa `chave`).
- **Padrão de criação de Kanban já existe**: `TenantSetupService::criarColunasKanban()`
  (`app/Services/TenantSetupService.php:52`) cria o Kanban + colunas padrão + config de IA —
  mas só roda 1x, na criação do tenant. Não existe endpoint pra criar um Kanban ADICIONAL
  depois — é exatamente o que o botão "+" precisa.
- **Simplificação técnica proposta**: em vez de reescrever as 15+ rotas pra incluir
  `/kanban/{kanban}/...` no path (o que exigiria atualizar toda chamada `fetch()` do
  Alpine.js em `resources/views/kanban/*.blade.php`), os mesmos endpoints passam a aceitar
  `?kanban_id=` como query string, com fallback: `$kanbanId = (int) $request->query(
  'kanban_id') ?: $kanbanPadrao->id` (sempre existe pelo menos 1 Kanban `tipo='vendas'` por
  tenant). Zero mudança de path, zero regressão pra quem não manda o parâmetro — só os
  controllers passam a filtrar por esse `kanban_id` em vez de assumir `tipo='vendas'` direto.
- **Blade atual da barra lateral**: `resources/views/layouts/app.blade.php:140-222` — bloco
  único "Kanban" (rótulo fixo), Alpine `x-data` com `menuAberto` controlando qual submenu está
  aberto. Vai virar N blocos (um por Kanban do tenant), com o `kanban_id` ativo controlado
  pela mesma variável Alpine, mas guardando o id em vez de só o nome do menu.

## 3. Modelo de dados

Migration nova, `add_nome_curto_to_kanbans_table`:

```php
Schema::table('kanbans', function (Blueprint $table) {
    $table->string('nome_curto', 20)->nullable()->after('nome');
});
```

Backfill: pro Kanban `tipo='vendas'` de cada tenant (hoje o único que existe), `nome_curto` =
`'Atendimentos'` (mantém o rótulo que já existe hoje, zero mudança visível pra quem só tem 1
Kanban). Novos Kanbans exigem `nome_curto` no momento da criação (campo obrigatório no modal
do "+").

Validação do campo: string, sem espaço, até 20 caracteres (`regex:/^\S+$/`).

## 4. Endpoint de criação de Kanban

Novo `KanbanController::criar(Request $request): JsonResponse` (ou controller dedicado,
decidir na implementação conforme o tamanho), rota `POST /painel/kanban` (role:admin,dono):

```php
$validated = $request->validate([
    'nome'        => 'required|string|max:100',
    'nome_curto'  => 'required|string|max:20|regex:/^\S+$/',
]);

$kanban = Kanban::create([
    'tenant_id'  => $request->user()->tenant_id,
    'tipo'       => Str::slug($validated['nome_curto'], '_'), // único identificador, não 'vendas'
    'nome'       => $validated['nome'],
    'nome_curto' => $validated['nome_curto'],
    'ordem'      => Kanban::where('tenant_id', $request->user()->tenant_id)->max('ordem') + 1,
]);
```

Criado **sem nenhuma coluna** — o usuário adiciona pela tela de Configurações já existente
(`KanbanColunaController::store`), que já funciona, só precisa aprender a filtrar pelo
`kanban_id` certo (via `?kanban_id=`) em vez de sempre pegar o `tipo='vendas'`.

**Risco a tratar**: `tipo` vira o slug do nome curto — precisa garantir unicidade por tenant
(`firstOrCreate`-safe ou checagem antes do `create`), senão 2 Kanbans com nome parecido
colidiriam no `tipo`.

## 5. Mudança nos 8 controllers existentes

Padrão idêntico em todos — trocar a resolução fixa por uma helper nova:

```php
private function resolverKanban(Request $request): Kanban
{
    $tenantId = $request->user()->tenant_id;
    $kanbanId = $request->query('kanban_id');

    if ($kanbanId) {
        return Kanban::where('tenant_id', $tenantId)->findOrFail($kanbanId);
    }

    return Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();
}
```

(Shape exato — trait compartilhado vs. repetir em cada controller — decidir na
implementação; repetir é mais simples e já é o padrão hoje nesses controllers.)

Cada método troca a query fixa `where('tipo','vendas')` por essa resolução, e passa
`$kanban->id` pros helpers de `KanbanColuna` (`chavesDoTenant`, `chaveDeEntrada`, etc. — já
aceitam o parâmetro opcional desde o plano de hoje).

`KanbanColunaConfigController::show/update(Request $request, string $coluna)` ganha a mesma
resolução, e troca a busca de `KanbanColunaConfig::where('coluna_kanban', $coluna)` (ambígua)
por primeiro resolver a `KanbanColuna` real (`where('kanban_id', $kanban->id)->where('chave',
$coluna)`) — fecha a ambiguidade que a revisão de hoje também apontou nesse controller.

## 6. Barra lateral (`resources/views/layouts/app.blade.php`)

- Busca a lista de Kanbans do tenant (`$kanbans = Kanban::where('tenant_id', $user->tenant_id)
  ->orderBy('ordem')->get()`) — passada pra view via o controller que renderiza o layout (ou
  um composer de view, decidir na implementação).
- Pra cada Kanban: um bloco igual ao de hoje, rótulo = `$kanban->nome_curto`, só com os links
  "Atendimentos" (`route('kanban', ['kanban_id' => $kanban->id])`) e "Configurações"
  (`route('kanban.config', ['kanban_id' => $kanban->id])`).
- `kanbanAtivo` (Alpine) guarda o `kanban_id` da rota atual (lido de `request()->query(
  'kanban_id')`, com fallback pro primeiro Kanban) — só o bloco desse Kanban abre/fica verde.
- Botão "+" ao lado do Kanban ativo — abre modal (reaproveita o padrão de modal já usado em
  "+ Nova Coluna" na tela de Configurações) pedindo nome + nome curto, chama o endpoint da
  seção 4, redireciona pra `route('kanban', ['kanban_id' => $novoKanban->id])` ao criar.
- Seção "Geral" (fora de qualquer bloco de Kanban): Variáveis, Motivos de Encerramento,
  Relatórios do Gestor, Documentação, Especificações Técnicas, Gestor do Kanban (admin) —
  idênticos a hoje, sem `kanban_id` nenhum.

## 7. Frontend das telas (Alpine.js)

Cada tela que hoje já faz `fetch('/painel/kanban/...')` (config.blade.php, index do board,
etc.) precisa passar a incluir `?kanban_id=X` em toda chamada — `X` lido de
`new URLSearchParams(window.location.search).get('kanban_id')` uma vez no `x-init`, guardado
numa variável Alpine, concatenado em cada `fetch()`. Mecânico, mas toca bastante arquivo
Blade — listar exatamente quais na hora de escrever o plano de implementação.

## 8. Riscos e decisões em aberto

- **Escopo real deste spec é maior do que pareceu inicialmente**: 8 controllers + migration +
  endpoint novo + barra lateral + N telas Alpine.js precisando do query param. Comparável em
  tamanho ao plano de `kanban_id` de hoje, só que tocando frontend também (terreno novo, não
  coberto pelos testes automatizados de backend que guiaram o trabalho de hoje) — validação
  manual no navegador vai ser necessária além dos testes de backend.
- **`tipo` como slug do nome curto**: precisa de checagem de unicidade por tenant antes do
  `create()` (ver seção 4).
- **Fase B (fora de escopo aqui)**: Variáveis/Motivos/Relatórios genuinamente por Kanban exige
  `kanban_id` nas 3 tabelas correspondentes — fica para depois, registrado como trabalho
  futuro conhecido.

## 9. Verificação

- TDD nos 8 controllers (teste por endpoint confirmando que `?kanban_id=` filtra certo, e que
  omitir o parâmetro continua resolvendo o Kanban `tipo='vendas'` como hoje — zero
  regressão).
- Teste do endpoint de criação de Kanban (nome/nome_curto obrigatórios, unicidade de `tipo`,
  Kanban criado sem colunas).
- Teste manual no navegador (Playwright) da barra lateral: criar um 2º Kanban pelo "+", ver o
  bloco novo aparecer, trocar entre os dois, confirmar que "Atendimentos"/"Configurações" de
  cada um mostram dados diferentes.
- Suíte completa sem regressão além da baseline de 17 falhas pré-existentes.
