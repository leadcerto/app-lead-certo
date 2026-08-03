# Lead Certo — Regras de Desenvolvimento

## Stack

- Laravel 13 · PHP 8.4 · MySQL 8 · Alpine.js v3 · Tailwind CSS
- VPS: `app.leadcerto.app.br` · SSH: `root@103.199.186.134` · Chave: `~/.ssh/leadcerto_vps`
- Repositório GitHub: `https://github.com/leadcerto/app-lead-certo.git`

## Regra fundamental: paridade entre canais WhatsApp (Uazapi + Covercut)

O Lead Certo tem **dois canais WhatsApp em produção, ao mesmo tempo, para o mesmo
tenant**: `UazapiChannelService`/`UazapiWebhookController` (não oficial, QR code) e
`CovercutChannelService`/`CovercutWebhookController` (oficial, Meta Cloud API).
Qualquer funcionalidade de sincronização de conversa — criação de mensagem, edição
de ticket, processamento de mídia, transferência bot↔humano, o que for — **tem que
existir e se comportar igual nos dois canais**. Um cliente não pode ter uma
experiência diferente (ou pior) dependendo de qual canal está conectado.

**Por quê:** em 2026-08-03 uma mensagem de orçamento sumiu do card porque o
`UazapiWebhookController` descartava silenciosamente mensagens enviadas pelo
atendente direto no app do celular (`fromMe && !viaApi`) sem `mediaType`
reconhecido. Ao corrigir, percebemos que o `CovercutWebhookController` tinha o
**mesmo problema, só que pior**: ele ignorava por completo qualquer mensagem
enviada pelo atendente via WhatsApp Business App no modo Coexistence
(`event: echo`, `direction: outbound`, `echo_source: phone`) — a funcionalidade
nem existia nesse lado. Se tivéssemos corrigido só o Uazapi, o mesmo buraco
continuaria aberto no canal oficial sem ninguém notar.

**Como aplicar:**

1. Antes de fechar qualquer tarefa que mexe no webhook, no envio de mensagem ou em
   qualquer fluxo de sincronização de um dos canais, pare e pergunte: *"isso
   também precisa acontecer no outro canal?"* — a resposta quase sempre é sim.
2. Ache o método equivalente no outro controller/service e confirme que o
   comportamento está espelhado. Os pares principais:
   - `UazapiWebhookController::handleMensagem/processarMensagemLead/transferirParaHumano`
     ↔ `CovercutWebhookController::processarMensagem/processarMensagemHumana`
   - `UazapiChannelService` ↔ `CovercutChannelService` (implementam
     `CanalWhatsappInterface`)
3. Se a paridade genuinamente não se aplica (ex.: botão interativo e chamada de voz
   não existem na API oficial; janela de 24h/72h só existe no canal oficial),
   **documente o porquê no código** — não deixe a ausência parecer um esquecimento.
4. Ao escrever teste para um comportamento novo num canal, escreva (ou confirme que
   já existe) o teste espelhado no outro canal na mesma tarefa — não numa tarefa
   futura.
5. Antes de implementar algo no canal oficial, consulte
   `api.covercut.com.br/docs/#configuracao` (ver [[referencia-docs-covercut]] na
   memória) — o formato de payload muda entre provedores e suposições por analogia
   já causaram bug (ex.: achar que `message.text` é sempre um objeto `{body}`).

## Fluxo obrigatório de deploy

**NUNCA altere arquivos diretamente na VPS.** O fluxo é sempre:

```
local → git commit → ./deploy.sh
```

### Sequência de deploy

```bash
# 1. Commit local
git add <arquivos>
git commit -m "mensagem"

# 2. Deploy (push + trava de segurança + pull + migrate + cache)
./deploy.sh
```

`deploy.sh` (na raiz do repo) faz tudo: push pro GitHub, checa se a VPS está limpa
(aborta se houver qualquer arquivo editado direto lá), `git pull`, `migrate --force`
e rebuild dos caches. **Não faça deploy manual via ssh** — o script existe justamente
para não depender de lembrar cada passo (foi assim que migrations pararam de rodar
e a VPS divergiu do git em julho/2026).

### Por que isso importa

Qualquer arquivo criado ou editado diretamente na VPS fica "fora do git". Na próxima vez que se fizer `git pull`, o pull falha por conflito — ou pior, `git clean -fd` remove o arquivo sem aviso. Isso já causou perda de código em produção (junho 2025) e quebrou funcionalidades em produção por divergência silenciosa entre VPS e git (julho 2026 — drag-and-drop do kanban e fila de mensagens pararam de funcionar porque uma migration nunca tinha sido escrita e uma coluna de banco foi criada direto na VPS). `deploy.sh` existe para tornar esse erro impossível: ele recusa o deploy se a VPS não estiver idêntica ao git.

### Se `deploy.sh` abortar por VPS suja

```bash
ssh -i ~/.ssh/leadcerto_vps root@103.199.186.134 "cd /var/www/leadcerto && git status"
```

Investigue o que mudou antes de decidir: trazer para o git (commit local) ou descartar (`git checkout -- <arquivo>`, com cuidado).

## Convenções do projeto

### Telefones

Formato canônico: `55DDXXXXXXXX` (sem espaços, sem hífen, sem parênteses).  
Celular: 13 dígitos. Fixo: 12 dígitos.

Comando para normalizar/mesclar duplicatas:
```bash
php artisan contatos:normalizar-telefones
```

### Multi-tenant

Todos os models de tenant usam `TenantScope` como global scope. Nunca fazer queries globais sem considerar o escopo do tenant.

Exceção: `Contato` é global (compartilhado entre tenants), isolado pelo `VinculoContatoTenant`.

### Models com tabelas explícitas

- `SequenciaMensagem` → `$table = 'sequencia_mensagens'`
- `Sequencia` → `$table = 'sequencias'`

Sempre declarar `$table` quando o nome do model em snake_case pluralizado pelo Laravel não bater com o nome real da tabela.

### Queue

Driver: `database`. Jobs com delay usam `->delay(now()->addSeconds(N))`.

Rodar workers na VPS:
```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

### Token do WhatsApp/Uazapi

Sempre resolva o token via `$ticket->canal->tokenUazapi()` (ou `$canal->tokenUazapi()`
quando já tem o canal em mãos) — nunca `$tenant->uazapi_instance_token` diretamente.
Os campos legados em `tenants` (`uazapi_instance_token`, `uazapi_webhook_token`,
`uazapi_instance_name`, `whatsapp_status`, `whatsapp_phone`, `whatsapp_connected_since`)
ainda existem no banco por um período de transição, mas são obsoletos e serão
removidos numa limpeza futura — não leia deles em código novo.

### Rotas

- `api/painel/*` — JSON API (retorna `JsonResponse`)
- Rotas web — retornam views

### Nomes de rotas importantes

| Rota | Controller | View |
|------|-----------|------|
| `kanban` | `KanbanController@view` | `kanban.index` |
| `kanban.config` | — | `kanban.config` |
| `kanban.variaveis` | — | `kanban.variaveis` |
| `contatos` | `ContatosController@view` | `contatos.index` |
| `contatos.importar` | `ContatosController@importar` | `contatos.importar` |
| `sequencia` | — | removida (usar `kanban.config`) |
