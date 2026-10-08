# Funil de Qualificação de Leads — Imóveis Caixa (1ª instância da ferramenta de funis)

**Data:** 07/10/2026
**Status:** Em revisão com o Leonardo

## 1. Contexto

Nova ferramenta da plataforma: funis de prospecção multi-tenant, com modelos diferentes de
funil pra finalidades diferentes. Cada funil tem páginas web próprias + anúncios e se conecta
a um Kanban específico. Esta é a 1ª instância real — o conteúdo completo (mecânica, avatares,
roteiros de vídeo, textos de botão, mensagens de WhatsApp, oferta da página de indicação,
estrutura de métricas) já foi todo definido e aprovado com o Leonardo em
`imoveis-caixa/_docs/funil-qualificacao-leads.md` — **este spec não repete esse conteúdo**, só
cobre a camada operacional (como isso vira código).

Decisão deliberada do Leonardo: construir esta 1ª instância como código real (rotas + views),
não como um construtor genérico de páginas — a generalização da ferramenta vem depois, quando
tivermos 1-2 funis reais rodando pra comparar. Ver [[feedback-lancar-primeiro-refinar-depois]]
na memória.

**Decisões já tomadas** (conversacional, com o Leonardo, 07/10):
- Imóveis Caixa vira tenant real da Lead Certo agora (mesmo processo usado pros outros
  clientes — ganha o Kanban de atendimento geral de brinde, via `TenantSetupService`).
- Um **segundo Kanban**, dedicado só aos leads deste funil, com 4 colunas: Novo Lead →
  Ingresso da Imersão Vendido → Presente na Imersão → Encerrado.
- Domínio: subdomínio do cliente via CNAME (`imersao.imoveisdacaixa.com.br`) apontando pra
  infraestrutura da Lead Certo — nada hospedado do lado do cliente.
- Trilha (Comprador/Especialista/Investidor) e temperatura (quente/morno) são **etiquetas**,
  não colunas — evita explosão de colunas (3×2 = 6 combinações).
- Ordem de implementação: Fase 0 (tenant+Kanban) → Fase 1 (páginas públicas) → Fase 2
  (conectar no Kanban) → Fase 3 (conectar com ferramentas de anúncio).

## 2. Achados do código existente (relevantes pra este spec)

- **Páginas públicas**: já existe o padrão certo a seguir — `GET /f/{uuid}` (`routes/web.php`)
  serve um `Formulario` público sem autenticação, por UUID. As páginas do funil vão seguir a
  mesma lógica (lookup público, sem middleware de auth/tenant), mas roteadas por DOMÍNIO em
  vez de path, já que cada funil mora no subdomínio do cliente.
- **Criação de tenant**: `TenantSetupService::configurar($tenant)`
  (`app/Services/TenantSetupService.php:17`) já cria persona padrão, Kanban padrão + colunas +
  config de IA por coluna, e motivos de desfecho padrão — tudo idempotente via `firstOrCreate`.
  `criarColunasKanban()` (linha 52) é o método a espelhar pro Kanban dedicado do funil.
- **⚠️ Risco crítico de schema**: `TicketAtendimento.coluna_kanban` (linha 225) é uma STRING
  comparada contra `KanbanColuna.chave`, e o ticket **não tem `kanban_id`** — só existe
  unicidade de `chave` dentro do MESMO Kanban (`unique(['kanban_id','chave'])`, migration
  `2026_07_17_000002`), não por tenant. Se o Kanban dedicado do funil usar uma `chave` que já
  existe no Kanban geral (ex.: `novo`), a resolução da coluna pode ficar ambígua. **Mitigação**:
  prefixar todas as `chave` do Kanban do funil com `funil_` (ex.: `funil_novo_lead`,
  `funil_ingresso_vendido`, `funil_presente_imersao`, `funil_encerrado`) — garante unicidade
  sem precisar alterar o schema existente.
- **`KanbanColunaConfig` por coluna**: hoje toda coluna criada por `criarColunasKanban()` recebe
  uma config de IA (prompts). As colunas do funil são atendidas por **humano** (time comercial
  da Imóveis Caixa, confirmado no conteúdo do funil) — não precisam de IA respondendo. Vamos
  criar a config com o comportamento de IA desativado/pass-through (a confirmar exato shape de
  `KanbanColunaConfig` na implementação — não é texto, é decisão de código).
- **Sem campo de atribuição de anúncio hoje**: nem `Contato` nem `TicketAtendimento` têm
  `fbclid`/`gclid`/UTM. `Contato.tags` (array) existe e é usado pra etiquetas gerais — vamos
  usar pra trilha+temperatura (exibição simples no card do Kanban). A atribuição de anúncio
  (fbclid/gclid, mais rica e específica do funil) vai numa tabela NOVA, não dentro de `Contato`
  (mantém o core do CRM genérico, sem campos só-funil).
- **Extração de código da mensagem**: `CovercutWebhookController::processarMensagem()`
  (linha 175) lê o texto da 1ª mensagem (linha 259-260) só pra decidir reabertura — nenhum
  parsing de tag/código hoje. Ponto de extensão: antes da criação do ticket (linhas 281-293).
- **Confirmado: hierarquia correta** — Tenant → N Kanbans (sem restrição de 1 por tenant,
  `Kanban.tenant_id`, diferenciados por `tipo`) → cada Kanban com seu próprio grupo de
  `KanbanColuna` (`kanban_id` FK). 10+ pontos do código já buscam o Kanban certo filtrando
  por `tipo='vendas'` explicitamente (`FormularioService.php:147`, `SequenciaMensagemJob.
  php:147`, `SecretariaEletronicaController.php:143`, `KanbanCanalController.php:16,49`,
  `KanbanController.php:746`, `KanbanInfoController.php:14,39`, `KanbanColunaController.
  php:54`, `RepararTicketsSemCanalCommand.php:48`) — esses continuam corretos sem nenhuma
  mudança, desde que o Kanban do funil use um `tipo` diferente de `'vendas'`.

## 3. ⚠️ Risco crítico — `KanbanColuna::chaveDeEntrada()` fica ambíguo com 2 Kanbans

Achado ao analisar a hierarquia Tenant→Kanbans→Colunas pra garantir segurança de manutenção
(pedido explícito do Leonardo, 07/10). **Este risco bloqueia a Fase 2 até ser corrigido.**

`KanbanColuna::chaveDeEntrada(int $tenantId)` (`app/Models/KanbanColuna.php:86-95`) é chamado
em `CovercutWebhookController::processarMensagem()` (linha 285) pra decidir em qual coluna
TODO ticket novo de WhatsApp entra — `'coluna_kanban' => KanbanColuna::chaveDeEntrada($tenant
->id)`. Por baixo, usa `doTenant($tenantId)` (linhas 63-69): busca TODAS as colunas do tenant
(sem filtrar por `kanban_id`), ordena por `ordem`, e `chaveDeEntrada()` pega a primeira com
`papel === Entrada`.

**Hoje isso nunca quebrou** porque todo tenant tem exatamente 1 Kanban (1 única coluna
Entrada por tenant, zero ambiguidade). No momento em que a Imóveis Caixa tiver o Kanban geral
(coluna "novo", papel Entrada) E o Kanban do funil (coluna "funil_novo_lead", papel Entrada
também), `chaveDeEntrada()` passa a ter 2 candidatos — e `first()` escolhe pela ordem
`ordem` tenant-wide, que não tem relação nenhuma com qual Kanban é o certo pra aquela
mensagem. **Resultado**: uma mensagem de WhatsApp comum (sem nada a ver com o funil) pode
cair ora no Kanban geral, ora no Kanban do funil — não-determinístico, mesma classe de bug já
corrigida nesta sessão em outro lugar (seleção de conta Instagram, rotação de imagem GMB),
só que aqui afeta TODO ticket novo da empresa, não só os do funil.

**Correção obrigatória antes da Fase 2**: adicionar parâmetro opcional
`chaveDeEntrada(int $tenantId, ?int $kanbanId = null)` (e equivalente em `doTenant()`),
filtrando por `kanban_id` quando informado. **Compatibilidade garantida**: todas as 10+
chamadas existentes continuam sem o parâmetro novo, comportamento idêntico ao de hoje (zero
regressão pra quem só tem 1 Kanban). O webhook do funil passa `$kanban->id` explicitamente,
depois de resolver se a mensagem tem o código `[CI-QUENTE]` etc. (usa o Kanban do funil) ou
não (usa o Kanban geral, `tipo='vendas'`, comportamento de hoje).

- Teste de regressão obrigatório: suíte completa dos 10+ callers existentes sem a menor
  mudança de comportamento (baseline de 17 falhas pré-existentes intacta).
- Teste novo: tenant com 2 Kanbans, cada um com coluna Entrada própria — `chaveDeEntrada()`
  sem `$kanbanId` continua resolvendo (ambíguo, mas não quebra: comportamento documentado,
  não ideal, mas é o comportamento de QUALQUER chamador que não foi atualizado pra passar o
  Kanban — por isso todo NOVO código do funil deve sempre passar `$kanbanId` explicitamente).
  Com `$kanbanId`, resolve sempre certo, nos dois Kanbans.
- Mesma lógica de risco (menor severidade, pois o resultado é um array usado de forma
  inclusiva, não winner-take-all) vale pra `chavesComPapel()` — considerar o mesmo parâmetro
  opcional por consistência, mesmo sem uso crítico identificado ainda.

## 4. Regras de segurança

1. **Isolamento de tenant nas páginas públicas**: a página pública do funil resolve o tenant
   pelo DOMÍNIO da requisição (`Route::domain()`), nunca por um ID/slug exposto na URL que
   possa ser adivinhado/enumerado — mesma proteção que o padrão `/f/{uuid}` já usa (UUID
   aleatório, não sequencial).
2. **Whitelist fechada no parsing do código do WhatsApp**: a extração do código (`[CI-
   QUENTE]` etc.) da mensagem de abertura NUNCA deve construir a `chave`/coluna de destino
   dinamicamente a partir do texto do usuário. Sempre resolver contra uma lista fechada e
   conhecida de códigos válidos (mapa código → trilha+temperatura+Kanban), ignorando (sem
   erro, só sem efeito) qualquer coisa que não bater exatamente — mensagem é texto livre que
   qualquer pessoa pode mandar pro número.
3. **Contadores de visita/clique não autenticados**: validar que o endpoint de incremento de
   contador (Fase 1) não permite inflar artificialmente métricas nem criar registros de lead
   fantasma — rate-limit básico por IP/sessão, sem exigir dado nenhum do visitante além do
   necessário.
4. **`Funil`/`FunilLead` sempre `tenant_id`-escopados**, mesmo padrão `TenantScope` já usado
   em todo o resto da plataforma (`Kanban`, `KanbanColuna`, etc.) — nunca uma query sem esse
   filtro.

## 5. Regra de manutenção — canal WhatsApp se vincula a TODOS os Kanbans automaticamente

Achado relacionado ao mesmo levantamento de segurança: `WhatsappCanalOficialController.php:
118-121` e `WhatsappCanalController.php:86-89` vinculam qualquer canal WhatsApp **novo** a
TODOS os Kanbans do tenant automaticamente (`Kanban::where('tenant_id', $tenantId)->
pluck('id')`, sem filtro de `tipo`) — decisão de produto intencional e documentada em
comentário, pra número novo já entrar disponível pra prospecção sem passo manual.

**Implicação pro funil**: quando o número oficial dedicado ao funil for conectado, ele vai
automaticamente se vincular TAMBÉM ao Kanban geral (e vice-versa, se um número novo for
conectado depois pra outro propósito, ele se vincula automaticamente ao Kanban do funil
também). **Ação na Fase 0/2**: depois de conectar o canal do funil, desvincular
explicitamente do Kanban geral (`$canal->kanbans()->detach($kanbanGeral->id)`), pra manter o
tráfego do funil isolado e não aparecer como opção de seleção aleatória de canal
(`SelecaoCanalWhatsappService`) pro Kanban geral. Revisitar essa decisão sempre que um novo
canal for conectado pro tenant Imóveis Caixa no futuro.

## 6. Fase 0 — Tenant + Kanban dedicado

- Criar tenant Imóveis Caixa via `tenant:criar` (ou tela admin) → `TenantSetupService`
  provisiona o Kanban geral automaticamente. Credenciais do usuário dono fornecidas
  separadamente pelo Leonardo (não persistidas neste documento).
- **Colunas do Kanban GERAL customizadas pro Leonardo** (não o padrão de 8 colunas da
  plataforma em `TenantFactory::colunasPadrao()`, que é orientado a atendimento de frete/
  serviço — não faz sentido pra Imóveis Caixa): `novo` (Entrada), `atendimento`
  (EmAndamento), `encerrado` (Encerramento), `fornecedores` (TransferenciaHumana — mesmo
  papel do "Outros/Internos" padrão, por ser categorização de contato e não etapa de venda),
  `pessoal` (TransferenciaHumana, mesmo motivo). Mapeamento de `papel` é minha proposta,
  ajustável — o Leonardo disse que cria colunas novas depois se precisar.
- Novo método (espelhando `criarColunasKanban()`) que cria o 2º Kanban
  (`tipo='funil_qualificacao'` ou similar, `nome='Funil de Qualificação — Imersão'`) + as 4
  colunas com `chave` prefixada `funil_*` (ver risco acima) + config de IA pass-through.
- Teste: tenant criado tem 2 Kanbans (geral + funil), cada um com suas colunas corretas,
  `chave` sem colisão entre os dois.

## 7. Fase 1 — Camada operacional das páginas públicas

- Novo modelo mínimo `Funil` (`tenant_id`, `nome`, `slug`, `dominio`, `kanban_id`) — só o
  necessário pra amarrar rotas, Kanban e contadores a uma entidade.
- Rotas agrupadas por domínio (`Route::domain('imersao.imoveisdacaixa.com.br')`), servindo:
  Página 1 (qualificação), 3 variantes de Página 2 (Comprador/Especialista/Investidor), Página
  de indicação. Conteúdo (textos/vídeo/botões) vem das views Blade, com o texto já aprovado no
  documento de conteúdo — não é editável por admin nesta fase (isso é generalização futura).
  Vídeo: placeholder até o Leonardo gravar e enviar o link real.
- Contadores de visita/clique por página/dia, seguindo o padrão de
  `WhatsappEnvioDiario` (1 linha por dia, contadores inteiros) — nova tabela `funil_visitas`.
- Teste: cada página pública responde 200 sem autenticação no domínio certo, contador
  incrementa a cada visita.

## 8. Fase 2 — Conectar no Kanban

> **Pré-requisito**: corrigir `KanbanColuna::chaveDeEntrada()`/`doTenant()` conforme a seção
> 3 ANTES de qualquer item abaixo — sem isso, ligar o Kanban dedicado quebra o roteamento de
> ticket novo pra toda a Imóveis Caixa, não só pro funil.

- Nova tabela `funil_leads` (atribuição): `funil_id`, `contato_id` (nullable até existir),
  `ticket_id` (nullable até existir), `trilha`, `temperatura`, `fbclid`, `gclid`,
  `utm_campanha`, `utm_criativo`, `criado_em`. Criado na Página 1 (quando `fbclid`/`gclid`
  chegam na URL) e atualizado conforme o lead avança.
- `CovercutWebhookController`: extrair o código `[CI-QUENTE]` etc. do texto da mensagem de
  abertura, ANTES de criar o ticket. Se encontrado: resolver trilha+temperatura, criar o
  ticket já na coluna `funil_novo_lead` do Kanban dedicado (em vez do Kanban geral), marcar
  `Contato.tags` com trilha+temperatura, vincular o `funil_leads` existente (por telefone) ao
  `contato_id`/`ticket_id` recém-criados.
- Página de indicação: captura nome+WhatsApp dos indicados (sem lógica de atribuição
  automática do bônus ainda — mecanismo de confirmação de presença em grupo é decisão em
  aberto, ver seção 10).
- Teste: mensagem com `[PA-QUENTE]` cria ticket no Kanban certo, coluna certa, etiquetas certas;
  mensagem sem código continua indo pro fluxo padrão (Kanban geral), sem regressão.

## 9. Fase 3 — Conectar com ferramentas de captura de leads

- Capturar `fbclid`/`gclid` como query param na Página 1, propagar via sessão/cookie até o
  clique do WhatsApp (embutido invisível, não no texto da mensagem) — liga no registro
  `funil_leads` criado na Fase 2.
- Disparar evento de conversão (Meta Conversions API / Google Enhanced Conversions) quando o
  ticket recebe a primeira resposta REAL do lead (não a mensagem de abertura) — ver
  "Rastreamento de conversão" no documento de conteúdo pra justificativa de negócio completa.
- Painel "Página de Conversões" (admin) com os números reais — a integração de gasto/
  impressões via API do Meta Ads/Google Ads pode ficar pra uma fase 3b separada, já que
  depende de credenciais de anúncio que só existem quando a campanha for criada de verdade.
- Teste: evento de conversão disparado só na 1ª resposta real, não em mensagens seguintes;
  idempotente (não duplica conversão pro mesmo ticket).

## 10. Riscos e decisões em aberto

- **Vídeos não gravados ainda** — toda a Fase 1 pode ser construída com placeholder de vídeo;
  o funil só fica "em pleno funcionamento" de verdade quando o Leonardo gravar e enviar os 4
  vídeos aprovados no documento de conteúdo.
- **Destino exato do CNAME** — só existe depois que a infraestrutura de hospedagem multi-funil
  rodar em produção (pode ser o próprio domínio principal da Lead Certo com `Route::domain()`,
  a confirmar na implementação).
- **Mecanismo de atribuição da indicação em grupo** (bônus de 5 convidados presentes) — ainda
  não decidido como o sistema confirma que um presente veio de uma indicação específica.
- **Config de IA pass-through pras colunas do funil** — shape exato de `KanbanColunaConfig`
  pra uma coluna 100% humana (sem IA respondendo) precisa ser confirmado durante a Fase 0.
- **Integração com API do Meta Ads/Google Ads** (gasto/impressões) — depende de ter campanha
  de anúncio real criada; pode virar fase separada (3b) sem bloquear o resto.

## 11. Verificação

- TDD em cada fase, suíte completa sem regressão além da baseline de 17 falhas pré-existentes.
- Deploy seguindo o workflow padrão do app-painel (branch → commit → merge --no-ff → push →
  `deploy.sh`, confirmado via pergunta explícita antes de cada deploy).
- Teste manual real: tenant criado em produção, Kanban dedicado visível na tela, página
  pública acessível (mesmo sem domínio real ainda, via path de teste ou domínio de staging),
  mensagem de WhatsApp de teste com código `[CI-QUENTE]` cria ticket no lugar certo.
