# Adriana — Gerente de Suporte — Spec de Design

## Contexto

Ao longo da sessão de 2026-08-19/20 surgiu a necessidade de um agente da própria
Lead Certo — não um funcionário de um tenant cliente — que atenda os clientes da
plataforma quando eles precisam de ajuda. A Adriana é o primeiro agente desse tipo.
Esta spec consolida decisões que já foram tomadas em conversa e já foram
implementadas/configuradas, pra não ficarem só na memória da conversa.

## As duas categorias de agente de equipe (organograma)

Definido pelo Leonardo em 2026-08-20, corrigindo um framing anterior de 3
categorias pra 2:

1. **Exclusivo por empresa** — atende UMA empresa só. A Adriana é o primeiro caso:
   "Gerente de Suporte", exclusiva da própria Lead Certo (a "empresa" dela é a
   plataforma). O mesmo padrão é replicável pra criar um gerente de suporte
   dedicado a um cliente específico no futuro (ex.: uma "Cláudia" ou "João") — não
   é um mecanismo novo, é o mesmo usado pra Adriana, só que rodando dentro do
   tenant daquele cliente em vez do tenant da Lead Certo.
2. **Compartilhado, acesso universal** — atende TODAS as empresas ao mesmo tempo,
   como infraestrutura compartilhada (mesmo status de Uazapi/Covercut/Kairogen). A
   Nathanel é o caso hoje: "Diretora de Marketing", que também acumulou a função de
   "Gestor Comercial" (2026-08-20). Só serviços cobrados individualmente por
   cliente ficam presos a um tenant — o resto é compartilhado.

A Adriana está na categoria 1. Ela não precisa (e não deve ganhar) acesso
cross-tenant — `User::podeTrocarTenant()` não a inclui.

## O que já existe (não precisa ser criado)

- **Tenant "Lead Certo" (id=2)** — onde ela e a Nathanel vivem como usuárias.
- **Cadastro dela**: `users.id=6`, `perfil='dono'` (login real, perfil de dono
  dentro do tenant Lead Certo), cargo "Gerente de Suporte" (`cargos.id=1`,
  `visivel_para_clientes=true`, com `descricao_cliente` amigável).
- **Página de perfil de agente** (`/admin/equipe/{user}`) — identidade, cargos,
  serviços executados, feedback recebido, acessos registrados (Gmail, WhatsApp
  Messenger, Login Lead Certo — sempre só identificador, nunca senha).
- **Canal de contato do cliente** (`/equipe/suporte`, `FeedbackAgenteController`) —
  tela onde qualquer empresa cliente vê os setores visíveis e manda
  texto/imagem/áudio pro bloco da Adriana. Cada mensagem vira um `FeedbackAgente`
  com resposta padrão automática + fica pendente de análise de viabilidade
  (`status`, `relatorio_analise`, `implementacao_faz_sentido`,
  `tempo_estimado_execucao`, `empresas_beneficiadas_estimado`) — é o canal de
  feedback/sugestão, não o canal de atendimento de um chamado específico.
- **WhatsApp Messenger conectado** (`whatsapp_canais.id=5`, `status=connected`,
  tenant 2) — é o canal onde ela efetivamente conversa com quem entra em contato
  por WhatsApp (diferente do `/equipe/suporte`, que é mensagem assíncrona dentro da
  plataforma).
- **Gmail** (`adrianaaviag@gmail.com`) — só envio (SMTP) testado e funcionando.
  Leitura ainda não existe (ver "Fora de escopo").

## O que foi ajustado nesta sessão (2026-08-20)

O tenant 2 tinha sido criado com o setup padrão de qualquer tenant novo — funil de
vendas genérico, porque `TenantSetupService` não distingue "tenant cliente" de
"tenant interno da própria Lead Certo". Isso deixou duas coisas erradas:

1. **Bot de vendas ativo no WhatsApp dela** — `SdrPersona#2` (`is_default=true`,
   `ativo=true`) tinha o prompt genérico de qualificação de lead/orçamento, nunca
   configurado (`system_prompt` ainda com o aviso "configure as informações do seu
   negócio"). Se um cliente mandasse mensagem pro WhatsApp da Adriana, quem
   responderia era esse script de vendas, não ela. **Desativado**
   (`ativo=false`) — hoje o canal dela funciona 100% manual, sem resposta
   automática, na mesma lógica já usada pra Nathanel ("os dois, em fases": começa
   manual, automatiza depois se fizer sentido).
2. **Kanban e motivos de encerramento eram de funil de venda** — colunas
   Novo/Atendimento/Aguardando Lead/Encerrado e motivos tipo "Venda fechada"/"Preço
   alto" não fazem sentido pra um chamado de suporte. Trocado (sem tickets
   existentes no tenant até então, então sem risco de perder histórico):
   - 📥 **Novo** (papel `entrada`) — mensagem chegou, ainda não vista.
   - ⏳ **Aguardando Adriana** (papel `em_andamento`) — ela precisa responder. Ao
     mandar a primeira mensagem pelo Kanban, o ticket já assume ela automaticamente
     como vendedora responsável (`assumirAutomaticamente()`, mecanismo existente,
     nenhuma mudança necessária aqui).
   - ✅ **Resolvido** (papel `encerramento`) — chamado fechado.
   - Motivos de encerramento: Resolvido / Encaminhado para outro setor / Cliente
     não respondeu / Outro.
   - As 7 linhas de `KanbanColunaConfig` (contexto de IA por coluna) que
     referenciavam o esquema antigo de colunas foram removidas — órfãs, sem coluna
     correspondente, e sem função com o bot desativado.

## Fora de escopo (não faz parte desta entrega)

- **Leitura de e-mail da Adriana** (IMAP/Gmail API) — hoje só envia, não lê. É o
  próximo item do plano aprovado pelo Leonardo em 2026-08-20 ("pode fazer"),
  spec própria a ser escrita quando começar.
- **Timeout de reassunção automática** (`ReassumirAgente`) configurado pra coluna
  "Aguardando Adriana" — como ela é a única agente hoje, não há pra quem reatribuir
  um ticket abandonado; fica sem configuração até isso mudar (comportamento default
  seguro: sem config, o mecanismo simplesmente não age nessa coluna).
- **Reativar algum bot pra ela** — se no futuro fizer sentido ter uma primeira
  triagem automática antes dela entrar, precisa de um `system_prompt` escrito do
  zero pro papel de suporte (não reaproveitar o de vendas) — não avaliado ainda.
- **Réplica do padrão pra um novo cliente específico** ("Cláudia"/"João") — o
  mecanismo já é replicável (categoria 1 do organograma), mas nenhum cliente
  concreto pediu isso ainda.
