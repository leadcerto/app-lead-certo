# Kanban — Colunas Dinâmicas — Design

**Goal:** Tirar o Kanban de Vendas do ENUM fixo do MySQL (hoje só serve pro tenant piloto, Frete.Rio) e deixar cada franqueado criar, nomear, reordenar e excluir suas próprias colunas. É o bloqueador que impede onboarding de qualquer franqueado novo além da Frete.Rio.

**Contexto:** Hoje `tickets_atendimento.coluna_kanban` é um MySQL `ENUM` com 8 valores fixos (`lead_novo`, `em_atendimento`, `aguardando_orcamento`, `aguardando_lead`, `pagamento`, `servico_agendado`, `encerrado`, `outros`). A mesma lista está hardcoded em pelo menos 18 arquivos do backend (comparações diretas tipo `=== 'encerrado'`) e em dois arrays JS (`kanban/index.blade.php`, `kanban/config.blade.php`), este último com textos de exemplo (`objetivoEx`, `iaPlaceholder`) escritos especificamente pro negócio de frete/mudança.

## Decisões fechadas nesta sessão

1. **Escopo:** desenhar pensando em múltiplos Kanbans por tenant (T-MULTI-KANBAN-ARQUITETURA), mas construir agora só o necessário pro Kanban de Vendas — schema pronto pra `kanban_id`, sem seletor de UI (só existe 1 Kanban ativo hoje).
2. **Reconhecimento de comportamento especial:** cada coluna tem um **papel** fixo, escolhido de uma lista do sistema. O nome/emoji da coluna é livre; o papel decide qual automação especial roda. Várias colunas podem ter o mesmo papel.
3. **Papéis são por tipo de Kanban:** o catálogo de papéis vive no código, não no banco, e é específico por `tipo` de Kanban (`vendas`, `pos_venda`, etc). Nesta tarefa só o catálogo de **Vendas** é populado — os outros tipos ganham seus papéis quando forem desenhados individualmente (T-KANBAN-POS-VENDA e afins).
4. **Abordagem de dados:** `tickets_atendimento.coluna_kanban` continua sendo **string** (a `chave` da coluna), não vira FK. Evita migrar dado histórico de tickets/mensagens. Validação deixa de ser `Rule::in()` fixo e passa a checar contra as colunas daquele tenant.
5. **Exclusão de coluna com ticket:** bloqueada. Sistema recusa e informa quantos tickets ainda estão lá.
6. **Backfill:** não-destrutivo. Tenants existentes (Frete.Rio) recebem as mesmas 8 colunas de hoje, com os mesmos nomes — nenhum ticket muda de lugar, nenhum dado se perde.

## Papéis do Kanban de Vendas

| Papel (`PapelColunaKanban`) | Comportamento especial do sistema | Cardinalidade |
|---|---|---|
| `entrada` | Coluna padrão pra ticket novo (webhook, formulário, importação, ligação). Quando o lead responde pela 1ª vez a uma mensagem do bot nesta coluna, o ticket avança automaticamente pra próxima coluna (por `ordem`). | Exatamente 1 por Kanban |
| `em_andamento` | Papel neutro — nenhuma automação do sistema além do que o franqueado configurar (IA, sequência, botão rápido). | 0 ou mais |
| `encerramento` | Ao entrar: seta `status=encerrado`, salva `coluna_antes_encerrar`. Participa da reabertura inteligente via IA (mensagem útil reabre pra `coluna_antes_encerrar`; despedida/agradecimento mantém encerrado). | 1 ou mais |
| `transferencia_humana` | Ao entrar: seta `agente_responsavel=humano`, tira o ticket da automação. | 0 ou mais |

O botão "Orçamento Enviado ✓" (hoje fixo pra `aguardando_orcamento`) deixa de ser ligado a um papel — vira uma configuração genérica **"botão rápido"** por coluna: label customizável + coluna de destino, disponível em qualquer coluna de papel `em_andamento`.

## Modelo de dados

### `kanbans` (nova tabela)
```
id
tenant_id       FK → tenants, cascadeOnDelete
tipo            string(30)   -- 'vendas' nesta primeira versão
nome            string(60)
ordem           unsignedInteger, default 0
timestamps
```
Sem UI de seleção nesta versão — o painel sempre usa o Kanban `tipo=vendas` do tenant logado.

### `kanban_colunas` (nova tabela — substitui o ENUM)
```
id
kanban_id       FK → kanbans, cascadeOnDelete
chave           string(50)   -- slug gerado do label, único DENTRO do kanban
label           string(60)
emoji           string(10), nullable
papel           string(30)   -- valor do enum PapelColunaKanban, validado em código
ordem           unsignedInteger
timestamps

unique(kanban_id, chave)
```

### `kanban_coluna_configs` (existente)
Ganha `kanban_coluna_id` (FK, nullable durante a transição — preenchido no backfill, `not-null` numa migration de limpeza depois que todo tenant estiver migrado). Campos atuais (`ia_contexto`, `ia_ativo`, `objetivo`, `foco_analise_imagem`, `sdr_delay_segundos`, `button_settings` via `sequencia_mensagens`) não mudam de significado, só ganham a nova FK como amarração preferencial (a coluna `coluna_kanban` string permanece por compatibilidade/leitura direta).

### `App\Enums\PapelColunaKanban` (código, não banco)
```php
enum PapelColunaKanban: string
{
    case Entrada = 'entrada';
    case EmAndamento = 'em_andamento';
    case Encerramento = 'encerramento';
    case TransferenciaHumana = 'transferencia_humana';

    public function label(): string { ... }         // "Entrada", "Em Andamento"...
    public function descricao(): string { ... }      // texto explicativo pro franqueado no dropdown
    public function objetivoExemplo(): string { ... }// texto-sugestão genérico (substitui objetivoEx hardcoded)
    public function promptExemplo(): string { ... }  // texto-sugestão genérico (substitui iaPlaceholder hardcoded)
}
```
Cada `tipo` de Kanban terá seu próprio enum (`PapelColunaKanbanPosVenda` etc) quando for desenhado — não existe ainda.

## Helper central (elimina comparação de string solta)

**`App\Models\KanbanColuna`** (Eloquent model da nova tabela) ganha métodos estáticos, todos cacheados por tenant (`Cache::remember("kanban_colunas:{$tenantId}", ...)`, invalidado em qualquer create/update/delete de coluna):

```php
KanbanColuna::chavesDoTenant(int $tenantId): array               // pra Rule::in() dinâmico
KanbanColuna::papelDe(int $tenantId, string $chave): ?PapelColunaKanban
KanbanColuna::chaveDeEntrada(int $tenantId): string               // sempre existe (regra: exatamente 1)
KanbanColuna::chavesDeEncerramento(int $tenantId): array          // pode ser mais de 1
KanbanColuna::proximaChave(int $tenantId, string $chaveAtual): ?string  // por 'ordem', pro auto-avanço da Entrada
```

### Migração dos 18 arquivos que comparam string hoje

Cada arquivo é revisado individualmente (não em lote) e testado antes/depois da troca. Padrão de troca:
- `if ($coluna === 'encerrado')` → `if (KanbanColuna::papelDe($tenantId, $coluna) === PapelColunaKanban::Encerramento)`
- `'coluna_kanban' => 'lead_novo'` (criação de ticket) → `'coluna_kanban' => KanbanColuna::chaveDeEntrada($tenantId)`
- `Rule::in(['lead_novo', ...])` → `Rule::in(KanbanColuna::chavesDoTenant($tenantId))`

Arquivos identificados (a validar um a um durante o plano de implementação): `KanbanController`, `KanbanColunaConfigController`, `GestorKanbanService`, `TicketAtendimento` (model), `UazapiWebhookController`, `FormularioService`, `SdrResponderService`, `FollowupConversas`, `SequenciaMensagemJob`, `SequenciaController`, `SecretariaEletronicaController`, `SincronizarContatosWhatsApp`, `SincronizarAgendaWhatsAppJob`, `ImportarParticipantesGrupos`, `AgendaImediataService`, `TicketController` (Internal), `ContatosController`, `TenantSetupService`.

## Migração de dados (backfill)

Uma migration, rodada uma única vez, não-destrutiva:

1. Para cada `tenant` existente: cria 1 `kanbans` (`tipo=vendas`, `nome='Vendas'`, `ordem=0`).
2. Cria as 8 `kanban_colunas` de hoje nesse Kanban, preservando `chave` (a string atual do ENUM), `label` e `emoji` de `kanban/index.blade.php` / `config.blade.php`, com papel:
   - `lead_novo` → `entrada`
   - `em_atendimento`, `aguardando_orcamento`, `aguardando_lead`, `pagamento`, `servico_agendado` → `em_andamento`
   - `encerrado` → `encerramento`
   - `outros` → `transferencia_humana`
3. Liga cada `kanban_coluna_configs` existente ao `kanban_coluna_id` correspondente (por `tenant_id` + `coluna_kanban`).
4. `tickets_atendimento.coluna_kanban` **não muda** — continua com os mesmos valores string, agora validados contra a tabela em vez do ENUM.
5. Migration separada: `ALTER TABLE tickets_atendimento MODIFY coluna_kanban VARCHAR(50)` (sai do ENUM MySQL pra string livre).

`TenantSetupService` (cria estrutura de tenant novo) passa a semear um Kanban de Vendas com as mesmas 8 colunas-padrão como ponto de partida — o franqueado edita/renomeia/exclui a partir daí, não começa do zero vazio.

## UI self-service

Nova seção dentro da tela já existente `kanban/config.blade.php` (não é tela nova):

- Lista de colunas do Kanban de Vendas, drag-and-drop pra reordenar (`ordem`)
- Botão **"+ Nova Coluna"**: nome, emoji, dropdown de papel (com a `descricao()` de cada papel visível como ajuda inline)
- Editar coluna existente: nome, emoji, papel (trocar o papel de uma coluna com tickets ativos pede confirmação — muda o comportamento do sistema pra tickets que já estão lá)
- Excluir coluna: bloqueado se `TicketAtendimento::where('coluna_kanban', $chave)->exists()` — mensagem mostra a contagem
- Campos `objetivoEx` / `iaPlaceholder` do formulário de contexto de IA passam a vir de `PapelColunaKanban::objetivoExemplo()` / `promptExemplo()` da coluna selecionada, em vez do texto hardcoded por chave

## Fora de escopo (explicitamente adiado)

- Seletor de múltiplos Kanbans na UI (T-MULTI-KANBAN-ARQUITETURA — schema pronto, tela não)
- Catálogo de papéis pra Pós-Venda, Relacionamento, Pesquisa, Prospecção Fria (cada um ganha seu próprio design quando for a vez)
- Migrar `tickets_atendimento.coluna_kanban` pra FK (mantido como string por decisão desta sessão)
- Automação de pagamento via webhook (mencionada no backlog como V2, independente desta tarefa)

## Testes

- Unit: `PapelColunaKanban` enum (labels/descrições/exemplos corretos por caso)
- Unit: `KanbanColuna` helpers (papelDe, chaveDeEntrada, chavesDeEncerramento, proximaChave, cache invalidation em create/update/delete)
- Feature: criar/editar/reordenar/excluir coluna via UI (incluindo bloqueio de exclusão com ticket ativo)
- Feature: backfill migration — rodar contra um snapshot de dados equivalente à Frete.Rio e confirmar 0 ticket órfão, 0 config perdida
- Feature (regressão, por arquivo migrado): confirmar que auto-avanço de Entrada, encerramento com reabertura via IA, e transferência humana continuam funcionando depois de cada arquivo trocar de comparação-string pra papel
