# Manual de Implantação — Sistema de Variáveis Dinâmicas com Aprendizado Contínuo

Consolida as decisões tomadas em duas sessões de brainstorm (Claude Sonnet 4.6 e Gemini 3.1 Pro,
fora deste repositório) mais o levantamento do código atual do Lead Certo. Este documento é o
"manual" pedido pelo usuário: regras de negócio + o que já existe no sistema + plano de fases.

## 1. Objetivo

Reduzir bloqueio de número no WhatsApp e aumentar personalização, permitindo que cada mensagem de
uma sequência de atendimento tenha **até 7 versões** (1 escrita pelo humano + até 6 geradas por IA),
sorteadas no envio, com **timing variável** (jitter) entre mensagens, **horário de funcionamento**
configurável por sequência, e — numa segunda fase — **aprendizado contínuo**: a IA aprende quais
variações convertem melhor (avançam mais etapas no Kanban) e substitui as que não convertem.

## 2. O que já existe no sistema (não reinventar)

Levantamento feito diretamente no código antes de propor qualquer mudança:

| Peça | Onde | O que já faz |
|---|---|---|
| Banco de variáveis por variável | `App\Models\SpintaxVariavel` (`spintax_variaveis`) | `nome`, `label`, `opcoes` (lista separada por linha), defaults de fábrica (`abertura_casual`, `abertura_empatica`, `despedida_casual`, `motivo_contato`, `gatilho_urgencia`, `reforco_valor`, `cta_fechamento`, `termo_servico`) + customização por tenant. `sortear()` escolhe uma opção aleatória. `getTodasParaTenant()` mescla default + custom. |
| Resolução de variáveis no envio | `App\Jobs\SequenciaMensagemJob::handle()` | Resolve `{nome}`, `{empresa}`, `{endereco_saida}`, `{endereco_destino}`, `{data_hoje}`, `{dia_semana}`, `{saudacao_tempo}`, `{referencia_dia}`, `{tempo_passado}` + sorteia uma opção de cada `SpintaxVariavel` do tenant — **tudo no momento do envio**, não na criação da mensagem. Isso já resolve o problema de "hora do dia" ficar igual em todas as 7 mensagens: cada mensagem resolve suas variáveis quando é realmente disparada. |
| Sugestão de variáveis via IA | `App\Http\Controllers\Painel\SequenciaController::sugerirVariaveis()` | Já chama `OpenRouterService->chat()` com prompt em português, pede JSON de volta, insere variáveis nas mensagens existentes. É a "Aplicar Variáveis com IA" que o usuário já conhece (e achou limitada). Prova que o padrão de chamada IA→JSON já funciona neste projeto — os dois prompts novos do §6 seguem o mesmo padrão. |
| Estrutura de sequência | `App\Models\Sequencia` / `App\Models\SequenciaMensagem` | `Sequencia` tem `coluna_kanban`, `ativo`. `SequenciaMensagem` tem `ordem`, `conteudo` (**um texto só, hoje**), `delay_segundos` (**valor fixo, sem jitter**), `obrigatorio`, `ativo`, `button_settings`. |

**O que não existe ainda (confirmado por busca no código):**
- Múltiplas versões por mensagem (hoje é 1 `conteudo` por `SequenciaMensagem`).
- Jitter de tempo (`delay_segundos` é um inteiro fixo).
- Horário de funcionamento por sequência/Kanban (nenhuma migration tem esse campo).
- Log de envio por variação usada e tracking de conversão.
- Qualquer peso/pontuação/aprendizado sobre as opções do `SpintaxVariavel`.

**Lição do branch `feature/kanban-colunas-dinamicas` (concluído nesta mesma janela de trabalho):**
qualquer comparação literal de coluna (`coluna_kanban === 'encerrado'`) quebra quando o franqueado
renomeia a coluna. O tracking de conversão deste sistema **deve** usar `PapelColunaKanban` (papel da
coluna), nunca a chave string, para não herdar o mesmo bug.

## 3. Estrutura de uma variação de mensagem

Cada mensagem da sequência passa a ter um banco de versões (extensão do modelo, não substituição):

| Campo | Descrição |
|---|---|
| `id` | Identificador único |
| `sequencia_mensagem_id` | A mensagem "pai" na sequência |
| `conteudo` | Texto da versão |
| `origem` | `humano` ou `ia` |
| `protegida` | `true` apenas para a versão de origem humano |
| `ativa` | `true`/`false` |
| `peso` | 1–10, controla frequência no sorteio ponderado (Fase 2) |
| `total_envios`, `total_conversoes` | Contadores (Fase 2) |
| `criada_em`, `substituida_em` | Timestamps |

## 4. Regras de proteção — inegociáveis

- Versão `protegida = true` (a do humano) nunca é editada, desativada ou excluída por nenhum agente de IA.
- A versão do humano sempre participa do sorteio, mesmo com baixa conversão.
- IA nunca altera conteúdo de versão existente — só cria versões novas e desativa as suas próprias de baixo desempenho.
- Humano pode editar/desativar/excluir qualquer versão, inclusive as da IA.
- Nenhuma rota de API permite `PATCH`/`DELETE` em versão `protegida = true`, mesmo chamada diretamente — validado no backend, não só escondido na UI.

## 5. Biblioteca de variáveis — categorias

Baseado no que foi decidido nas duas sessões, restrito ao que faz sentido em **primeiro contato via WhatsApp** (o usuário descartou explicitamente variáveis comportamentais e temporais de "dias desde o último contato", por não se aplicarem a primeiro contato):

- **Fixas por conta**: `{nome_vendedor}`, `{nome_empresa}`, `{site_empresa}`, `{instagram_empresa}`.
- **Do contato**: `{nome}` (já existe, com fallback quando não há nome real).
- **Banco de variações (sorteio)**: `{saudacao}`, `{despedida}`, `{cta}`, `{gancho}`, `{prova_social}`, `{urgencia}` — mais as 8 já existentes em `SpintaxVariavel::$defaults`.
- Cada usuário pode editar/incluir/excluir suas próprias opções por variável; o sistema sai de fábrica com uma lista padrão (já é o comportamento do `SpintaxVariavel`).

## 6. Prompts internos da IA

### Prompt 1 — Geração inicial das 6 versões (dispara ao humano salvar a 1ª versão)

Regras: preservar todas as `{variaveis}` exatamente como estão; manter o mesmo objetivo comunicacional; variar estrutura de frase, abertura emocional, formalidade (±1 grau), tamanho (±20%); não inventar dado/promessa/contexto novo; não usar mais emojis que o original; saída em JSON com `variacoes: [{ordem, conteudo}]` × 6.

### Prompt 2 — Geração de 1 versão substituta (ciclo de aprendizado, Fase 2)

Recebe: a versão sinalizada (baixo desempenho), a versão original do humano (referência), as versões de melhor desempenho da mesma variável/posição. Gera 1 versão nova que aprende com os padrões das melhores, sem copiar nenhuma existente. Saída em JSON: `{nova_variacao: {conteudo, justificativa_interna}}` — `justificativa_interna` nunca é exibida ao usuário final, é log interno.

Regras de execução: JSON inválido → até 2 retentativas → alerta admin; contagem de itens divergente → rejeita e reenvia; variáveis divergentes do input → rejeita; nunca enviar dados de outros leads no prompt (apenas métricas agregadas/anônimas).

## 7. Timing variável (jitter)

Trocar `delay_segundos` (fixo) por `delay_min_segundos` / `delay_max_segundos` em `SequenciaMensagem`.
No dispatch do job, sortear um valor no intervalo a cada envio. Mudança isolada, não depende de
nenhuma outra parte deste sistema — pode ser entregue em paralelo a qualquer fase.

## 8. Horário de funcionamento

Novos campos em `Sequencia`: `horario_ativo` (boolean, `false` = 24h sempre ativa), `horario_inicio`,
`horario_fim` (horário local do tenant). Quando `horario_ativo = true` e o disparo cai fora da janela,
o sistema usa a **sequência de repouso**: um segundo registro `Sequencia` (mesma `coluna_kanban`),
referenciado por `sequencia_repouso_id` (FK nullable, self-referencing) na sequência principal.
Se não houver sequência de repouso configurada, a sequência principal simplesmente não dispara fora
do horário (mensagens ficam pendentes até a próxima janela).
Reaproveita 100% da estrutura `Sequencia`/`SequenciaMensagem` já existente — não cria um conceito
novo de "mensagem alternativa por flag".

## 9. Aprendizado contínuo (Fase 2)

- "Conversão" = o lead avançou de etapa no Kanban após receber a mensagem. Cada variável usada
  naquela mensagem recebe crédito de conversão, atribuído por posição na sequência (uma variação
  pode converter bem na mensagem 1 e mal na mensagem 3 — métricas separadas por posição).
- Log de envio imutável: `ticket_id`, `mensagem_id`, versões sorteadas, papel da coluna no envio,
  papel da coluna quando (e se) converteu.
- Ciclo de análise: semanal, ou ao atingir 50 envios por versão. Sinaliza no máximo 1 versão por
  variável por ciclo (nunca a do humano), gera 1 substituta, desativa a sinalizada, notifica o usuário.
- Peso de sorteio recalculado pela taxa de conversão (tabela de faixas no manual original), com piso
  mínimo de peso 3 para a versão do humano.

## 10. Plano de implementação em fases

**Fase 1 — Variação + Timing + Horário (entrega imediata, baixo risco, reaproveita infra existente)**
1. Migration + model: tabela de versões por `SequenciaMensagem` (campos do §3, sem os contadores de
   aprendizado ainda) + migração dos dados existentes (o `conteudo` atual de cada mensagem vira a
   versão `protegida=true, origem=humano`).
2. Job/serviço de geração inicial das 6 versões via IA (Prompt 1, §6), reaproveitando
   `OpenRouterService` no mesmo padrão de `sugerirVariaveis()`.
3. Sorteio no `SequenciaMensagemJob`: em vez de usar `conteudo` fixo, sorteia entre as versões ativas
   da mensagem (sorteio uniforme nesta fase — sem peso ainda).
4. `delay_min_segundos`/`delay_max_segundos` + sorteio no dispatch (independente, pode entrar em
   qualquer ordem).
5. Extensão de `SpintaxVariavel` para incluir as novas variáveis do §5 (`{saudacao}`, `{despedida}`,
   `{cta}`, `{gancho}`, `{prova_social}`, `{urgencia}`).
6. Horário de funcionamento por sequência + sequência alternativa de "repouso".
7. UI: aba por versão dentro de cada mensagem da sequência (editar/ativar/desativar), com a versão do
   humano visualmente marcada como protegida (sem botão de excluir/desativar).

**Fase 2 — Aprendizado contínuo (depende de volume real de envios da Fase 1)**
8. Log de envio imutável (ticket + mensagem + versões usadas + papel da coluna no envio).
9. Job de atribuição de conversão: ao avançar de etapa, credita as versões da mensagem anterior
   (usando `PapelColunaKanban`, não chave literal — ver §2).
10. Ciclo de análise (semanal / gatilho de 50 envios): cálculo de taxa de conversão por versão e
    posição, recálculo de peso, sinalização de pior desempenho.
11. Prompt 2 (§6): geração da versão substituta + notificação ao usuário no painel.
12. Sorteio ponderado (troca o sorteio uniforme da Fase 1 pelo ponderado por peso).

Cada item acima vira uma task com TDD (RED→GREEN) quando formos para
`superpowers:writing-plans`, no mesmo padrão usado no branch de colunas dinâmicas do Kanban — specs
por tarefa, testes reais com `RefreshDatabase`, respeitando `TenantScope`.

## 11. Decisões confirmadas com o usuário

- **Horário de funcionamento fica em `Sequencia`** (não em `Kanban`/`KanbanColuna`) — granularidade
  por sequência, sem acoplar ao trabalho de colunas dinâmicas recém-concluído.
- **Escopo desta rodada: só a Fase 1** (variações + jitter + horário de funcionamento). A Fase 2
  (aprendizado contínuo) fica para um novo brainstorm quando houver volume real de envios em
  produção — não entra no plano técnico agora.
