# Canal WhatsApp Oficial via Covercut — Design Técnico

> Complementa o manual funcional `2026-07-25-cadastro-whatsapp-oficial-manual.md` (fluxo genérico de Embedded Signup, sem schema/código). Este documento cobre a integração técnica concreta com a **Covercut** (`api.covercut.com.br`), parceira BSP da Meta escolhida para operar a API Oficial do WhatsApp da Lead Certo, e a mudança de modelagem necessária para o canal oficial coexistir com o não-oficial (Uazapi).

---

## 1. Contexto e objetivo

Hoje a Lead Certo só tem um tipo de conexão de WhatsApp: a **API não-oficial** (Uazapi/Baileys), conectada via QR Code, usada tanto para prospecção quanto para atendimento. É urgente adicionar a **API Oficial do WhatsApp** (Meta Cloud API), operada através da Covercut, para receber os leads gerados por anúncios "clique para o WhatsApp".

### Decisão estratégica de uso do canal oficial

O canal oficial será usado **exclusivamente em modo de recepção/resposta**:
- Nunca dispara mensagem proativamente (nem sequência automática, nem campanha).
- Só responde quem iniciou a conversa (lead que clicou no anúncio ou mandou mensagem).
- **Não usaremos templates pagos da Meta.** Isso significa que, uma vez fechada a janela de conversa (24h, ou 72h quando a conversa se origina de anúncio), o sistema **não tenta reabrir** — o envio é bloqueado e o ticket é sinalizado para atenção humana, se necessário, por outro canal.

A prospecção continua sendo feita pelos números não-oficiais (Uazapi), que continuam funcionando exatamente como hoje.

---

## 2. Modelo de negócio dos provedores (Uazapi e Covercut)

Uazapi e Covercut são prestadoras de serviço para a Lead Certo — a Lead Certo mantém **uma conta em cada uma**, e cada franqueado (tenant) tem seus **próprios números exclusivos** dentro dessas contas compartilhadas (já é assim hoje com a Uazapi).

Cada tenant **pode ter mais de um número**, seja oficial ou não-oficial. Multi-número **não-oficial** por tenant é uma capacidade futura (fora de escopo desta entrega — hoje continua 1 número não-oficial por tenant, como já é). O suporte a múltiplos números **oficiais** por tenant também não é construído agora, mas a modelagem abaixo não impõe nenhuma restrição de banco que precise ser desfeita depois para viabilizá-lo.

---

## 3. Modelo de dados

### 3.1 Nova tabela `whatsapp_canais`

```
whatsapp_canais
  id
  tenant_id          FK -> tenants
  tipo               enum('oficial', 'nao_oficial')
  provider           enum('covercut', 'uazapi')
  status             enum('connected', 'connecting', 'disconnected')
  phone              string, nullable
  connected_since    timestamp, nullable
  config             json   -- segredos/campos específicos do provider:
                             --   uazapi: instance_name, instance_token, webhook_token
                             --   covercut: phone_number_id, waba_id, api_key, api_secret, webhook_secret
  timestamps
```

Sem unique constraint de "1 por tipo por tenant" — é uma regra de aplicação (hoje: no máximo 1 não-oficial + 1 oficial por tenant), não uma trava de schema. Isso evita uma migration futura para desfazer a constraint quando o multi-número for implementado.

### 3.2 Alterações em `tickets_atendimento`

```
+ whatsapp_canal_id     FK -> whatsapp_canais (nullable durante a migração, depois obrigatório)
+ janela_expira_em      timestamp, nullable   -- só usado quando canal.tipo === 'oficial'
+ janela_origem_anuncio boolean, default false -- true = janela de 72h (veio de anúncio), false = 24h
```

Cada ticket aponta para o canal específico que está sendo usado — a resolução de "qual canal enviar" nunca depende de uma suposição de singularidade no tenant, e sim do canal já registrado no ticket. Isso já é naturalmente compatível com multi-número futuro.

### 3.3 Alterações em `mensagens`

```
uazapi_message_id  →  provider_message_id
```

Generalização do campo de deduplicação de eventos de webhook, hoje nomeado especificamente para Uazapi, para servir aos dois provedores.

### 3.4 Estratégia de migração (produção já usa Uazapi ativamente)

Em duas etapas, seguindo a mesma cautela do fluxo de deploy já documentado no `CLAUDE.md` do projeto (histórico de quebra de produção por migration mal planejada em julho/2026):

1. **Migration aditiva**: cria `whatsapp_canais`, popula 1 linha por tenant existente (`tipo = nao_oficial`, `provider = uazapi`) copiando os campos atuais (`uazapi_instance_token`, `uazapi_webhook_token`, `whatsapp_status`, `whatsapp_phone`, `whatsapp_connected_since`). Os campos antigos em `tenants` continuam existindo e sendo lidos — nada quebra.
2. **Migration de limpeza** (só depois de validar em produção): remove os campos antigos de `tenants`, e os serviços que hoje leem `$tenant->uazapi_instance_token` diretamente passam a resolver o canal:
   - Quando há um ticket em mãos: via `$ticket->canal` (já aponta para a linha certa).
   - Quando não há ticket (ex: geração de QR Code, importação de contatos, sincronização de agenda): via um método explícito e comentado como escolha temporária de hoje (ex: "pega o canal não-oficial mais recente do tenant"), não uma relação `hasOne` permanente — para não bakear a suposição de 1:1 na arquitetura.

---

## 4. Regra de negócio: janela de conversa

- Toda mensagem inbound recebida pelo canal oficial atualiza `janela_expira_em` do ticket correspondente:
  - `+24h` por padrão.
  - `+72h` se o payload do webhook trouxer dados de referral de anúncio (ex: `ctwa_clid` / objeto `referral` do Cloud API) — nesse caso `janela_origem_anuncio = true`.
- Cada nova mensagem do lead **reinicia** a contagem (recalcula `janela_expira_em` a partir de agora).
- Qualquer tentativa de envio pelo canal oficial passa por uma checagem prévia: se `now() > janela_expira_em`, o envio é **bloqueado** — sem fallback de template, sem retry automático. Loga o bloqueio e sinaliza o ticket para atenção humana (mesmo espírito de "não tentar reconectar automaticamente" já usado hoje para instância Uazapi desconectada).
- Não há, nesta entrega, nenhuma tela de configuração de templates — está fora de escopo porque a decisão de negócio é não usar templates.

---

## 5. Camada de serviço

Introduzir uma interface pequena, `CanalWhatsappInterface`, com os métodos que hoje o `UazapiService` expõe para envio/presença (`enviarTexto`, `enviarMenuBotoes`, `setPresenca`, `status`), implementada por:

- `UazapiChannelService` — wrapper fino do `UazapiService` existente (comportamento inalterado).
- `CovercutChannelService` — novo, fala com a Cloud API da Meta através dos endpoints da Covercut (`X-API-Key`/`X-API-Secret`, base `https://api.covercut.com.br/api/v1`), e aplica a checagem de janela (seção 4) antes de qualquer envio.

`HumanizacaoService`, `SdrResponderService` e demais consumidores passam a resolver o canal do ticket (`$ticket->canal`) e delegar à implementação correta, em vez de instanciar `UazapiService` diretamente.

---

## 6. Webhook de entrada

Novo `CovercutWebhookController`, análogo ao `UazapiWebhookController` existente, mas:
- Autenticação por assinatura HMAC-SHA256 (`webhook_secret` armazenado em `whatsapp_canais.config`), em vez do token opaco na URL usado pela Uazapi.
- Parser próprio para o formato de payload da Cloud API/Covercut (diferente do formato Uazapi).

A lógica de negócio comum — criar/atualizar `Contato` e `TicketAtendimento`, salvar `Mensagem`, disparar SDR, avançar kanban — é extraída do `UazapiWebhookController` para um serviço compartilhado, chamado pelos dois controllers de webhook com o payload já normalizado. Evita duplicar a lógica de negócio entre os dois canais.

---

## 7. Fluxo de conexão (UI)

Na página atual de configurações (`configuracoes/whatsapp.blade.php`):

- A seção existente do QR Code ganha um rótulo explícito: **"WhatsApp Não-Oficial"** — conexão direta via QR Code (Baileys), para deixar claro ao usuário que este número não tem garantias da Meta.
- Nova seção abaixo: **"WhatsApp Oficial (Business API)"**, com:
  - Card de status (desconectado / conectado + telefone), no mesmo padrão visual da seção existente.
  - Botão "Conectar número oficial", que abre o widget embutido da Covercut (janela customizável deles → fluxo de Embedded Signup da Meta, conforme descrito no manual `2026-07-25-cadastro-whatsapp-oficial-manual.md`, seção 2).

### 7.1 Dependência técnica em aberto

A documentação pública da Covercut (`api.covercut.com.br/docs`) **não detalha** o mecanismo exato de retorno do widget embutido: como o `phone_number_id`/`waba_id` chegam de volta ao Lead Certo depois que o número conecta (webhook específico? redirect com parâmetros? postMessage?). Isso precisa ser confirmado com o Sandro (contato Covercut) ou na documentação privada de parceiro **antes** de iniciar a implementação desta seção. Não deve ser assumido/adivinhado no plano de implementação — é um bloqueador a resolver primeiro.

---

## 8. Fora de escopo (combinado nesta rodada)

- Templates de mensagem (marketing/utilidade) para reengajamento fora da janela.
- Múltiplos números não-oficiais por tenant (UI de gerenciar vários) — capacidade futura.
- Múltiplos números oficiais por tenant — não bloqueado no schema, mas sem UI nesta entrega.
- Migração completa para fora da Uazapi — Uazapi continua sendo a via de prospecção.

---

## 9. Referências

- Manual funcional: `docs/superpowers/specs/2026-07-25-cadastro-whatsapp-oficial-manual.md`
- Regras de humanização/anti-ban (canal não-oficial): `leadcerto-whatsapp-regras/regra-geral-de-envio-de-mensagens-no-whatsapp.md`
- Documentação técnica Covercut: `api.covercut.com.br/docs`
