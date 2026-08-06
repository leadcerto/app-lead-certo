# Regras de Atendimento IA ↔ Humano — Documento de Contexto (Fase 01)

> Escrito pelo Leonardo em 2026-08-06. Fonte de verdade das 13 regras comportamentais
> que orientam os 4 blocos de implementação decompostos a partir daqui:
> `2026-08-06-alerta-interno-agente-design.md` (Bloco 1 — infra de alerta, primeiro a
> ser construído), Bloco 2 (handoff humano ↔ agente), Bloco 3 (guardrails de
> resposta) e Bloco 4 (monitoramento proativo de Kanban) — os 3 últimos ainda sem
> spec própria. Preservado aqui na íntegra para não se perder na conversa.

## Contexto Geral

O sistema de atendimento é composto por conversas estruturadas com 3 personagens ativos:

- **LEAD** — a pessoa sendo atendida
- **Agente de IA** — agente que atende o lead seguindo regras, base de conhecimento e objetivos de avanço no Kanban
- **Atendente (Humano)** — entra na conversa sempre que necessário para esclarecer dúvidas, dar orientações ou quando o lead solicita atendimento humano

O sistema é um SaaS, portanto deve atender qualquer tipo de empresa, com Kanbans, colunas e critérios de avanço totalmente configuráveis por workspace.

Todos os áudios, imagens e documentos enviados na conversa são transcritos automaticamente e tratados como fonte de dados de primeira classe, com o mesmo peso que mensagens de texto.

## Tipos de Mensagem

**Tipo 1 — Mensagens Automáticas** — enviadas pelo sistema. Podem ser:
- Obrigatórias — disparadas em momentos fixos do fluxo
- Por silêncio — disparadas após um tempo configurável de inatividade do lead

**Tipo 2 — Mensagens do Agente de IA** — três naturezas:
- Atendimento — respostas e interações com o lead
- Alerta — sinalizações internas para o humano (nunca visíveis ao lead)
- Migração de Kanban — mensagem específica enviada ao lead quando ocorre avanço de coluna

**Tipo 3 — Mensagens do Humano** — enviadas diretamente pelo atendente ao lead ou ao agente via canal interno.

## Regras

**Regra 1 — Humano Assumiu → Agente em Background com Reassunção por Timeout**
Quando o atendente humano entra na conversa, `agente_responsavel` passa para humano. O agente para de falar com o lead, mas continua lendo tudo em background. Após o tempo configurado na Regra 8 sem nenhuma mensagem do humano ou do lead, o agente lê todo o histórico, reassume a conversa, notifica o humano que reassumiu e registra na base de conhecimento.

**Regra 2 — Agente em Dúvida → Consulta o Humano Antes de Responder**
Quando o agente encontra uma situação fora do seu escopo ou base de conhecimento (ex: preço fora da tabela, pergunta sem resposta definida), ele pausa a resposta ao lead, envia um alerta privado ao humano pedindo orientação e só responde ao lead após receber a instrução. O aprendizado é registrado na base de conhecimento.

**Regra 3 — Monitoramento Periódico de Tickets Fora do Fluxo Ativo**
Fora do contexto de conversas individuais, o agente varre periodicamente os tickets e colunas do Kanban. Ao identificar padrões ou problemas (ex: múltiplos leads travados no mesmo ponto), sinaliza ao humano via alerta privado. Não interfere em nenhuma conversa individual durante esse processo. Registra na base de conhecimento o que for relevante.

**Regra 4 — Trava Total de Fala Quando Humano é Responsável**
Enquanto `agente_responsavel=humano`, o agente não envia nenhuma mensagem visível ao lead — nem de atendimento, nem de migração, nem automáticas de sua autoria. A única exceção é a reassunção prevista na Regra 1 após o timeout expirar.

**Regra 5 — Leitura Obrigatória de Histórico Antes de Qualquer Pergunta**
Antes de solicitar qualquer informação ao lead, o agente varre todo o histórico da conversa — incluindo transcrições de áudios, imagens e documentos — verificando se aquela informação já foi fornecida. Se já foi fornecida, o agente usa o dado existente e registra na base de conhecimento. Perguntar algo já respondido é comportamento proibido.

**Regra 6 — Proibição de Eco**
O agente nunca deve repetir literalmente o que o lead acabou de escrever como parte da resposta. Reformulação com acréscimo de valor é permitida. Repetição pura é proibida.

**Regra 7 — Validação de Contexto Antes de Responder**
Antes de gerar qualquer resposta, o agente valida internamente:
- A resposta é relevante para o que o lead perguntou agora?
- A resposta está alinhada com o escopo do atendimento?
- A resposta não contradiz nada dito anteriormente no histórico?

Se qualquer validação falhar → o agente aplica a Regra 2 em vez de responder diretamente.

**Regra 8 — Timeout Configurável de Reassunção (Humano → Agente)**
O sistema expõe um campo de configuração por workspace: `timeout_reassuncao_agente` (valor inteiro, unidade segundos|horas|dias, ativo true|false). Ao expirar o tempo sem mensagem do humano ou do lead, a Regra 1 é acionada automaticamente.

**Regra 9 — Lead Cobra Resposta Durante Espera do Humano**
Se o lead enviar mensagem enquanto o agente aguarda orientação do humano (Regra 2), o agente responde uma única vez com mensagem padrão configurável (ex: "Estou verificando mais detalhes sobre isso para te dar a melhor resposta. Em breve retorno!"). O agente não repete essa mensagem caso o lead insista — apenas registra as novas mensagens no histórico e segue aguardando.

**Regra 10 — Transcrição como Fonte de Dados de Primeira Classe**
Áudios, imagens e documentos transcritos têm o mesmo peso que mensagens de texto para todas as regras do sistema. O agente trata transcrições como parte nativa do histórico, sem distinção de origem ou formato.

**Regra 11 — Mensagens de Alerta do Agente São Privadas por Padrão**
Todas as mensagens de alerta geradas pelo agente — dúvidas, sinalizações, padrões identificados — nunca são visíveis ao lead. São enviadas exclusivamente ao canal interno do atendente humano.

**Regra 12 — Monitoramento de Kanban Adaptável a Qualquer Workspace**
O agente monitora colunas com base em metadados configuráveis por workspace: nome da coluna, tempo máximo esperado de permanência e nível de criticidade. Nenhuma coluna é fixa no sistema. O agente sinaliza ao humano via alerta privado quando um lead ultrapassa o tempo configurado em uma coluna sem movimentação.

**Regra 13 — Migração de Coluna é Responsabilidade Prioritária do Agente**
O agente monitora continuamente se o objetivo da coluna atual foi atingido. Assim que o lead cumpre o critério de avanço configurado, o agente:
1. Executa a migração do lead para a próxima coluna automaticamente
2. Envia mensagem de migração ao lead (tipo específico — nunca confundida com mensagem de atendimento)
3. Notifica o humano via canal interno que a migração ocorreu
4. Registra na base de conhecimento o contexto e o que levou à migração

Sobre migração manual pelo humano: o humano pode migrar o lead manualmente a qualquer momento — essa autonomia nunca é bloqueada. Quando isso ocorre, o agente detecta a mudança de coluna, lê o contexto completo, se atualiza e assume o acompanhamento na nova coluna normalmente. O agente registra que a migração foi realizada pelo humano.

Guardrails: critério de avanço é configurável por workspace — nenhum critério fixo assumido pelo sistema. O agente nunca pula colunas — qualquer exceção escala ao humano para decisão.

## Índice Rápido

| # | Regra | Bloco |
|---|---|---|
| 1 | Humano assumiu → agente em background + reassunção por timeout | Bloco 2 |
| 2 | Agente em dúvida → consulta humano antes de responder | Bloco 3 |
| 3 | Monitoramento periódico de tickets fora do fluxo | Bloco 4 |
| 4 | Trava total de fala quando humano é responsável | Bloco 2 (já parcialmente implementado) |
| 5 | Leitura obrigatória de histórico antes de perguntar | Bloco 3 |
| 6 | Proibição de eco | Bloco 3 |
| 7 | Validação de contexto antes de responder | Bloco 3 |
| 8 | Timeout configurável de reassunção | Bloco 2 |
| 9 | Lead cobra resposta durante espera do humano | Bloco 3 |
| 10 | Transcrição como fonte de dados de primeira classe | ✅ já implementado |
| 11 | Alertas do agente são privados por padrão | **Bloco 1 — ver `2026-08-06-alerta-interno-agente-design.md`** |
| 12 | Monitoramento adaptável a qualquer Kanban | Bloco 4 |
| 13 | Migração de coluna é responsabilidade prioritária do agente | Bloco 4 (parte já implementada na Frente 1 da base de conhecimento) |

## Decisões de arquitetura já fechadas (2026-08-06)

- **Ordem de construção:** Bloco 1 (infra de alerta) → Bloco 2 (handoff) → Bloco 3 (guardrails) → Bloco 4 (monitoramento). Bloco 1 é pré-requisito técnico dos outros três.
- **Canal de alerta (Regra 11):** seção própria na UI, separada do sino de "Agenda para agora" (`AgendaImediataService`) — semânticas diferentes (fila de ação vs. aviso do que aconteceu).
- **Frequência do monitoramento (Regra 3):** comando novo, a cada 15 minutos — separado do Gestor do Kanban (que é semanal e estratégico, não operacional).
- **Autovalidação (Regra 7):** uma única chamada de IA com autocrítica embutida no prompt, não duas chamadas separadas — YAGNI, evita dobrar custo/latência em toda mensagem de todo tenant. Reavaliar se a taxa de erro observada (via T-IA-MONITOR, quando existir) justificar.
- **`ConfiguracaoTimeout` (Regra 8) não vira tabela genérica** — timeouts continuam como campos nomeados e específicos na tabela do dono do conceito, mesmo padrão já usado em `kanban_coluna_configs.followup_estagio1_segundos`/`auto_mover_segundos`/`sdr_delay_segundos`.
