<?php

namespace App\Jobs;

use App\Models\Formulario;
use App\Models\FormularioEnvio;
use App\Models\TicketAtendimento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FormularioLeadJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        private int $envioId,
        private int $ticketId,
    ) {}

    public function handle(): void
    {
        $envio = FormularioEnvio::with('formulario.tenant')->find($this->envioId);

        if (! $envio) {
            Log::warning("FormularioLeadJob: envio #{$this->envioId} não encontrado");
            return;
        }

        $formulario = $envio->formulario;
        $ticket     = TicketAtendimento::withoutGlobalScopes()
            ->with(['contato', 'canal'])
            ->find($this->ticketId);

        if (! $ticket || ! $formulario) {
            return;
        }

        $telefone = $ticket->contato?->telefone;
        $canal    = $ticket->canal;

        // Achado real (auditoria de paridade Uazapi/Covercut, 2026-08-04): este job
        // resolvia $ticket->canal?->tokenUazapi() direto, que é sempre null pra um
        // canal Covercut — um lead de formulário cujo ticket resolvesse pro canal
        // oficial falhava aqui silenciosamente (nem o bot_sdr chegava a disparar).
        // Roteando por $canal->servico()->enviarTexto() (mesmo padrão já usado em
        // SequenciaMensagemJob/FollowupConversas/SdrResponderJob), os dois
        // provedores funcionam sem exigir token Uazapi explicitamente aqui.
        if (! $telefone || ! $canal) {
            Log::warning("FormularioLeadJob: sem telefone ou canal", ['envio' => $this->envioId]);
            return;
        }

        if ($formulario->double_optin) {
            // Double opt-in: envia confirmação antes de disparar o bot
            $canal->servico()->enviarTexto(
                $canal,
                $telefone,
                "Olá! Recebemos seu cadastro. ✅\n\nResponda *SIM* para confirmar que foi você mesmo que preencheu."
            );

            $envio->update(['processado' => true]);
            return;
        }

        if ($formulario->acao_pos_envio === 'mensagem_unica' && $formulario->mensagem_custom) {
            $canal->servico()->enviarTexto(
                $canal,
                $telefone,
                $formulario->mensagem_custom
            );

            $ticket->update(['agente_responsavel' => 'humano']);
            $envio->update(['processado' => true]);
            return;
        }

        // bot_sdr: dispara o João normalmente
        SdrResponderJob::dispatch($this->ticketId)->onQueue('default');
        $envio->update(['processado' => true]);
    }
}
