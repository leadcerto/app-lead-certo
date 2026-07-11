# Gestor do Kanban — Relatório Semanal — Design

**Goal:** Um agente IA que roda uma vez por semana (sábado à meia-noite), lê as conversas da semana de cada coluna do Kanban de cada tenant, e produz um relatório com números + análise qualitativa + sugestão de ajuste de prompt por coluna, além de uma síntese geral da semana.

**Contexto:** Já existia uma spec anterior (T-GESTOR-KANBAN, reunião de 2026-07-08) prevendo um relatório *diário*. Nesta sessão (2026-07-11) o usuário revisou a cadência para **semanal** (sábado 00:00, olhando a semana inteira de uma vez) — não precisa de monitoramento contínuo.

## Decisões fechadas nesta sessão

1. **Cadência:** semanal, todo sábado à meia-noite, cobrindo os 7 dias anteriores.
2. **Entrega:** tela nova no painel (Kanban → Relatórios), com histórico de semanas anteriores. Não é enviado por WhatsApp/e-mail nesta primeira versão.
3. **Sugestão de prompt:** o relatório traz um texto pronto pra copiar e colar no `ia_contexto` da coluna — não aplica sozinho (o dono revisa e cola manualmente).
4. **Prompt do próprio Gestor:** é **global** (um só, não por tenant) e editável **só pelo perfil `admin`** (Lead Certo), nunca pelo `dono` do franqueado. Novo franqueado já nasce com o Gestor funcionando, sem nenhum passo de configuração.
5. **Escopo de colunas:** todas, incluindo `encerrado` (pra entender motivo de perda via `tag_desfecho`).
6. **Conteúdo:** números (quantos entraram/avançaram/travaram por coluna) **+** análise qualitativa das conversas.

## Arquitetura

```
Schedule (routes/console.php)
  └─ Sábado 00:00 → `kanban:gestor-semanal` (Artisan Command)
       └─ Para cada Tenant ativo:
            └─ GestorKanbanService::gerarRelatorioSemanal(tenant, semanaInicio, semanaFim)
                 ├─ Para cada coluna (lead_novo...encerrado):
                 │    ├─ coletarNumeros(coluna, semana)      → entradas/avanços/travados/tag_desfecho
                 │    ├─ amostrarConversas(coluna, semana)   → até N tickets (travados há mais tempo + fechados recentemente)
                 │    └─ OpenRouterService::chat(promptColuna + números + conversas) → análise + sugestão
                 ├─ OpenRouterService::chat(promptSintese + todas as análises de coluna) → síntese geral
                 └─ GestorKanbanRelatorio::create([...])  (persiste tudo)
```

## Componentes

### Dados

**`gestor_kanban_config`** (tabela singleton, SEM tenant_id — é global)
- `id`
- `prompt_coluna` (text) — prompt usado na análise de cada coluna
- `prompt_sintese` (text) — prompt usado na síntese final
- `updated_by` (FK users, nullable) — quem editou por último
- timestamps

Semente inicial (seed/migration): prompt de partida com as 5 funções já definidas na spec de 2026-07-08 (foco no fluxo interno, auditoria de gargalo, sugestão de ajuste, etc.)

**`gestor_kanban_relatorios`** (tenant-scoped via `TenantScope`)
- `id`, `tenant_id`
- `semana_inicio` (date), `semana_fim` (date)
- `dados` (json) — array por coluna: `{coluna, entradas, avancos, travados, tag_desfecho_breakdown, analise, sugestao_prompt}`
- `sintese_geral` (text)
- timestamps
- índice único `(tenant_id, semana_inicio)` — evita relatório duplicado se o comando rodar de novo pra mesma semana

### Serviço

**`app/Services/GestorKanbanService.php`**
- `gerarRelatorioSemanal(Tenant $tenant, Carbon $inicio, Carbon $fim): GestorKanbanRelatorio`
- Métodos privados: `coletarNumerosColuna()`, `amostrarConversasColuna()` (critério: tickets com `updated_at`/última mensagem mais antiga primeiro — os mais "travados" — e os fechados nos últimos 7 dias; limite configurável, ex. 15 por coluna, pra controlar custo de tokens), `analisarColuna()`, `sintetizarSemana()`

### Comando

**`app/Console/Commands/GestorKanbanSemanal.php`** — `kanban:gestor-semanal {--tenant=} {--dry-run}`
- Sem `--tenant`, roda pra todos os tenants com `status = 'ativo'` (enum já existente em `tenants.status`)
- `--dry-run` mostra o que faria sem chamar IA nem persistir
- Agendado em `routes/console.php`: `Schedule::command('kanban:gestor-semanal')->weeklyOn(6, '00:00')`

### Controllers e rotas

- `Painel\GestorKanbanRelatorioController` — `index()` lista relatórios do tenant, `show($id)` detalhe. Middleware `role:admin,dono` (mesmo padrão de `kanban.config`), tenant-scoped.
  - `GET /api/painel/kanban/relatorios`
  - `GET /api/painel/kanban/relatorios/{id}`
  - `GET /kanban/relatorios` (view)
- `Admin\GestorKanbanConfigController` — `show()`/`update()` do prompt global. Middleware `role:admin` (só admin, sem `dono`).
  - `GET /api/admin/gestor-kanban/prompt`
  - `PUT /api/admin/gestor-kanban/prompt`
  - `GET /admin/gestor-kanban` (view)

### Views

- `resources/views/kanban/relatorios.blade.php` — lista de relatórios semanais (mais recente primeiro), cada um expansível mostrando por coluna: números + análise + caixa de texto com a sugestão (botão "copiar"), e a síntese geral no topo. Segue o mesmo padrão Alpine.js já usado em `kanban/config.blade.php`.
- `resources/views/admin/gestor-kanban.blade.php` — textarea grande pra `prompt_coluna` e `prompt_sintese`, botão Salvar. Só aparece no menu pra `perfil=admin`.

## Controle de custo/tokens

Não manda a semana inteira de conversas crua pra IA. Por coluna: amostra de até ~15 tickets (priorizando os mais travados + os fechados na semana), e usa o `resumo_ia` já existente (de `T-HISTORICO-RESUMO`) quando disponível em vez do histórico completo, pra tickets fechados.

## Erros e casos de borda

- Coluna sem nenhum ticket na semana → entra no relatório com números zerados e análise "sem atividade esta semana", sem chamar IA pra ela (economiza uma chamada).
- Falha na chamada de IA pra uma coluna → loga warning, essa coluna fica com `analise: null` no JSON, resto do relatório segue normalmente (não aborta o tenant inteiro).
- Tenant sem nenhuma atividade na semana inteira → não gera relatório (nada pra reportar).
- Comando rodando de novo pra uma semana já processada → índice único evita duplicata; usa `updateOrCreate`.

## Testes

- `GestorKanbanServiceTest` — números calculados corretamente por coluna, amostra respeita o limite, tickets sem mensagens não quebram.
- `GestorKanbanSemanalCommandTest` — roda pra múltiplos tenants, `--dry-run` não persiste, não duplica relatório da mesma semana.
- `GestorKanbanConfigControllerTest` — só `admin` acessa (403 pra `dono`), persiste prompt.
- `GestorKanbanRelatorioControllerTest` — `dono` só vê relatórios do próprio tenant.

## Fora de escopo (nesta primeira versão)

- Envio por WhatsApp/e-mail do relatório (só fica na tela)
- Botão "aplicar sugestão direto" (só copiar/colar manual)
- Relatório sob demanda (só o agendado semanal — sem botão "gerar agora")
