# Manual Completo — Lead Certo
## Sistema de Campanhas com Roteamento Inteligente via WhatsApp (Push Engine)

> Este é um manual funcional e de negócio — descreve **o que** o sistema deve fazer, **o que ele nunca pode fazer**, e **por quê**. Não contém código, schema de banco literal ou prompts de IA prontos: essas decisões de implementação são do desenvolvedor. Onde uma tabela mostra um exemplo de payload de API (seção 12), é referência de um contrato já documentado pela Uazapi — não é código gerado por este manual.

---

# ÍNDICE GERAL

```
PARTE I  — Manual do Usuário (Operacional)
  1. Visão Geral da Plataforma
  2. Gestão de Leads
  3. Personas Push
  4. Campanhas
  5. Botões Interativos e Roteamento
  6. Kanban de Destino e Transição Push → Kanban
  7. Opt-out e Bloqueio de Contato
  8. Boas Práticas e Limites por Número

PARTE II — Manual Técnico (Desenvolvedor)
  9.  Arquitetura Macro
  10. Conceitos e Entidades (modelo conceitual)
  11. Regras de Negócio Críticas — O que Fazer / O que Não Fazer
  12. Integração com o Motor de Humanização existente
  13. Auditoria e Rastreabilidade
  14. Checklist de Validação antes de Produção
```

---

# PARTE I — MANUAL DO USUÁRIO

## Capítulo 1 — Visão Geral da Plataforma

### 1.1 O que é o Lead Certo — Push Engine

O Push Engine é o módulo do Lead Certo responsável por **prospecção ativa em escala via WhatsApp**: uma ou mais personas fictícias, cada uma operando vários números de WhatsApp ao mesmo tempo, disparam campanhas segmentadas para públicos diferentes (leads frios, clientes que já compraram, contatos extraídos do Google Meu Negócio, etc.), coletam a resposta do lead através de botões interativos, e roteiam automaticamente cada lead para o atendimento certo — sem intervenção manual.

### 1.2 Conceitos Fundamentais

| Conceito | Descrição |
|---|---|
| **Persona Push** | Personagem fictício (nome, personalidade, tom de voz, conta Gmail própria) que representa a prospecção ativa. Pode operar com vários números de WhatsApp simultaneamente. |
| **Número Push** | Um número de WhatsApp (Uazapi) vinculado a uma Persona Push. Uma Persona pode ter 1 a N números ativos. |
| **Campanha** | Disparo programado de mensagens para uma lista de leads, sempre vinculada a uma única Persona Push e a um Kanban de prospecção próprio. |
| **Lead** | Contato que pode receber campanhas de prospecção. |
| **Botão Interativo** | Botão nativo da API do WhatsApp que o lead clica para responder — até 3 por mensagem. |
| **Roteamento** | Ação automática executada quando o lead clica em um botão: mover para Kanban, excluir da campanha, abrir link, ou mostrar mais informações. |
| **Kanban de Prospecção** | Kanban dedicado à campanha de uma Persona Push — cada campanha tem o seu, voltado a um público específico. |
| **Kanban de Destino** | Kanban de atendimento (vendas, suporte etc.) para onde o lead é transferido quando demonstra interesse. |
| **Opt-out Local** | Remoção do lead apenas da campanha em que ele clicou "sem interesse" — ele continua elegível para outras campanhas futuras. |

### 1.3 Fluxo Macro da Plataforma

```
[Lead cadastrado] → [Campanha criada, vinculada a uma Persona Push e ao seu Kanban de prospecção]
        ↓
[Persona Push dispara: imagem + texto curto + até 3 botões, por um dos seus Números Push]
        ↓
[Lead recebe a mensagem no WhatsApp]
        ↓
[Lead clica em um botão]
     ↙        ↓        ↘
[Kanban   [Excluído   [Link / mais
 destino]  da campanha] informações]
```

Se o lead **não clicar em nada**, ele permanece "neutro" na campanha e pode receber novas tentativas de contato conforme a sequência configurada (ex: reforço de oferta em alguns dias), até clicar em algum botão ou até a campanha decidir encerrar a tentativa com ele.

---

## Capítulo 2 — Gestão de Leads

### 2.1 O que é um Lead

Lead é qualquer contato que pode ser incluído numa lista de campanha de prospecção. Ele tem dados básicos (nome, telefone) e pode estar vinculado a uma ou mais campanhas ao mesmo tempo, desde que campanhas diferentes.

### 2.2 Cadastro de Leads

Leads entram na base do Push Engine por dois caminhos:
- **Extração/importação em lote** (ex: contatos capturados do Google Meu Negócio, lista de clientes antigos, base comprada de um segmento).
- **Cadastro manual pontual**, quando faz sentido adicionar um contato avulso a uma campanha.

Campos mínimos necessários para um lead entrar numa campanha:
- Nome (ou identificação disponível — no caso de extração de empresas, pode ser só o nome da empresa)
- Telefone WhatsApp válido
- Origem do lead (de onde veio — importante para segmentar campanhas futuras pelo mesmo público)

### 2.3 Importação em Lote

Como o volume típico de uma campanha é grande (o exemplo já usado neste projeto foi uma lista de 5.800 contatos extraídos do Google Meu Negócio), a importação precisa suportar volume alto sem exigir cadastro um a um.

**O que a importação em lote precisa garantir:**

| Regra | Por quê |
|---|---|
| Detectar e ignorar duplicatas de telefone já existentes na mesma campanha | Evita que o mesmo lead receba a mensagem inicial duas vezes na mesma campanha |
| Validar formato do telefone antes de aceitar o lead na lista | Um número mal formatado nunca deve gerar tentativa de envio — desperdiça capacidade de disparo do número Push |
| Registrar a origem/fonte de cada lote importado | Permite medir depois qual fonte de captação (Google Business, lista comprada, indicação) converteu melhor |
| Permitir importar para uma campanha existente ou criar lista nova | O operador vai repetir esse processo com frequência — sempre que uma nova fonte de leads aparecer |
| Não misturar automaticamente o mesmo lead em campanhas diferentes sem intenção explícita do operador | Um lead pode estar em mais de uma campanha (públicos diferentes), mas isso deve ser uma decisão deliberada, nunca um efeito colateral da importação |

**O que a importação em lote não deve fazer:**
- Não deve disparar mensagens automaticamente assim que os leads são importados — a importação só popula a lista; o disparo é um passo separado e deliberado (agendamento da campanha).
- Não deve sobrescrever dados de um lead que já existe na base global de contatos do Lead Certo (nome, telefone) — a importação de uma campanha de prospecção é sobre "quem entra nesta lista", não sobre "atualizar o cadastro geral do contato".

---

## Capítulo 3 — Personas Push

### 3.1 O que é uma Persona Push

Uma Persona Push é um personagem fictício, com identidade completa, criado para parecer uma pessoa real de verdade fazendo prospecção manual — não uma automação.

**Elementos de identidade de uma Persona Push:**
- Nome e sobrenome
- Idade (ou faixa etária implícita no jeito de escrever)
- Personalidade e tom de voz (mais calmo/tranquilo, mais animado, mais direto — cada persona tem o seu, consistente em todas as conversas)
- Conta de e-mail (Gmail) própria, dedicada só a essa persona
- Foto de perfil condizente com a identidade

### 3.2 Uma Persona, Vários Números

Uma única Persona Push pode operar **vários números de WhatsApp ao mesmo tempo** (o exemplo de referência deste projeto foi 20 números para uma única persona, numa campanha de 5.800 contatos). Isso existe porque a API não-oficial (Uazapi) impõe limites de disparo por número — multiplicar números é a forma de escalar o volume de prospecção sem multiplicar o número de personagens que o time precisa manter.

**O que isso exige do sistema, na prática:**
- Cada novo lead da campanha é vinculado a **um único** número Push, escolhido entre os números ativos daquela persona no momento do primeiro disparo.
- Todos os números da mesma persona devem escrever com o mesmo tom/personalidade — para quem olha de fora, "é a mesma pessoa", mesmo sendo tecnicamente vários números diferentes.
- Cada número tem sua própria capacidade de disparo diária, que **não é igual entre os números** (ver Capítulo 8, maturidade do chip).

### 3.3 O que uma Persona Push deve fazer

- Manter consistência de tom e personalidade em todas as conversas, independente de qual dos seus números está sendo usado.
- Registrar, para cada lead, qual número Push específico o atende — esse vínculo é o que evita a duplicidade tratada no Capítulo 8.
- Aguardar confirmação do Kanban de destino antes de continuar ou encerrar uma conversa, quando o lead demonstra interesse (ver Capítulo 6).

### 3.4 O que uma Persona Push nunca deve fazer

- Nunca reiniciar contato com um lead pelo mesmo número **ou por outro número da mesma persona**, se aquele lead já está com atendimento em andamento em outro canal (Kanban de destino).
- Nunca disparar a mesma mensagem para todos os seus leads no mesmo formato de texto idêntico — isso é padrão de bot detectável (mesma regra do Motor de Humanização, Capítulo 12).
- Nunca continuar tentativas de contato com um lead que já clicou em "sem interesse"/opt-out naquela campanha.

---

## Capítulo 4 — Campanhas

### 4.1 O que é uma Campanha

Uma Campanha é o conjunto completo de: uma Persona Push responsável, uma lista de leads, uma sequência de mensagens (com seus intervalos), e um Kanban de prospecção próprio que acompanha visualmente o andamento de cada lead dentro dela.

Cada campanha é voltada a **um público específico** — leads frios de uma fonte, clientes que já compraram, leads que abandonaram um orçamento, etc. Personas e campanhas diferentes existem justamente para tratar públicos diferentes de forma diferente.

### 4.2 Anatomia de uma Mensagem de Campanha

O formato padrão (quase 100% das campanhas seguem este modelo) é:

```
[ Imagem ]
[ Texto curto ]
[ Botão 1 ]  → tem um destino próprio, configurável
[ Botão 2 ]  → tem um destino próprio, configurável
[ Botão 3 ]  → opcional, também com destino próprio
```

O texto do botão é editável por campanha (ex: "Ver detalhes", "Falar com suporte", "Chamar vendedor", "Mais informações", "Bloquear contato" — o texto muda conforme o contexto da campanha, mas o **destino** por trás de cada botão é o que importa tecnicamente).

### 4.3 Sequência de Reabordagem (lead "neutro")

Se o lead não clica em nenhum botão, ele fica em estado **neutro** — nem interessado, nem recusado. A campanha pode (de forma configurável) mandar uma nova tentativa depois de um tempo (ex: "Vi que você não teve interesse, quer que eu mande de novo?" ou "Aquela oferta ainda está ativa, está terminando — quer aproveitar?").

**Regras da reabordagem:**
- Só se aplica a leads em estado neutro — nunca a quem já recusou (opt-out) ou já está em atendimento no Kanban de destino.
- Cada nova tentativa é, ela mesma, uma nova chance de o lead clicar em algum dos botões — inclusive o de recusa.
- O número de reabordagens e o intervalo entre elas são parâmetros da campanha, não um valor fixo do sistema.

### 4.4 O que uma Campanha deve fazer

- Manter o estado de cada lead (neutro / interessado / recusado) de forma única, não duplicada entre os números Push da mesma persona.
- Aplicar a "checagem de boas práticas" (Capítulo 8) antes de **cada** envio, não só no primeiro disparo da campanha.
- Pausar automaticamente e alertar o operador quando um gargalo de capacidade de envio for detectado (ver 4.5).

### 4.5 O que fazer quando a capacidade de envio está no limite

Uma campanha não deve simplesmente parar de funcionar silenciosamente se os números Push atingirem o limite diário de disparo. O comportamento esperado é:

1. A campanha **pausa** os disparos pendentes daquele momento.
2. Um alerta é enviado ao operador, explicando que a pausa ocorreu por limite de capacidade dos números.
3. A campanha **retoma sozinha** assim que a capacidade se normalizar (próximo dia, próxima janela liberada) — sem precisar de ação manual para "religar".

Isso evita dois erros opostos: continuar disparando e arriscar banimento dos números, ou parar de vez e exigir que o operador perceba e reative manualmente.

---

## Capítulo 5 — Botões Interativos e Roteamento

### 5.1 Tipos de Destino de um Botão

Cada botão de uma mensagem de campanha tem, independentemente dos outros dois, um dos seguintes destinos:

| Destino | O que acontece quando o lead clica |
|---|---|
| **Mover para Kanban** | O lead sai da campanha de prospecção e entra no Kanban de atendimento indicado (ver Capítulo 6) |
| **Excluir da campanha (opt-out)** | O lead sai da lista desta campanha (ver Capítulo 7) |
| **Abrir link** | Abre um link externo (ex: página do site, catálogo) |
| **Mostrar mais informações** | Envia conteúdo adicional sobre a campanha, sem tirar o lead do estado atual |

### 5.2 Combinações Mais Comuns

Na prática, a esmagadora maioria das campanhas usa apenas 2 botões:
- **Botão 1**: leva para o Kanban de atendimento (ex: "Ver detalhes", "Chamar vendedor")
- **Botão 2**: exclui o lead da campanha (ex: "Bloquear contato")

Um terceiro botão, quando existe, normalmente serve para abrir um link ou mostrar mais informações — um meio-termo entre "quero atendimento agora" e "não quero nada".

### 5.3 O que o Roteamento deve fazer

- Disparar a ação correta do botão **imediatamente** após o clique — sem fila de espera perceptível pelo lead.
- Registrar, para toda campanha, qual botão cada lead clicou e quando (isso é a base do relatório de conversão da campanha).
- Permitir que o texto visível do botão seja customizado por campanha, sem alterar a lógica de destino por trás dele.

### 5.4 O que o Roteamento nunca deve fazer

- Nunca permitir que dois botões da mesma mensagem levem ao mesmo tempo a ações conflitantes (ex: mover pro Kanban **e** excluir da campanha simultaneamente) — cada clique aciona exatamente um destino.
- Nunca perder o registro de qual botão foi clicado — sem esse dado, não há como medir conversão da campanha nem auditar o que aconteceu com um lead específico.

---

## Capítulo 6 — Kanban de Destino e Transição Push → Kanban

### 6.1 O que acontece quando o lead demonstra interesse

Quando o lead clica no botão que leva ao Kanban de atendimento, a Persona Push que estava conduzindo a prospecção precisa "passar o bastão" de forma limpa:

1. A Persona Push notifica o Kanban de destino que um lead está chegando.
2. A Persona Push **aguarda confirmação** de que o Kanban assumiu — o tempo de espera é configurável por Kanban, em horas, minutos ou segundos.
3. **Se a confirmação chegar**: a Persona Push encerra sua participação naquela conversa — quem continua a partir daí é o Kanban de destino.
4. **Se a confirmação não chegar** dentro do tempo configurado: a Persona Push continua conduzindo a conversa no seu próprio Kanban de prospecção, até que o próprio fluxo daquele Kanban decida encerrar automaticamente (mesma lógica de encerramento por silêncio já usada no restante do Lead Certo).

Esse desenho evita duas situações ruins: o lead ficar "no limbo" sem ninguém respondendo, ou dois canais (Persona Push e Kanban de destino) respondendo ao mesmo tempo, de forma desencontrada.

### 6.2 Histórico na Transição

Quando o Kanban de destino assume o lead, ele **sempre** recebe:
- O histórico completo da conversa que a Persona Push teve com o lead.
- Um resumo gerado dessa conversa, para que quem for atender consiga entender rapidamente o contexto antes de responder — sem precisar ler tudo desde o início.

### 6.3 O que a Transição deve fazer

- Confirmar de forma explícita e registrada o momento em que o Kanban assumiu (não presumir silenciosamente).
- Entregar histórico + resumo sempre juntos — nunca um atendimento novo "do zero" sem contexto.
- Respeitar o tempo de espera configurado por Kanban — esse valor não é fixo no sistema, é decisão de quem configura cada Kanban.

### 6.4 O que a Transição nunca deve fazer

- Nunca transferir o lead para o Kanban de destino sem levar o histórico — o atendente do Kanban nunca deve começar "cego".
- Nunca deixar a Persona Push completamente órfã (sem instrução do que fazer) se a confirmação não chegar — o comportamento de fallback (continuar no próprio Kanban até encerrar por silêncio) precisa estar sempre ativo.

---

## Capítulo 7 — Opt-out e Bloqueio de Contato

### 7.1 O que significa "sair da campanha"

Quando o lead clica no botão de recusa (ex: "Bloquear contato", "Não tenho interesse"), o comportamento correto é o **opt-out local**:

> O lead sai apenas da campanha em que clicou. Ele continua elegível para outras campanhas futuras, de outros públicos ou outras personas.

Isso é diferente de um bloqueio global (que impediria qualquer campanha, de qualquer persona, de contatar aquele número novamente) — essa não é a regra adotada aqui.

### 7.2 O que o Opt-out deve fazer

- Remover o lead da fila de disparos daquela campanha **imediatamente**, em tempo real — nunca com um atraso perceptível.
- Impedir qualquer mensagem pendente daquela campanha (já agendada, ainda não enviada) de sair para aquele lead depois do clique de recusa.
- Registrar o motivo e o momento da saída, para fins de relatório da campanha.

### 7.3 O que o Opt-out nunca deve fazer

- Nunca deixar uma mensagem "na fila" ser enviada depois que o lead já clicou em recusar — isso é o erro mais grave possível aqui: gera a exata sensação de automação insistente que este sistema inteiro foi desenhado para evitar, e é o tipo de coisa que gera denúncia e banimento de número.
- Nunca aplicar o opt-out a outras campanhas do mesmo lead sem que isso tenha sido pedido explicitamente — opt-out é por campanha, não global, a menos que o operador decida diferente no futuro.

---

## Capítulo 8 — Boas Práticas e Limites por Número

### 8.1 Por que os limites existem

Toda a arquitetura de múltiplos números por Persona Push existe por causa de um único fator: **cada número de WhatsApp tem uma capacidade de disparo limitada e diferente**, e ultrapassar esse limite é o principal caminho para o banimento.

### 8.2 Maturidade do Chip

Números de WhatsApp não têm todos a mesma capacidade — um número recém-conectado ("chip novo") aguenta muito menos volume de disparo por dia do que um número já maduro, com uso orgânico acumulado. Isso precisa ser tratado como um dado por número, não um valor único aplicado a todos os números da mesma persona.

**Consequência prática:** antes de cada envio, o sistema precisa checar se aquele número específico está dentro das "boas práticas" daquele momento da sua maturidade — não apenas um limite genérico igual para todos.

### 8.3 O que as Boas Práticas exigem, na prática

- Verificar a capacidade disponível do número **antes de cada envio**, não só uma vez no início do dia.
- Distribuir os leads de uma campanha entre os números ativos da persona de forma que nenhum número isolado ultrapasse sua própria capacidade.
- Aplicar intervalo mínimo entre disparos do mesmo número — nunca disparar em rajada.
- Nunca reutilizar o mesmo texto de mensagem, palavra por palavra, para leads diferentes — variar sempre (mesmo princípio do Motor de Humanização, Capítulo 12).

---

# PARTE II — MANUAL TÉCNICO (DESENVOLVEDOR)

## Capítulo 9 — Arquitetura Macro

```
LEAD CERTO — PUSH ENGINE
│
├── GESTÃO DE LEADS
│   └── Importação em lote / cadastro manual, com origem rastreada
│
├── PERSONA PUSH
│   ├── Identidade (nome, tom, e-mail, personalidade)
│   └── N Números Push vinculados (cada um com sua própria capacidade/maturidade)
│
├── CAMPANHA
│   ├── Lista de leads
│   ├── Sequência de mensagens (inicial + reabordagens)
│   ├── Kanban de prospecção próprio
│   └── Regras de intervalo e reabordagem
│
├── MOTOR DE ROTEAMENTO
│   ├── Botão → Mover para Kanban de destino
│   ├── Botão → Opt-out local
│   └── Botão → Link / mais informações
│
├── TRANSIÇÃO PUSH → KANBAN
│   ├── Notificação + espera de confirmação (timeout configurável por Kanban)
│   ├── Handoff de histórico + resumo
│   └── Fallback: Persona Push continua até encerramento automático por silêncio
│
└── CAMADA ANTI-BAN (compartilhada com o Motor de Humanização existente)
    ├── Limite por número (maturidade do chip)
    ├── Cooldown por número
    └── Cooldown por lead (intervalo da campanha)
```

## Capítulo 10 — Conceitos e Entidades (modelo conceitual)

Esta seção descreve **o que precisa existir conceitualmente** para as regras dos capítulos anteriores funcionarem — não é um schema de banco de dados, é a lista de "coisas que o sistema precisa saber sobre" para o desenvolvedor desenhar a estrutura de dados como achar melhor.

| Conceito | O sistema precisa saber... |
|---|---|
| Lead | Quem é, de onde veio (origem/fonte), e em quais campanhas está |
| Persona Push | Identidade, tom, e quais Números Push pertencem a ela |
| Número Push | A qual Persona pertence, sua capacidade/maturidade atual, seu histórico de uso recente |
| Campanha | Persona responsável, Kanban de prospecção vinculado, lista de leads, sequência de mensagens e intervalos |
| Vínculo Lead × Número Push | Qual número específico está conduzindo a conversa com aquele lead — fixo durante a campanha, salvo em caso de interrupção do lado do número |
| Estado do Lead na Campanha | Neutro / Interessado / Recusado — único por campanha, nunca duplicado entre números da mesma persona |
| Botão de Mensagem | Texto visível (customizável por campanha) + destino (mover Kanban / opt-out / link / mais informações) |
| Evento de Transição | Registro de quando um lead saiu da Persona Push e entrou num Kanban de destino, com histórico e resumo anexados |
| Kanban de Destino | Tempo de espera de confirmação configurado (h/min/seg) |

## Capítulo 11 — Regras de Negócio Críticas: O que Fazer / O que Não Fazer

Esta é a seção mais importante do manual técnico — consolida, num único lugar, as regras que **não podem** ser tratadas como detalhe de implementação, porque um erro aqui gera duplicidade de contato, banimento de número, ou perda de confiança do lead.

### 11.1 Estado do Lead — Global na Campanha

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Manter um único estado por lead **por campanha**, consultado antes de qualquer novo disparo | Deixar o estado "preso" ao número Push que fez o primeiro contato | Se o estado for por número, o mesmo lead pode receber a sequência de novo por outro número da mesma persona — queima a abordagem e parece descoordenado |
| Consultar o estado atual do lead antes de cada nova tentativa de reabordagem | Disparar reabordagem sem checar se o lead já mudou de estado nesse meio tempo (ex: já clicou em recusa por outro canal) | Reabordar quem já recusou é exatamente o tipo de insistência que gera denúncia |

### 11.2 Cooldown por Número ≠ Cooldown por Lead

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Tratar como duas regras **independentes**, avaliadas juntas antes de cada envio: (1) o número pode disparar agora? (2) o lead pode receber agora? | Tratar como uma regra única (ex: só checar o intervalo do lead e assumir que o número está sempre livre) | O número pode estar dentro do limite do lead, mas ter estourado seu próprio limite diário — ou o contrário. As duas checagens precisam passar, não uma ou outra |
| Reavaliar a capacidade do número a cada novo envio, não só uma vez por dia | Calcular a capacidade do número uma única vez no início do dia e não atualizar durante o dia | A capacidade "restante" do número muda a cada envio feito — uma checagem estática fica desatualizada rapidamente |

### 11.3 Opt-out em Tempo Real

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Processar o clique de opt-out e remover o lead da fila **no mesmo momento** do clique | Deixar mensagens já agendadas/em fila serem enviadas mesmo depois do opt-out | Uma mensagem chegando depois do "não tenho interesse" é o pior cenário possível — parece ignorar a vontade do lead e é motivo comum de denúncia/bloqueio |
| Aplicar o opt-out apenas à campanha específica onde o clique ocorreu | Propagar o opt-out para todas as campanhas do mesmo lead automaticamente | A decisão de negócio já tomada é opt-out **local** — bloqueio global é uma decisão diferente, não implementada por padrão |

### 11.4 Transição Push → Kanban

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Esperar a confirmação do Kanban pelo tempo configurado, e só então encerrar a Persona Push naquele lead | Encerrar a Persona Push imediatamente ao notificar o Kanban, sem esperar confirmação | Se o Kanban não assumir de verdade, o lead fica sem ninguém respondendo — pior que a Persona Push continuar um pouco mais |
| Entregar histórico completo + resumo junto com a transferência | Transferir o lead "limpo", sem contexto, para o Kanban de destino | Forçaria o atendente humano a perguntar tudo de novo — má experiência e risco de parecer desorganizado |
| Realocar o número Push vinculado ao lead **apenas** em caso de interrupção do lado do número (ex: número desconectou, foi banido, teve problema técnico) | Realocar o número só porque o lead demorou para responder | Trocar de número sem motivo técnico real quebra a continuidade percebida pelo lead — para ele, "a mesma pessoa" mudou de número sem explicação |

### 11.5 Roteamento de Botões

| ✅ Deve fazer | ❌ Não deve fazer | Por quê |
|---|---|---|
| Tratar cada botão com destino totalmente independente dos outros dois | Assumir que todos os botões de uma campanha sempre têm o mesmo tipo de destino | O botão 1 pode ir para o Kanban X, o botão 2 para o Kanban Y, o botão 3 excluir da campanha — cada campanha pode combinar diferente |
| Registrar qual botão foi clicado, por qual lead, em qual campanha, e quando | Registrar apenas "o lead respondeu", sem detalhar qual botão | Sem esse dado não há como montar relatório de conversão nem auditar decisões de roteamento |

---

## Capítulo 12 — Integração com o Motor de Humanização Existente

O Push Engine **não substitui** o Motor de Humanização já documentado (regras de delay, quebra de balões, status de digitação, limites anti-ban por número) — ele **herda e estende** essas regras para o cenário de múltiplos números por persona.

**Pontos de atenção específicos da extensão:**

- Todas as regras de delay, quebra de balões e status de presença (documentadas no arquivo de Regras Gerais de Envio já existente) se aplicam **igualmente** a cada Número Push, individualmente — não existe um "modo mais rápido" para prospecção em massa.
- O limite diário de mensagens para "números que nunca conversaram" (já documentado: máximo de 50/dia para número frio) se aplica **por Número Push**, não por Persona — é exatamente por isso que uma persona precisa de vários números para escalar volume.
- O formato de botões interativos (seção 11 do documento de Regras Gerais, já validado tecnicamente com a Uazapi) é o mesmo usado aqui — três botões, IDs descritivos, fallback em texto puro para quando o botão não renderizar.
- A regra de "nunca enviar a mesma mensagem em texto idêntico para múltiplos números" se torna ainda mais crítica no Push Engine: como uma campanha dispara para milhares de leads, a tentação de reaproveitar exatamente o mesmo texto é maior — e é exatamente esse padrão que mais chama atenção do WhatsApp.

---

## Capítulo 13 — Auditoria e Rastreabilidade

Todo evento relevante do Push Engine deve gerar um registro auditável, com no mínimo:
- Timestamp do evento
- Lead envolvido
- Campanha e Persona Push envolvidas
- Número Push que executou a ação
- Tipo de evento (disparo inicial, reabordagem, clique de botão, transição para Kanban, opt-out)
- Quando aplicável: qual Kanban de destino assumiu, e se a confirmação de handoff foi recebida dentro do tempo configurado

**Por que isso importa:** é a base para dois tipos de análise que o negócio vai precisar mais cedo ou mais tarde — (1) qual campanha/persona/origem de lead está convertendo melhor, e (2) auditoria de incidente, caso um número seja banido e seja preciso entender exatamente o que foi disparado por ele antes do bloqueio.

---

## Capítulo 14 — Checklist de Validação antes de Produção

Antes de colocar qualquer campanha real em produção, validar manualmente:

- [ ] Um lead que não respondeu pelo Número Push A não recebe a mesma sequência de novo pelo Número Push B da mesma persona
- [ ] Um lead que clicou em opt-out não recebe nenhuma mensagem pendente da mesma campanha depois do clique
- [ ] Um lead que clicou no botão de interesse é efetivamente transferido para o Kanban de destino, com histórico e resumo anexados
- [ ] Se o Kanban de destino não confirmar dentro do tempo configurado, a Persona Push continua a conversa normalmente
- [ ] Cada Número Push respeita seu próprio limite diário, mesmo quando outros números da mesma persona ainda têm capacidade disponível
- [ ] A campanha pausa sozinha e alerta o operador quando os números atingem o limite de capacidade, e retoma sozinha quando a capacidade se renova
- [ ] Todo clique de botão gera um registro auditável com lead, campanha, número e destino do botão
- [ ] Realocação de número só ocorre em caso de interrupção técnica do número — nunca por inatividade do lead
