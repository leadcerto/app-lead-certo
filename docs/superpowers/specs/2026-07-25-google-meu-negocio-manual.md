# Manual Completo — Lead Certo
## Módulo Google Meu Negócio (Gestão de Perfis, Avaliações e Equipe)

> Manual funcional e de negócio — descreve **o que** o sistema deve fazer, **o que ele nunca pode fazer**, e **por quê**. Não contém código, schema de banco literal ou prompts de IA prontos — decisões de implementação são do desenvolvedor.
>
> **Ponto de partida:** já existe uma versão funcionando hoje, operando o perfil de um franqueado (Frete.Rio) — este manual descreve a versão melhorada e generalizada para múltiplos franqueados e múltiplas fichas por franqueado.

---

# ÍNDICE GERAL

```
PARTE I  — Manual do Usuário (Operacional)
  1.  Visão Geral do Módulo
  2.  Gestão de Fichas de Empresa
  3.  Geração de Conteúdo de Avaliação via IA
  4.  Agendamento de Avaliações
  5.  Papéis: Gerente e Avaliador
  6.  Painel do Avaliador
  7.  Validação Automática da Avaliação
  8.  Comissionamento e Ciclo de Pagamento
  9.  Monitoramento de Qualidade da Ficha
  10. Publicações Automáticas
  11. Criação de Novas Fichas em Escala

PARTE II — Manual Técnico (Desenvolvedor)
  12. Arquitetura Macro
  13. Conceitos e Entidades (modelo conceitual)
  14. Regras de Negócio Críticas — O que Fazer / O que Não Fazer
  15. Integração com a API do Google — Pontos de Atenção
  16. Auditoria e Rastreabilidade
  17. Checklist de Validação antes de Produção
```

---

# PARTE I — MANUAL DO USUÁRIO

## Capítulo 1 — Visão Geral do Módulo

### 1.1 Por que este módulo existe

Avaliações no Google Meu Negócio geram tráfego orgânico de altíssima qualificação: aparecem nas primeiras posições de busca e captam clientes que ainda não conhecem a empresa, só pela confiança gerada por outras pessoas. Um perfil bem avaliado e bem preenchido é, na prática, uma ferramenta de prospecção — e por isso vale o investimento de tempo para automatizar sua gestão.

### 1.2 O que o módulo cobre

- Cada franqueado pode ter **várias fichas/perfis** dentro do Google Meu Negócio (ex: uma ficha por bairro/unidade).
- Criação e edição de fichas direto pela plataforma.
- Geração de textos de avaliação variados via IA, com controle humano sobre palavras-chave e contextos.
- Cadastro e gestão de uma equipe de pessoas que fazem as avaliações (avaliadores), organizadas sob gerentes.
- Agendamento de quantas avaliações cada perfil deve receber, por dia da semana.
- Validação automática: o sistema confirma se a avaliação combinada realmente foi publicada no perfil certo.
- Comissionamento e pagamento semanal da equipe, proporcional ao que foi cumprido corretamente e no prazo.
- Monitoramento da "saúde"/completude da ficha (não só das avaliações).
- Publicações automáticas recorrentes no perfil (posts, promoções).

---

## Capítulo 2 — Gestão de Fichas de Empresa

### 2.1 Múltiplas Fichas por Franqueado

Um franqueado pode ter mais de uma ficha cadastrada no Google Meu Negócio (por exemplo, uma unidade por bairro/região). O sistema precisa tratar cada ficha como uma entidade própria, com seu próprio conjunto de agendamentos, avaliações e indicador de qualidade — mesmo que várias fichas pertençam ao mesmo franqueado.

### 2.2 Edição de Fichas Existentes

Dados de uma ficha (descrição, horário de funcionamento, categoria, fotos, etc.) devem poder ser editados diretamente pela plataforma, sem precisar acessar o painel do Google separadamente.

### 2.3 Vínculo com Páginas do Site

Cada ficha pode apontar para uma página específica do site do franqueado (por exemplo, a página do bairro correspondente), em vez de sempre linkar para a home do site. Isso aumenta a relevância do clique — quem procura "empresa X no bairro Y" cai direto na página daquele bairro, não numa página genérica.

---

## Capítulo 3 — Geração de Conteúdo de Avaliação via IA

### 3.1 Painel de Palavras-chave e Contextos

O operador configura, por ficha (ou por franqueado), uma lista de **palavras-chave e contextos** relacionados ao negócio. A IA usa esse material como matéria-prima para gerar dezenas de modelos de texto de avaliação diferentes (a referência dada foi de 50 a 80+ modelos por rodada de geração).

**Diferença entre palavra-chave e contexto:**
- Palavra-chave: um termo pontual a ser usado (ex: "mudança", "cuidado com os móveis").
- Contexto: uma ideia/situação mais completa que a IA desenvolve em texto próprio (ex: "o cliente elogiando a pontualidade da equipe").

### 3.2 Citação de Funcionários por Função

Um diferencial importante: os textos gerados podem citar nomes reais de funcionários, agrupados por função (ex: atendimento, motorista, carregador, montador de móveis). Isso torna a avaliação mais crível e, ao mesmo tempo, reconhece publicamente o trabalho de cada equipe.

### 3.3 Variação de Tom

A IA deve alternar entre tons diferentes ao longo das avaliações geradas — mais sério/formal em algumas, mais descontraído (com emojis, gírias leves) em outras — para que o conjunto de avaliações não pareça uniforme demais, o que soaria artificial.

### 3.4 O que a Geração de Conteúdo deve fazer

- Gerar um volume alto de variações (dezenas) a partir de um número pequeno de palavras-chave/contextos configurados.
- Misturar palavra-chave + contexto + (quando aplicável) nome de funcionário + tom variável, evitando repetição perceptível entre avaliações do mesmo perfil.
- Deixar o conjunto de textos gerado disponível para revisão humana antes de entrar na fila de agendamento — a geração de texto é automática, mas a aprovação do conjunto de modelos disponíveis é humana.

### 3.5 O que a Geração de Conteúdo nunca deve fazer

- Nunca reutilizar o texto exato de uma avaliação já publicada em outro perfil ou por outro avaliador — isso é facilmente detectável e prejudica a credibilidade do conjunto.
- Nunca gerar conteúdo que faça afirmações verificáveis e potencialmente falsas sobre a empresa (números específicos, promessas) — o texto deve ficar no campo da experiência subjetiva do "cliente".

---

## Capítulo 4 — Agendamento de Avaliações

### 4.1 Agendamento Manual por Dia da Semana

A tela de agendamento mostra, para cada ficha cadastrada, os dias da semana (domingo a sábado), e o operador define quantas avaliações quer para aquele perfil em cada dia — podendo distribuir de forma desigual entre os dias e entre os perfis, conforme sua própria estratégia.

### 4.2 Balanceamento Automático (evolução do modelo manual)

Como alternativa ao agendamento manual dia a dia, o operador pode simplesmente informar **quantas avaliações no total** quer investir naquela semana, e o sistema distribui automaticamente essas avaliações entre os dias da semana e entre os perfis selecionados, de forma aleatória — evitando um padrão previsível de "sempre no mesmo dia, sempre a mesma quantidade".

### 4.3 Distribuição entre Avaliadores

Depois que a agenda da semana está pronta (manual ou balanceada), o sistema distribui essas avaliações entre os avaliadores cadastrados. Essa distribuição não é fixa — ela responde ao desempenho de cada avaliador (ver Capítulo 5).

### 4.4 O que o Agendamento deve fazer

- Suportar tanto o modelo manual (dia a dia, perfil a perfil) quanto o modelo de meta semanal com distribuição automática.
- Distribuir avaliações entre avaliadores considerando o histórico recente de cumprimento de prazo — quem cumpre bem recebe mais, quem atrasa recebe menos.
- Permitir imagens/fotos anexadas à avaliação como parte do que é entregue ao avaliador (opcional, não obrigatório que seja relacionado ao serviço prestado).

### 4.5 O que o Agendamento nunca deve fazer

- Nunca distribuir mais avaliações para um perfil do que a quantidade de avaliadores disponíveis consegue cumprir dentro do prazo da semana — isso só gera atraso em cascata.
- Nunca reatribuir uma avaliação já entregue a um avaliador para outro avaliador sem uma ação explícita do gerente responsável.

---

## Capítulo 5 — Papéis: Gerente e Avaliador

### 5.1 Hierarquia

O sistema tem dois perfis de acesso relacionados à operação de avaliações:

| Papel | Responsabilidade |
|---|---|
| **Gerente** | Responsável por uma equipe de avaliadores. Recebe parte da comissão de cada avaliação concluída pela sua equipe. |
| **Avaliador** | Executa as avaliações atribuídas a ele. Recebe a outra parte da comissão por avaliação concluída corretamente e no prazo. |

### 5.2 Regras de Vínculo

- Um avaliador é **fixo a um único gerente** — não pertence a duas equipes ao mesmo tempo.
- Um avaliador pode, porém, ser responsável por **vários perfis de avaliação** diferentes (ou seja, o vínculo fixo é com o gerente, não com um único perfil de empresa).

### 5.3 O que a Estrutura de Equipe deve fazer

- Permitir o cadastro de gerentes e, dentro de cada gerente, o cadastro dos avaliadores da sua equipe.
- Refletir no cálculo de comissão (Capítulo 8) o vínculo gerente↔avaliador correto a cada avaliação concluída.
- Permitir que o gerente visualize o desempenho (pontualidade, volume concluído) de toda a sua equipe.

### 5.4 O que a Estrutura de Equipe nunca deve fazer

- Nunca permitir que um avaliador figure em mais de uma equipe/gerente simultaneamente — isso quebraria o cálculo de comissão do gerente.
- Nunca pagar comissão de gerente para alguém que não é o gerente responsável pelo avaliador que executou aquela avaliação específica.

---

## Capítulo 6 — Painel do Avaliador

### 6.1 O que o Avaliador vê

Ao entrar no seu painel (login e senha próprios), o avaliador vê:
- Todas as avaliações que tem para fazer.
- Os dias em que cada uma deve ser feita.
- Qual é o perfil de empresa que está avaliando.
- O texto pronto que ele vai copiar e colar na avaliação do Google.

### 6.2 Fluxo de Execução pelo Avaliador

1. O avaliador clica no link que já está na tarefa — isso abre diretamente o perfil da empresa correto no Google.
2. Ele copia o texto fornecido e cola na avaliação do Google.
3. Ele confirma no painel que concluiu aquela tarefa.

O trabalho do avaliador é deliberadamente simples e rápido — a complexidade (escolha do texto, validação de que realmente foi publicado) fica toda do lado do sistema, não dele.

### 6.3 Indicação Visual de Status

Cada avaliação pendente aparece destacada (colorida) no painel do avaliador. Assim que o sistema confirma que a avaliação foi validada de verdade (Capítulo 7), ela passa a aparecer como concluída (visualmente neutra — "preto e branco"), dando ao avaliador uma confirmação clara do que já está fechado.

### 6.4 O que o Painel do Avaliador deve fazer

- Mostrar de forma simples e sem ambiguidade o que falta fazer e o que já foi confirmado.
- Diferenciar visualmente tarefas pendentes de tarefas confirmadas.
- Mostrar ao avaliador seu histórico de avaliações concluídas com sucesso na semana.

### 6.5 O que o Painel do Avaliador nunca deve fazer

- Nunca marcar uma avaliação como concluída só porque o avaliador clicou "concluí" — a confirmação real depende da validação automática (Capítulo 7), não da palavra do avaliador.
- Nunca expor a um avaliador os dados/tarefas de outro avaliador da mesma equipe.

---

## Capítulo 7 — Validação Automática da Avaliação

### 7.1 Por que a Validação é Necessária

O modelo anterior dependia apenas do agendamento — o sistema assumia que a avaliação tinha sido feita quando o avaliador confirmava. Isso não garante que a avaliação realmente existe no perfil, publicada com o texto certo, e não bloqueada pelo Google.

### 7.2 Como a Validação Funciona

O sistema lê periodicamente (em horários determinados, todos os dias) as avaliações recebidas em cada perfil de empresa no Google Meu Negócio, e cruza com o que estava agendado para aquele avaliador, naquele perfil, naquele dia.

- **Se a avaliação agendada realmente existir** no perfil, com o texto esperado: ela é marcada como **concluída automaticamente**, e o crédito correspondente é gerado para o avaliador (e para o gerente, ver Capítulo 8).
- **Se não existir** (não foi publicada, foi bloqueada pelo Google, ou o texto não confere): a tarefa continua pendente/atrasada no painel, e o sistema pode gerar um alerta.

### 7.3 O que a Validação deve fazer

- Rodar de forma automática e recorrente, sem depender de ação manual do operador para "checar se já foi feito".
- Gerar o crédito **apenas** quando a existência real da avaliação for confirmada — nunca antes disso.
- Alertar quando uma avaliação que deveria ter sido feita num determinado dia não foi encontrada até o fim daquele dia.

### 7.4 O que a Validação nunca deve fazer

- Nunca gerar crédito de comissão baseado apenas na confirmação manual do avaliador, sem checagem real no perfil do Google.
- Nunca marcar uma avaliação atrasada/não encontrada como concluída silenciosamente — atraso deve sempre aparecer com um alerta correspondente (ver Capítulo 8, para o efeito disso na comissão).

---

## Capítulo 8 — Comissionamento e Ciclo de Pagamento

### 8.1 Valor por Avaliação

Cada avaliação concluída corretamente gera um valor fixo de comissão. Esse valor, junto com o percentual de divisão entre gerente e avaliador, é **configurável por franquia** — franqueados diferentes podem ter valores e percentuais diferentes.

### 8.2 Divisão entre Gerente e Avaliador

Quando uma avaliação é validada (Capítulo 7), o valor da comissão é dividido entre:
- O **avaliador** que executou a avaliação.
- O **gerente** responsável pela equipe daquele avaliador.

O percentual exato dessa divisão é parametrizável por franquia, não fixo no sistema.

### 8.3 Impacto do Atraso na Remuneração

Uma avaliação concluída **dentro do prazo** gera remuneração completa (100%) para o avaliador. Uma avaliação concluída **com atraso** (validada depois da data em que estava agendada) ainda é dada como concluída, mas com remuneração reduzida — o valor exato da redução é uma configuração de negócio, não um número fixo do sistema.

Esse mecanismo existe para incentivar pontualidade: avaliadores consistentemente pontuais tendem a receber mais avaliações futuras (Capítulo 4.4); avaliadores que atrasam recorrentemente recebem cada vez menos, até serem desligados da operação.

### 8.4 Ciclo de Pagamento Semanal

- A semana de referência vai de **domingo a sábado**.
- O pagamento de uma semana só é **liberado** quando **todas** as avaliações daquele lote semanal estiverem concluídas (ou definitivamente encerradas como não cumpridas).
- O pagamento é realizado na **segunda-feira seguinte** ao fechamento da semana.

### 8.5 O que o Comissionamento deve fazer

- Calcular a comissão de cada avaliação individualmente, no momento em que ela é validada (Capítulo 7) — não só no fechamento da semana.
- Consolidar os valores por avaliador e por gerente ao final da semana, para liberação do pagamento.
- Aplicar o percentual de redução por atraso de forma consistente e visível para o avaliador (ele deve entender por que recebeu menos, se for o caso).

### 8.6 O que o Comissionamento nunca deve fazer

- Nunca liberar o pagamento de uma semana antes de todo o lote daquela semana estar fechado — mesmo que a maior parte já esteja concluída.
- Nunca pagar comissão de gerente sem que a comissão do avaliador correspondente também tenha sido processada — as duas partes vêm da mesma avaliação validada.
- Nunca aplicar um valor ou percentual de comissão diferente do configurado para aquela franquia específica.

---

## Capítulo 9 — Monitoramento de Qualidade da Ficha

### 9.1 Além das Avaliações

O Google usa critérios próprios de completude e atividade da ficha para decidir seu posicionamento nas buscas — não depende só do volume de avaliações. Este módulo deve, portanto, também monitorar a saúde geral da ficha.

### 9.2 O que deve ser Monitorado

- Um indicador visual (ex: percentual) de o quão bem preenchida e otimizada está a ficha para os critérios de busca do Google.
- Campos que precisam de atualização recorrente — o exemplo mais comum é o horário de funcionamento, que o próprio Google frequentemente solicita revalidação (feriados, horários especiais).

### 9.3 O que o Monitoramento deve fazer

- Sinalizar de forma clara quais campos da ficha estão incompletos ou desatualizados, e o impacto disso no posicionamento.
- Quando possível, atualizar automaticamente campos padronizáveis (como horário de funcionamento) em todas as fichas de uma vez, via integração com a API do Google.

---

## Capítulo 10 — Publicações Automáticas

### 10.1 O Padrão de Publicação Manual (hoje)

Hoje, publicações no perfil (fotos/banners com texto, na aba de promoções) são feitas manualmente, poucas vezes por semana, reaproveitando a mesma imagem-base — só o nome do arquivo de imagem muda a cada publicação nova (é por esse nome que o Google identifica a imagem como "nova").

### 10.2 O que a Automação deve fazer

- Publicar automaticamente, em intervalo recorrente configurável, reaproveitando um banner-base por perfil, trocando o nome do arquivo a cada publicação.
- Gerar o texto de cada publicação de forma automática, sem depender de alguém entrar manualmente no perfil do Google.
- Funcionar de forma **totalmente automática** — diferente do agendamento de avaliações (que depende de pessoas), aqui não há intervenção humana no dia a dia.

---

## Capítulo 11 — Criação de Novas Fichas em Escala

### 11.1 O Problema que Resolve

Franqueados novos podem já ter dezenas ou centenas de endereços que ainda **não existem** como fichas no Google Meu Negócio (diferente do caso do Frete.Rio, cujas fichas já existiam e só precisavam ser geridas). O exemplo de referência dado foi um cliente com mais de 200 endereços a cadastrar de uma vez.

### 11.2 O que a Criação em Escala deve fazer

- Permitir definir um padrão de dados (descrição, categoria, campos-base) a ser aplicado a um lote inteiro de novas fichas, evitando preenchimento repetitivo campo a campo.
- Enviar as fichas para aprovação do Google via integração direta com a API, sem depender de cadastro manual um a um pelo painel do Google.
- Acompanhar o status de aprovação de cada ficha enviada em lote (aprovada, pendente, rejeitada).

---

# PARTE II — MANUAL TÉCNICO (DESENVOLVEDOR)

## Capítulo 12 — Arquitetura Macro

```
LEAD CERTO — MÓDULO GOOGLE MEU NEGÓCIO
│
├── GESTÃO DE FICHAS
│   ├── Fichas existentes (edição, vínculo com página do site)
│   └── Criação em lote de fichas novas (via API do Google)
│
├── GERAÇÃO DE CONTEÚDO (IA)
│   ├── Painel de palavras-chave / contextos / nomes de funcionários por função
│   └── Geração de dezenas de modelos de avaliação, com tom variável
│
├── AGENDAMENTO
│   ├── Manual (por dia da semana × perfil)
│   ├── Balanceamento automático (meta semanal total)
│   └── Distribuição entre avaliadores (por desempenho)
│
├── EQUIPE
│   ├── Gerente (equipe de avaliadores, recebe comissão da equipe)
│   └── Avaliador (fixo a 1 gerente, pode atender vários perfis)
│
├── PAINEL DO AVALIADOR
│   └── Tarefas do dia, texto pronto, link direto, status visual
│
├── VALIDAÇÃO AUTOMÁTICA
│   └── Leitura periódica do perfil real no Google × cruzamento com agendado
│
├── COMISSIONAMENTO
│   ├── Valor por avaliação (configurável por franquia)
│   ├── % gerente / avaliador (configurável por franquia)
│   ├── Redução por atraso
│   └── Fechamento semanal (dom-sáb) + pagamento na segunda seguinte
│
├── QUALIDADE DA FICHA
│   └── Indicador de completude + atualização automática de campos-padrão
│
└── PUBLICAÇÕES AUTOMÁTICAS
    └── Banner reaproveitado + texto gerado + agenda recorrente, sem intervenção humana
```

## Capítulo 13 — Conceitos e Entidades (modelo conceitual)

| Conceito | O sistema precisa saber... |
|---|---|
| Ficha de Empresa | A qual franqueado pertence, vínculo com a API do Google, página do site associada, indicador de qualidade atual |
| Palavra-chave / Contexto | A qual ficha (ou franqueado) pertence, se está ativo para geração de novos textos |
| Modelo de Avaliação Gerado | Texto, tom, se já foi usado (para evitar repetição), a qual ficha pertence |
| Gerente | Identidade, equipe de avaliadores vinculados |
| Avaliador | Identidade, gerente responsável (fixo), quais fichas está autorizado a avaliar |
| Agendamento | Ficha, avaliador designado, data prevista, texto/modelo atribuído |
| Validação da Avaliação | Se foi encontrada de fato no perfil real, quando foi encontrada, se está dentro ou fora do prazo |
| Comissão | Avaliação de origem, valor total, parte do avaliador, parte do gerente, se foi reduzida por atraso |
| Semana de Pagamento | Intervalo domingo-sábado, status de fechamento (todas avaliações concluídas ou não), data de liberação (segunda seguinte) |
| Publicação Automática | Ficha, banner-base usado, texto gerado, data programada |

## Capítulo 14 — Regras de Negócio Críticas: O que Fazer / O que Não Fazer

### 14.1 Validação antes de Crédito

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Gerar crédito de comissão só depois de confirmar, via leitura do perfil real, que a avaliação existe com o texto esperado | Gerar crédito assim que o avaliador confirma manualmente no painel | Sem essa checagem, o sistema pagaria por avaliações que nunca foram publicadas de verdade, ou que o Google bloqueou |

### 14.2 Hierarquia Gerente/Avaliador

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Manter o avaliador vinculado a exatamente um gerente | Permitir um avaliador em duas equipes ao mesmo tempo | Quebraria o cálculo de comissão do gerente — de qual equipe seria a comissão? |
| Permitir que um avaliador atenda vários perfis de empresa diferentes | Restringir um avaliador a um único perfil | O modelo de negócio já prevê avaliadores cobrindo múltiplas fichas |

### 14.3 Comissionamento e Pagamento

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Usar o valor e o percentual configurados **daquela franquia específica** em cada cálculo | Aplicar um valor/percentual único, fixo pra toda a plataforma | Já foi decidido que isso varia por franquia |
| Reduzir a remuneração do avaliador quando a validação ocorrer fora do prazo agendado | Pagar o valor cheio independente de atraso | O modelo de incentivo por pontualidade depende dessa diferença |
| Só liberar o pagamento da semana quando **todo** o lote semanal estiver fechado | Liberar pagamento parcial conforme cada avaliação vai sendo concluída | Regra de negócio explícita: fechamento é por lote semanal completo, não por avaliação isolada |
| Pagar na segunda-feira seguinte ao fechamento da semana (domingo-sábado) | Pagar em qualquer outro dia, ou usar semana com corte diferente (ex: segunda-domingo) | Ciclo já definido explicitamente pelo negócio |

### 14.4 Distribuição de Avaliações

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Considerar o histórico de pontualidade do avaliador ao distribuir novas avaliações | Distribuir de forma igual entre todos os avaliadores, ignorando desempenho | O modelo de negócio usa a distribuição como incentivo — quem cumpre bem recebe mais |

---

## Capítulo 15 — Integração com a API do Google — Pontos de Atenção

- A leitura de avaliações publicadas e a criação/edição de fichas dependem da API do Google Meu Negócio (Google Business Profile API) — o desenvolvedor precisa estudar os critérios e limites de uso em escala (rate limits, aprovação de fichas em lote) antes de desenhar a integração.
- Critérios de qualidade/posicionamento de ficha usados pelo Google (completude de campos, atividade recente, resposta a avaliações) devem ser mapeados a partir da documentação oficial da API — este manual não define esses critérios porque eles pertencem à plataforma do Google, não ao Lead Certo.
- Relatórios de chamadas/visualizações mencionados no Capítulo 1 (que hoje chegam por e-mail do Google) podem estar disponíveis via API — vale investigar antes de desenhar um relatório interno equivalente.

## Capítulo 16 — Auditoria e Rastreabilidade

Todo evento relevante deste módulo deve gerar um registro auditável, com no mínimo:
- Ficha envolvida
- Avaliador e gerente envolvidos
- Texto/modelo de avaliação usado
- Data agendada vs. data de validação real
- Se a comissão foi paga integral ou reduzida por atraso
- Semana de referência e data de liberação do pagamento

**Por que isso importa:** é a base para resolver disputas de pagamento com avaliadores/gerentes, e para medir ao longo do tempo se a equipe de avaliação está performando (pontualidade, volume, taxa de avaliações realmente validadas vs. agendadas).

## Capítulo 17 — Checklist de Validação antes de Produção

- [ ] Uma avaliação só gera crédito depois de confirmada pela leitura real do perfil, nunca só pela confirmação do avaliador
- [ ] Um avaliador nunca aparece vinculado a mais de um gerente ao mesmo tempo
- [ ] O valor e o percentual de comissão usados batem com a configuração específica daquela franquia
- [ ] Uma avaliação validada com atraso gera remuneração reduzida, não integral
- [ ] O pagamento da semana só é liberado quando todo o lote semanal está fechado, nunca parcialmente
- [ ] O pagamento cai na segunda-feira seguinte ao fechamento da semana (domingo-sábado)
- [ ] Avaliadores pontuais recebem mais avaliações nas distribuições seguintes; avaliadores atrasados recebem menos
- [ ] Toda avaliação, validação e pagamento gera registro auditável completo
