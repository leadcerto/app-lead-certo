# SPEC-013 — Política da Janela de Atendimento da Meta (24 Horas) — WhatsApp Business API Oficial

**Data de Elaboração:** 02/09/2026  
**Status:** Implementado e Homologado  
**Canal Aplicável:** WhatsApp Business API Oficial (Meta Cloud API / CoverCut)

---

## 1. Regra Oficial da Meta (WhatsApp Cloud API)

A Meta estabelece uma política estrita de **Janela de Atendimento ao Cliente (Customer Service Window)** para mensagens de formato livre (texto, áudio, mídia e IA):

### A. Como Funciona o Início da Janela
* A janela de atendimento de **24 horas** é iniciada no instante em que o cliente (**Lead**) envia qualquer mensagem para o número da empresa.
* Durante essas 24 horas, a empresa (seja via Agente de IA, sequências automáticas ou humano pelo Kanban) pode enviar **mensagens livres** de qualquer tipo sem custos adicionais de Message Templates.

### B. Renovação Contínua da Janela (Rolling 24-Hour Window)
> [!IMPORTANT]
> **A Janela NÃO fica presa ao horário da primeira mensagem!**
> **Toda e qualquer nova mensagem enviada pelo lead RENOVA o prazo por mais 24 horas a partir do timestamp da mensagem mais recente recebida.**
> *Exemplo:* Se o lead mandou mensagem às 08:00 (janela até 08:00 do dia seguinte), e às 17:00 ele mandou outra mensagem, o sistema **estende a janela automaticamente para as 17:00 do dia seguinte**.

### C. Anúncios de Clique para WhatsApp (CTWA - 72 Horas)
* Quando o primeiro contato do lead é originado de um **Anúncio da Meta (Click-to-WhatsApp Ads / Referral)**, a Meta concede uma janela de atendimento estendida de **72 horas gratuitas**.
* O webhook da CoverCut detecta o payload de anúncio (`referral` ou `ctwa_clid`) e ajusta `janela_expira_em = now() + 72 horas`.

### D. Encerramento da Janela (Após 24h de Inatividade do Lead)
* Se transcorrerem 24 horas sem que o lead envie uma nova mensagem, a janela se **fecha**.
* **Bloqueio Determinístico:** A Meta Cloud API **rejeita** qualquer mensagem livre após o fechamento da janela (gerando erro e exigindo Template pré-aprovado tarifado).
* **Proteção no Sistema:** O sistema Lead Certo verifica `ticket.janela_expira_em` antes de qualquer envio da IA ou sequência (`CovercutChannelService::dentroDaJanela()`). Se a janela estiver expirada, o envio automático é **bloqueado deterministicamente**, evitando cobranças indevidas ou falhas de entrega.

---

## 2. Arquitetura de Implementação no Lead Certo

### 1. Atualização do Timestamp no Inbound (`CovercutWebhookController`)
A cada mensagem recebida do lead pelo canal oficial, o controller executa:
```php
$temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
$janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

$ticket->update([
    'whatsapp_canal_id'     => $canal->id,
    'janela_expira_em'      => $janelaExpiraEm,
    'janela_origem_anuncio' => $temReferralAnuncio,
]);
```

### 2. Validação Prévia de Envio (`CovercutChannelService`)
Antes de disparar mensagens para a CoverCut/Meta, o serviço executa:
```php
private function dentroDaJanela(WhatsappCanal $canal, string $telefone): bool
{
    $ticket = TicketAtendimento::withoutGlobalScopes()
        ->where('tenant_id', $canal->tenant_id)
        ->where('whatsapp_canal_id', $canal->id)
        ->whereHas('contato', fn ($q) => $q->where('telefone', $telefone))
        ->whereIn('status', ['aberto', 'aguardando'])
        ->latest()
        ->first();

    if ($ticket && $ticket->janela_expira_em && now()->greaterThan($ticket->janela_expira_em)) {
        Log::warning('CovercutChannelService: envio bloqueado, janela de conversa expirada', [
            'canal_id'   => $canal->id,
            'ticket_id'  => $ticket->id,
            'expirou_em' => $ticket->janela_expira_em->toIso8601String(),
        ]);
        return false;
    }

    return true;
}
```

### 3. Garantia no Envio de Sequências e SDR
* **`SdrResponderJob`:** Bloqueia automaticamente a IA se `now() > $ticket->janela_expira_em`.
* **`SequenciaMensagemJob`:** Não tenta forçar disparos de sequências fora da janela no canal oficial.
* **`FollowupConversas`:** Respeita a expiração da janela ao avaliar avanços de coluna e auto-mover.

---

## 3. Resumo de Diretrizes para o Agente de IA e Atendimento Humano

| Evento | Comportamento da Janela | Ação da IA / Sistema |
|---|---|---|
| **Lead envia 1ª mensagem** | Abre janela de 24h | IA/Sequência responde normalmente |
| **Lead responde qualquer mensagem** | **Renova +24h do momento atual** | IA continua conversação ativa |
| **Lead veio de Anúncio Meta (CTWA)** | Abre janela de 72h | IA/Sequência tem 3 dias de janela livre |
| **Passaram 24h sem mensagem do lead** | Janela fechada | IA bloqueia envio livre; aguarda novo contato ou intervenção humana |
