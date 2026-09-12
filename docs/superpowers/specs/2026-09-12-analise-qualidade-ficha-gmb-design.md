# Análise de Qualidade da Ficha (GMB) — Design

**Status:** rascunho para revisão do Leonardo.
**Contexto:** item 1 do backlog GMB (`_docs/TAREFAS.md`), construído a partir dos critérios reunidos
em `_docs/GMB-CRITERIOS-QUALIDADE-FICHA.md` e na página `/admin/gmb/apostila`.
**Referência visual:** PageSpeed Insights (gauge 0-100 geral + gauges por categoria + lista de
diagnósticos expansível + auditorias aprovadas).

## Objetivo

Dar ao usuário, por perfil GMB, uma nota de 0 a 100 (geral + por categoria) que reflete o quão perto a
ficha está do "ideal" segundo os critérios reais de ranqueamento do Google, com diagnósticos acionáveis —
"o que fazer pra chegar em 100" — e, quando possível, um botão que já resolve o problema.

## Categorias (blocos pequenos, um de cada vez)

1. **Identidade** — categoria primária preenchida e específica; secundárias (3-5, sem excesso); nome sem
   *keyword stuffing* (heurística simples); descrição preenchida (mínimo de caracteres).
2. **Localização** — detecta automaticamente se o perfil é "loja física" (tem `storefrontAddress`) ou
   "área de atendimento" (tem `serviceArea`) e cobra o campo certo pra cada caso — nunca cobra endereço
   público de quem intencionalmente o oculta.
3. **Conteúdo** — quantidade de fotos cadastradas; catálogo de produtos/serviços com ao menos 1 item;
   atributos com alguma marcação (não todos vazios).
4. **Atividade** — último post do perfil há ≤ 7 dias (já disponível em `gmb_posts`, sem chamada nova à
   API); Q&A com ao menos 1 pergunta respondida pelo dono.
5. **Reputação** — volume de avaliações, nota média, se teve avaliação nova nos últimos 30 dias, taxa de
   resposta do dono às avaliações.
6. **Presença Externa** — site vinculado presente (`websiteUri`); Schema.org fica como item de checklist
   manual (não verificável via API sem raspar o site do cliente).
7. **Saúde/Risco** — horário de funcionamento preenchido e sem aviso de feriado pendente; item de
   "verificar edição sugerida por terceiro" fica como lembrete manual até o webhook de Notifications API
   estar implementado (backlog item 7).

Cada categoria tem peso 1/7 na nota geral (ajustável depois se algum tiver menos critérios que os outros).

## Fontes de dado por categoria

| Categoria | Fonte | Chamada nova? |
|---|---|---|
| Atividade (posts) | Tabela `gmb_posts` (já existe) | Não |
| Identidade | My Business Business Information API — `locations.get` (`categories`, `title`, `profile.description`) | Sim |
| Localização | Mesma chamada acima (`storefrontAddress` / `serviceArea`) | Reaproveita |
| Conteúdo | Business Information API (`profile`, atributos) + Google My Business API v4 (media count) | Sim |
| Reputação | Google My Business API v4 — `accounts/{}/locations/{}/reviews` | Sim (endpoint novo) |
| Presença Externa | `websiteUri` da mesma chamada de Identidade | Reaproveita |
| Saúde/Risco | `regularHours` + `specialHours` da mesma chamada | Reaproveita |

A maior parte das categorias reaproveita **1 única chamada** à Business Information API por perfil — só
Atividade (grátis, já local) e Reputação (endpoint de reviews) precisam de chamada separada.

## Arquitetura

- **`GmbQualidadeService`** (novo) — orquestra as chamadas às APIs do Google + tabela local, calcula
  score por categoria e a lista de diagnósticos (problema + recomendação + link de ação, quando existir).
- **Cache do resultado** — tabela nova `gmb_qualidade_scores` (1 linha por perfil, com JSON dos
  diagnósticos e timestamp), recalculada sob demanda via botão "Reavaliar agora" — não em toda carga de
  página, pra não estourar cota/latência.
- **Rotas novas:**
  - `GET /admin/gmb/perfis-gmb/{perfil}/qualidade` — tela de detalhe (estilo PageSpeed).
  - `POST /admin/gmb/perfis-gmb/{perfil}/qualidade/reavaliar` — força recálculo.
- **Coluna nova "Qualidade"** na listagem de Perfis GMB (`admin.perfis-gmb.index`) — nota + botão "Ver
  diagnóstico" linkando pra tela acima.

## Diagnósticos acionáveis

Cada item de diagnóstico tem: texto do problema (linguagem humana, não técnica), a recomendação (do
conteúdo da Apostila) e, quando aplicável, um **botão de ação direta**:

- "Sem post nos últimos 7 dias" → botão pra `/admin/gmb/posts/novo` (já filtrando o perfil).
- "Menos de N fotos" → botão pra Galeria de Imagens.
- "Descrição vazia ou curta" → botão "Gerar com IA" (reaproveita padrão do `GmbPostIaService`).
- Itens que exigem edição **direto no Google** (categoria, horário) — v1 só explica o passo a passo e
  linka pro Google Business Profile Manager; escrever direto via API fica pro backlog item 2 (Atualizar
  informações da ficha pelo painel), ainda não construído.

## Construção incremental (ordem sugerida)

1. Fundação: migration da tabela de score, `GmbQualidadeService` vazio, coluna "Qualidade" na listagem,
   tela de detalhe com gauges renderizando (sem dado real ainda).
2. Categoria **Atividade** — primeira de verdade, zero chamada nova à API (só lê `gmb_posts`).
3. Categoria **Identidade**.
4. Categoria **Localização**.
5. Categoria **Conteúdo**.
6. Categoria **Reputação** (integra o endpoint de reviews da v4 pela primeira vez no sistema).
7. Categoria **Presença Externa** + **Saúde/Risco**.

Cada passo é uma tarefa fechada e testável isoladamente antes de passar pro próximo.

## Fora de escopo da v1

- Responder avaliação via API (decisão já registrada — fora de escopo, sensível).
- Escrever direto na ficha do Google (editar categoria/horário pelo painel) — depende do backlog item 2.
- Verificação automática de Schema.org no site do cliente (exigiria scraping).
- Comparação com concorrente (fica pra v2, conforme já registrado no backlog).
