# Navegação fixa "Kanban" + permissão de edição restrita (Etapa 1)

**Data:** 09/10/2026
**Status:** Em revisão com o Leonardo

## 1. Contexto

O seletor de Kanban na navegação (`docs/superpowers/specs/2026-10-08-seletor-kanban-navegacao-design.md`,
implementado e em produção desde 08-09/10/2026) entregou a Fase A combinada: um bloco colapsável
por Kanban na barra lateral, com "Atendimentos"/"Configurações" dentro de cada um e uma seção
"Geral" compartilhada (Variáveis, Motivos de Encerramento, Relatórios do Gestor, Documentação,
Especificações Técnicas).

Revisando esse resultado com o Leonardo (09/10/2026), ficou claro que o desenho real que ele quer
é diferente em três pontos:

1. **O rótulo do menu não muda** — "Kanban" é fixo, sempre; o que muda é a lista de Kanbans dentro
   dele, cada um identificado pelo próprio apelido (`nome_curto`).
2. **Cada Kanban tem os 7 itens**, não só 2 — Atendimentos, Variáveis, Configurações, Motivos de
   Encerramento, Relatórios do Gestor, Documentação, Especificações Técnicas ficam dentro do
   submenu de CADA Kanban (a seção "Geral" desta spec some).
3. **Permissão de edição aperta** — hoje `dono` (o proprietário da empresa cliente) tem acesso
   total a Configurações/Variáveis/Motivos/Relatórios. Passa a ser: todo mundo com acesso a Kanban
   **vê** essas telas, mas só o time Lead Certo (`perfil='admin'`) **edita** qualquer coisa nelas.
   "Atendimentos" (o board em si — mover card, mandar mensagem, assumir ticket) continua 100%
   operacional pra todo mundo, sem mudança — é o trabalho do dia a dia da equipe do cliente.

**Decisão explícita do Leonardo (09/10/2026)**: Variáveis, Motivos de Encerramento e Relatórios do
Gestor viram dado genuinamente separado por Kanban — mas isso é trabalho grande (3 tabelas, cada
uma com uma trava de unicidade que inclui `tenant_id`, igual ao achado de ontem em
`kanban_coluna_configs`) e fica pra Etapas 2/3/4 futuras, cada uma com seu próprio desenho. **Esta
spec cobre só a Etapa 1**: navegação + permissão de visualizar/editar — o dado dessas 3 áreas
continua um só por tenant por enquanto, só a estrutura de navegação e quem pode mexer mudam.

## 2. Achados do código existente

Levantamento feito em 09/10/2026 (agente de investigação, leitura completa de cada arquivo citado):

- **Documentação** (`kanban.documentacao-botoes`, `resources/views/kanban/documentacao-botoes.blade.php`)
  é uma página 100% estática (sem `fetch`, sem model, sem controller — rota é uma closure em
  `routes/web.php`) — manual de produto sobre botões interativos do WhatsApp. Não tem nada pra
  editar, não precisa de banner de só-leitura.
- **Especificações Técnicas** (`admin.especificacoes`, `app/Http/Controllers/Admin/EspecificacoesController.php`)
  lê arquivos markdown de `docs/superpowers/specs/*.md` do disco e renderiza com CommonMark — não
  tem tabela, não é dado de tenant, não precisa de banner de só-leitura (também já não tem nenhuma
  ação de editar pra restringir).
- **Variáveis** (`SpintaxVariavelController`, model `SpintaxVariavel`, tabela `spintax_variaveis`) —
  sem `TenantScope`, filtra tudo manualmente por `tenant_id`. `UNIQUE(tenant_id, nome)`. ~6 pontos
  de leitura, incluindo `SequenciaMensagemJob.php:198`.
- **Motivos de Encerramento** (`MotivoDesfechoController`, model `MotivoDesfecho`, tabela
  `motivos_desfecho`) — tem `TenantScope`. `UNIQUE(tenant_id, chave)`. Acoplamento fraco com
  `TicketAtendimento.tag_desfecho` (string solta, não FK) — alimenta métricas do Dashboard
  (`DashboardController.php:42`) e é escrito por vários jobs com chaves fixas
  (`FormularioLeadJob`, `FollowupConversas`, `CovercutWebhookController`).
- **Relatórios do Gestor** (`GestorKanbanRelatorioController`, model `GestorKanbanRelatorio`,
  tabela `gestor_kanban_relatorios`) — tem `TenantScope`. `UNIQUE(tenant_id, semana_inicio)`.
  Gerado pelo comando semanal `GestorKanbanSemanalCommand` via `GestorKanbanService`, que já
  itera por tenant e já usa `KanbanColuna::chavesDoTenant()`/`papelDe()` **sem** passar o parâmetro
  `?int $kanbanId` que esses métodos já aceitam desde ontem — ficaria pronto pra Etapa 4 usar.
- **Rotas de visualização hoje** (`routes/web.php`): `kanban.variaveis` (L286-288),
  `kanban.motivos-desfecho` (L296-298), `kanban.relatorios` (L291-293),
  `kanban.documentacao-botoes` (L301-303), `admin.especificacoes` (L306-311) — todas com
  `role:admin,dono`. A API GET de Motivos de Encerramento (L414) já está aberta pra todos os
  perfis com acesso a Kanban (achado útil — já existe precedente desse padrão no código).
- **Rotas de Configurações do Kanban** (grupo `role:admin,dono` criado/estendido ontem,
  `routes/web.php` ~L586-615): `/kanban/colunas` (GET/POST/PUT/DELETE/reordenar),
  `/kanban/canais`, `/kanban/info`, `/kanban/coluna-config/{coluna}`,
  `/kanban/coluna-objetivos/{coluna}/...`, `/kanban` (POST, criar Kanban).
- **Lista de perfis com acesso a Kanban** (já usada em `KanbanController::index()` e replicada no
  layout): `admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing` — essa é a
  lista de referência pra "todo mundo que vê Kanban" nesta spec.
- **Barra lateral** (`resources/views/layouts/app.blade.php`): o bloco "Geral" (criado ontem,
  linhas ~195-254) deixa de existir nesta mudança — os 5 itens que estavam lá voltam a ficar
  dentro do `@foreach` de cada Kanban.

## 3. Arquitetura da barra lateral

- O toggle de topo nunca muda de texto: sempre "Kanban" (ícone + label fixos).
- Dentro dele, `@foreach` nos Kanbans do tenant (já existe desde ontem,
  `$kanbansDoTenant`/`$kanbanIdAtivo` computados no topo do layout).
- Cada linha do foreach é **clicável e funciona como aba, não como acordeão**: clicar nela navega
  pra `route('kanban', ['kanban_id' => $kanban->id])` (igual hoje) e, pelo fato de o
  `$kanbanIdAtivo` bater com aquele Kanban, a linha fica destacada e os 7 links aparecem logo
  abaixo dela, sem precisar de nenhum clique extra de abrir/fechar — isso já é derivado da rota
  atual (`request()->routeIs(...)` + `$kanbanIdAtivo === $kanban->id`), igual ao mecanismo que já
  existe hoje pra decidir qual bloco fica verde. Não precisa de estado Alpine novo pra isso — é
  puro Blade/servidor, a seta de abrir/fechar (`menuAberto`, a chevron SVG) é removida desse nível.
- Os 7 links de cada Kanban ativo: Atendimentos, Variáveis, Configurações, Motivos de
  Encerramento, Relatórios do Gestor, Documentação, Especificações Técnicas — nesta ordem, igual à
  ordem de hoje. Só Atendimentos e Configurações carregam `?kanban_id=` (são os únicos cujo
  backend já usa o parâmetro); os outros 5 apontam pras mesmas rotas de sempre, sem o parâmetro,
  porque o dado deles continua compartilhado nesta etapa.
- "+ Criar Kanban": só renderiza (`@if`) quando `$perfil === 'admin'`. Mantém o comportamento já
  implementado ontem (modal, `$dispatch('abrir-novo-kanban')`), só muda a condição de quem vê o
  botão — de `in_array($perfil, ['admin','dono'])` pra `$perfil === 'admin'`.
- A seção "Geral" inteira (bloco, `$menuKeyGeral`, toggle próprio) é removida do layout.

## 4. Permissões — rotas

Padrão aplicado a `kanban.variaveis`, `kanban.motivos-desfecho`, `kanban.relatorios`,
`kanban.config` e seus respectivos grupos de API:

- **Rota de visualização (GET da página e GET da API)**: middleware muda de `role:admin,dono` pra
  `role:admin,dono,diretor,gerente,gestor,vendedor,pos_venda,diretor_marketing` (a mesma lista já
  usada pro board).
- **Rotas de mutação (POST/PUT/DELETE/reordenar)**: middleware muda de `role:admin,dono` (ou, no
  caso das rotas de Configurações do Kanban criadas ontem, do grupo `role:admin,dono` que as
  envolve) pra `role:admin` sozinho.
- `kanban.documentacao-botoes` e `admin.especificacoes`: mesma mudança de visualização (abrir pra
  todos os perfis com acesso a Kanban) — não têm rota de mutação pra restringir.
- O controller de cada endpoint de mutação não precisa de checagem adicional em código — o
  middleware `role:admin` já barra com 403 antes de chegar no método, mesmo padrão usado em todo
  o resto do projeto.

## 5. Frontend — banner de só-leitura

Nas 3 telas com conteúdo editável (Configurações do Kanban, Variáveis, Motivos de Encerramento).
Relatórios do Gestor já é só leitura pra todo mundo hoje — não tem nenhum botão de criar/editar
na tela (os relatórios são gerados sozinhos pelo comando semanal) — então não precisa do banner
nem do bloqueio visual, só a mudança de visualização da seção 4 (abrir pra mais perfis):

- Controller (ou o `@php` no topo da view) calcula `$podeEditar = $perfil === 'admin'` e passa pra
  view.
- Bloco Blade condicional no topo do `@section('content')`, antes do conteúdo principal:
  ```blade
  @unless($podeEditar)
  <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-3 mb-4 flex items-center gap-2">
      <svg class="w-4 h-4 flex-shrink-0" ...></svg>
      Edição restrita ao time Lead Certo — você pode visualizar, mas não alterar esta tela.
  </div>
  @endunless
  ```
- O container principal da tela ganha uma classe condicional:
  `class="{{ $podeEditar ? '' : 'opacity-60 pointer-events-none select-none' }} ..."` — isso
  desativa clique em TODO o conteúdo dentro (inputs, botões, drag-and-drop, checkboxes) com uma
  única classe, sem precisar tocar em cada um dos ~30 controles individualmente.
- `pointer-events-none` é puramente visual/UX — a segurança real está nas rotas (seção 4). Mesmo
  que alguém contorne o CSS via devtools, a chamada à API volta 403.

## 6. Riscos e decisões em aberto

- **Perfil `dono` perde acesso de edição que tem hoje.** Confirmado explicitamente com o Leonardo
  (09/10/2026) — é intencional, não um efeito colateral.
- **`kanban.motivos-desfecho`/`kanban.variaveis`/`kanban.relatorios` abertas pra mais perfis**:
  antes só admin/dono viam essas páginas; agora vendedor/gerente/etc. também veem (em modo
  leitura). Nenhum dado sensível adicional é exposto além do que já aparece — são configurações
  do próprio funil do tenant, não dado de outro tenant.
- **Pontos fora desta etapa, documentados pra não serem confundidos com bug**: as 3 áreas
  (Variáveis, Motivos, Relatórios) continuam mostrando o MESMO conteúdo não importa qual Kanban
  esteja ativo — isso é esperado nesta etapa, não um erro de isolamento. A mudança pra dado
  separado por Kanban é a Etapa 2 (Variáveis), Etapa 3 (Motivos de Encerramento) e Etapa 4
  (Relatórios do Gestor), cada uma com sua própria spec depois.

## 7. Verificação

- Teste automatizado por rota mudada: perfil fora da lista de acesso a Kanban continua barrado
  (404/403, sem mudança); perfil com acesso a Kanban mas não-admin consegue `GET` (200) e recebe
  403 em qualquer `POST/PUT/DELETE`; perfil `admin` continua fazendo tudo.
- Teste de layout: `KanbanBladeCompileCheckTest` (já existe) estendido pra confirmar que a seção
  "Geral" não existe mais e que os 7 links aparecem dentro do bloco do Kanban ativo.
- Verificação manual no navegador (Playwright, mesmo padrão de ontem): logar como `dono`, confirmar
  que vê mas não consegue editar Variáveis/Motivos/Relatórios/Configurações; logar como `admin`,
  confirmar que edita normalmente; confirmar que Atendimentos continua 100% funcional pros dois.
- Suíte completa sem regressão além da baseline atual (17 falhas + 5 erros pré-existentes).
