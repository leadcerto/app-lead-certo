# Paridade entre canais WhatsApp — Uazapi (não-oficial) vs Covercut (oficial)

**Contexto (2026-08-03/04):** o canal não-oficial (Uazapi) do Frete Rio ficou
desconectado desde o incidente do botão "Remover" em 29/07 e nunca foi
reconectado — confirmado via banco (`whatsapp_canais` sem nenhuma linha
`provider=uazapi`). Nesse intervalo, todo o sistema rodou só com o canal
Covercut (oficial). Isso descobriu 163 tickets travados sem canal algum (ver
[[feedback_paridade_canais_whatsapp]] na memória e o commit
`6b862e3`) e expôs várias funcionalidades que só existem do lado Uazapi.

Este documento lista **tudo que é exclusivo de um canal e não existe no
outro**, pra servir de checklist quando o número novo do Uazapi for
reconectado (ver regra fundamental em `CLAUDE.md`: toda função de
sincronização precisa existir nos dois canais — quando não existir, o motivo
tem que estar documentado, não ser um esquecimento).

## Legenda

- **(a) Uazapi-only** — não existe no Covercut, e não é possível existir por
  limitação da API oficial da Meta (ex.: grupos, agenda de contatos) OU
  simplesmente nunca foi construído do outro lado.
- **(b) Covercut-only** — não existe no Uazapi, geralmente por regra da Meta
  (janela de 24h, assinatura HMAC) que só se aplica ao canal oficial.
- **(c) Existe nos dois** — mas com comportamento ou caminho de código
  diferente, vale revisão.

---

## 1. Envio de mensagens (`CanalWhatsappInterface`)

`app/Services/Canais/UazapiChannelService.php` vs `CovercutChannelService.php`

Os 6 métodos da interface (`enviarTexto`, `enviarTextoDireto`, `enviarImagem`,
`enviarAudio`, `enviarDocumento`, `enviarSticker`) **estão implementados nos
dois lados** — a superfície pública é simétrica.

| Comportamento | Uazapi | Covercut |
|---|---|---|
| Humanização (balões, "digitando...", delay) | ✅ via `HumanizacaoService` | ❌ nunca — envio imediato, 1 mensagem só |
| Janela de conversa 24h/72h | ❌ nunca bloqueia | ✅ bloqueia envio fora da janela (`dentroDaJanela()`) |
| `enviarAudio($ptt=true)` | sempre `ptt` | só marca `voice:true` se URL terminar em `.ogg` |

**(c)** Ao reconectar: respostas do bot pelo Uazapi voltam a ter delay e
divisão em balões — comportamento visível pro lead que estava ausente
enquanto só o Covercut respondia. Não é bug, é esperado.

## 2. Webhook — mensagens recebidas

`UazapiWebhookController.php` (797 linhas) vs `CovercutWebhookController.php` (418 linhas)

### Só no Uazapi (a)

| Funcionalidade | Local |
|---|---|
| Botão interativo clicado (`buttonOrListid`) | `UazapiWebhookController.php:188-207` → `KanbanBotaoActionService` |
| Chamada de voz perdida (`messageType` contém `call`) | `UazapiWebhookController.php:147-152, 402-453` |
| Evento de conexão (`open`/`close`/`connecting`/`timeout`) | `UazapiWebhookController.php:783-796` — **Covercut não tem nenhum evento de status de conexão**; uma vez criado, o canal Covercut nunca mais é atualizado por webhook |
| Fallback de autenticação legado (`tenants.uazapi_webhook_token`) | `UazapiWebhookController.php:33-55` — dívida técnica marcada `TODO(Task 15)` |
| Placeholder `[Mensagem sem conteúdo reconhecido]` | `UazapiWebhookController.php:748-762` — Covercut só loga warning e descarta, decisão deliberada (`CovercutWebhookController.php:339-350`) |
| Deduplicação de álbum de imagens ("Album: N images") | `UazapiWebhookController.php:128-134` — conceito que só existe no formato Uazapi |
| **Reabertura de ticket encerrado via IA** (`deveReabrirTicketEncerrado`) | `UazapiWebhookController.php:226-262, 573-594` — **⚠️ assimetria mais importante**: um lead que volta a escrever depois de encerrado se comporta diferente dependendo do canal. No Covercut, mensagem pra ticket encerrado simplesmente não reabre. |
| Extração progressiva de nome do texto (`extrairNomeDaTexto`) | `UazapiWebhookController.php:333-342, 519-563` |
| Detecção de origem por link rastreado (`detectarOrigem`) | `UazapiWebhookController.php:171-172, 628-657` |
| Validação de `pushName` (filtra "~Deus", números, etc.) | `UazapiWebhookController.php:169, 492-512` |

### Só no Covercut (b)

| Funcionalidade | Local | Por quê |
|---|---|---|
| Assinatura HMAC do webhook | `CovercutWebhookController.php:65-69, 85-96` | Uazapi só usa token opaco na URL |
| Janela de conversa 24h/72h persistida no ticket | `CovercutWebhookController.php:117, 133-137, 151-152` | Regra da Meta |

## 3. Botões interativos — `KanbanBotaoActionService`

**(a) Exclusivo do Uazapi, sem qualquer caminho pro Covercut.**

- `enviarBotoes()` chama `UazapiService::enviarMenuBotoes()` diretamente — não
  passa pela abstração `CanalWhatsappInterface`.
- `SequenciaMensagemJob.php:115-127`: se a sequência é pro Covercut e tem
  botões ou imagem configurados, **a mensagem inteira é pulada** (não
  degrada pra texto simples) — log: `'Sequência: mídia/botões não suportados
  no canal oficial, mensagem pulada'`.
- Recepção do clique (`executar()`) só é chamada de
  `UazapiWebhookController` — nada equivalente no Covercut.

**⚠️ Ação recomendada:** checar se havia sequências com botão/imagem
configuradas neste tenant — leads atendidos só pelo Covercut durante a
queda **não receberam essas etapas**, mensagem inteira foi pulada em
silêncio.

## 4. `UazapiService.php` — métodos sem equivalente Covercut

| Método Uazapi | Covercut |
|---|---|
| `criarInstancia()`, `conectar()` (QR), `status()`, `listarInstancias()` | (a) sem equivalente — Covercut não usa QR/polling, marca `connected` na criação |
| `enviarMenuBotoes()` | (a) sem equivalente — ver seção 3 |
| `setPresenca()` | (a) sem equivalente — usado só por `HumanizacaoService` |
| `configurarWebhook()` / `getWebhook()` | (a) sem equivalente reutilizável — Covercut configura embutido no `store()` |
| `listarGrupos()` | (a) sem equivalente — grupos não existem na API oficial da Meta |
| `listarContatos()` | (a) sem equivalente — API oficial não expõe agenda do celular |
| `enviarImagem()` **dentro de `SequenciaMensagemJob.php:172`** | (a) chamado direto no Uazapi, ignorando a interface — no Covercut a mensagem inteira é pulada (mesma assimetria da seção 3) |

## 5. Humanização — `HumanizacaoService.php`

**Confirmado exclusivo do Uazapi** — o construtor injeta `UazapiService`
diretamente (não a interface genérica). Chamado por
`UazapiChannelService::enviarTexto()` e por `SequenciaMensagemJob.php:186,204`
(que resolve o token Uazapi diretamente, sem passar pela interface). Covercut
nunca passa por humanização, por design.

## 6. Mídia — `MediaProcessorService.php`

**(c) Cobertura simétrica nos tipos suportados** (image/audio/video/document
nos dois lados; nenhum dos dois trata sticker recebido), mas o mecanismo de
download é estruturalmente diferente:

- Uazapi precisa descriptografar mídia E2E do WhatsApp (HKDF-SHA256 +
  AES-256-CBC) com fallback pro endpoint próprio da Uazapi
  (`baixarMidiaDoUazapi()`) — **este endpoint está marcado como "instável/
  quebrado desde 01/07" no próprio comentário do código** (linha ~489).
- Covercut recebe mídia já descriptografada da Meta — um único caminho via
  `baixarMidiaCovercut()`.
- Vídeo e documento são só placeholder textual nos dois lados — paridade
  correta, proposital (sem análise real de conteúdo nesses dois tipos).
- `extrairTranscricaoBruta()` é compartilhado pelos dois lados (não há
  duplicação aqui).

**⚠️ Ação recomendada:** ao reconectar o número novo, confirmar se o
endpoint de download da Uazapi voltou a funcionar, já que ele é o fallback
quando a descriptografia direta via `mediaKey` falhar.

## 7. Reconciliação de desconexão — gap real, nunca implementado

Spec: `docs/superpowers/specs/2026-07-21-reconciliacao-whatsapp-desconexao-design.md`

**Este design nunca foi implementado.** Confirmado por busca no código: não
existem `IniciarReconciliacaoWhatsAppJob`, `ReconciliarChatJob`, tratamento
do `EventType=history`, nem as colunas que o design previa
(`whatsapp_desconectado_desde`, `ultima_reconciliacao_verificada_em`,
`recuperado_via_reconciliacao`). O `handleConexao()` real só atualiza
`status`/`connected_since` do canal — exatamente o problema que o design
original descrevia, ainda sem solução.

O mecanismo desenhado (mas não construído) previa: gatilho por gap ≥ 5min de
desconexão → reconciliação via `POST /chat/find` + `/message/find` +
`/message/history-sync` da Uazapi → cria contato/ticket/mensagem
retroativamente sem disparar o bot → badge "🕓 Recuperado — revisar" no
ticket → varredura periódica de segurança a cada 30min.

**⚠️ Isso significa: não há recuperação automática das mensagens da semana
de queda, e se cair de novo por qualquer motivo no futuro, o sistema segue
sem recuperar nada sozinho.** Vale decidir manualmente, antes de reconectar,
se compensa tentar recuperar o histórico da última semana via alguma
consulta pontual à Uazapi.

## 8. Sincronização de agenda/contatos e grupos

`app/Console/Commands/SincronizarContatosWhatsApp.php`,
`app/Jobs/SincronizarAgendaWhatsAppJob.php`,
`app/Console/Commands/ImportarParticipantesGrupos.php`

**(a) 100% exclusivos do Uazapi — e estruturalmente impossíveis no Covercut**
(a API oficial da Meta não expõe agenda de contatos nem grupos do celular).
Não é uma lacuna a corrigir, é limitação da API oficial.

- `SincronizarContatosWhatsApp` filtra explicitamente `tipo='nao_oficial'`.
- `SincronizarAgendaWhatsAppJob` dispara sozinho 10s depois que
  `WhatsappCanalController::status()` detecta conexão bem-sucedida — **isso
  vai disparar de novo automaticamente quando o número novo conectar**,
  importando a agenda do celular novo pro CRM. Como é número novo, a agenda
  será diferente da anterior — confirmar se isso é desejado.

## 9. Outros achados de assimetria

- **`FormularioLeadJob.php:44-49`** resolve `$ticket->canal?->tokenUazapi()`
  direto e loga erro se ausente, **sem checar se o canal é Covercut**
  (diferente do padrão já usado em `SequenciaMensagemJob`/`FollowupConversas`,
  que checam `$canal->provider === 'covercut'` antes de exigir token Uazapi).
  Um lead de formulário cujo ticket resolver pro canal Covercut pode falhar
  silenciosamente aqui — **mesma categoria de bug que motivou o
  `RepararTicketsSemCanalCommand`, vale investigar/corrigir**.
- **`WhatsappCanalController.php`** (fluxo de criar instância, QR code, poll
  de status, deletar) é o controller usado pra reconectar o número novo —
  fluxo estruturalmente diferente de `WhatsappCanalOficialController.php`
  (Covercut não tem QR nem polling).
- Campos legados em `Tenant` (`uazapi_instance_name`, `uazapi_instance_token`,
  `uazapi_webhook_token`, `whatsapp_status`, `whatsapp_phone`,
  `whatsapp_connected_since`) já são obsoletos por decisão do CLAUDE.md — não
  ler deles em código novo, mesmo depois da reconexão.

---

## Checklist prático para quando o número novo conectar

1. [ ] Decidir se vale tentar recuperar manualmente o histórico da semana de
   queda antes de reconectar (seção 7 — não há automação pra isso).
2. [ ] Depois de conectar, checar sequências com botão/imagem configuradas —
   confirmar quais leads atendidos só pelo Covercut durante a queda não
   receberam essas etapas (seção 3).
3. [ ] Rodar `php artisan whatsapp:reparar-tickets-sem-canal --dry-run` pra
   confirmar que não sobrou ticket sem canal na transição.
4. [ ] Confirmar que `SincronizarAgendaWhatsAppJob` (dispara sozinho 10s após
   conectar) está importando a agenda certa do número novo (seção 8).
5. [ ] Testar envio de áudio/imagem recebido de verdade — confirmar se o
   endpoint de download da Uazapi (`baixarMidiaDoUazapi`) voltou a funcionar
   (seção 6).
6. [ ] Observar se a humanização (delay, "digitando...") volta a se
   comportar bem — não deve travar em "composing" (seção 1).
7. [ ] Investigar `FormularioLeadJob` quanto à checagem de canal Covercut
   (seção 9) — corrigir se for o mesmo tipo de bug do canal ausente.
8. [ ] Tickets já abertos hoje no canal Covercut **não migram sozinhos** de
   volta pro Uazapi — só trocam quando chega mensagem nova pelo outro canal
   (comportamento esperado, não é bug).
