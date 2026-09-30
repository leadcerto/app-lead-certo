<?php

namespace App\Console\Commands;

use App\Models\ChamadaPerdida;
use App\Models\TicketAtendimento;
use Illuminate\Console\Command;

/**
 * Pendência registrada em 22/09 (Leonardo): Caso B de spam/telemarketing na
 * Secretária Eletrônica — número TEM WhatsApp, a mensagem de abertura foi
 * enviada com sucesso, mas o lead nunca respondeu. Padrão típico de quem
 * liga sem intenção real de falar com a empresa (quem liga de verdade
 * responde). Complementa `numero_invalido` (Caso A: número sem WhatsApp).
 */
class MarcarChamadasSpamSemRespostaCommand extends Command
{
    protected $signature = 'chamadas:marcar-spam-sem-resposta {--dias=3 : Dias sem resposta do lead pra considerar spam}';
    protected $description = 'Marca como provável spam as chamadas perdidas cuja mensagem de abertura foi enviada mas o lead nunca respondeu';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');

        $chamadas = ChamadaPerdida::withoutGlobalScopes()
            ->where('mensagem_enviada', true)
            ->where('numero_invalido', false)
            ->where('provavel_spam_sem_resposta', false)
            ->where('mensagem_enviada_em', '<=', now()->subDays($dias))
            ->whereNotNull('ticket_id')
            ->get();

        $marcados = 0;

        foreach ($chamadas as $chamada) {
            $ticket = TicketAtendimento::withoutGlobalScopes()->find($chamada->ticket_id);

            if ($ticket && $ticket->ultima_mensagem_lead_em === null) {
                $chamada->update(['provavel_spam_sem_resposta' => true]);
                $marcados++;
            }
        }

        $this->info("{$marcados} chamada(s) marcada(s) como provável spam (sem resposta após {$dias} dia(s)).");

        return self::SUCCESS;
    }
}
