<?php

namespace App\Jobs;

use App\Models\Formulario;
use App\Models\FormularioEnvio;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
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

    public function handle(HumanizacaoService $humanizacao): void
    {
        $envio = FormularioEnvio::with('formulario.tenant')->find($this->envioId);

        if (! $envio) {
            Log::warning("FormularioLeadJob: envio #{$this->envioId} não encontrado");
            return;
        }

        $formulario = $envio->formulario;
        $ticket     = TicketAtendimento::withoutGlobalScopes()
            ->with('contato')
            ->find($this->ticketId);

        if (! $ticket || ! $formulario) {
            return;
        }

        $tenant   = $formulario->tenant;
        $telefone = $ticket->contato?->telefone;

        if (! $telefone || ! $tenant?->uazapi_instance_token) {
            Log::warning("FormularioLeadJob: sem telefone ou token Uazapi", ['envio' => $this->envioId]);
            return;
        }

        if ($formulario->double_optin) {
            // Double opt-in: envia confirmação antes de disparar o bot
            $humanizacao->processar(
                $tenant->uazapi_instance_token,
                $telefone,
                "Olá! Recebemos seu cadastro. ✅\n\nResponda *SIM* para confirmar que foi você mesmo que preencheu."
            );

            $envio->update(['processado' => true]);
            return;
        }

        if ($formulario->acao_pos_envio === 'mensagem_unica' && $formulario->mensagem_custom) {
            $humanizacao->processar(
                $tenant->uazapi_instance_token,
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
