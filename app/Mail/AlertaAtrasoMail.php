<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * E-mail urgente individual enviado ao avaliador que está em atraso.
 * Disparado automaticamente pelo CRON AlertarAtrasoCommand.
 * Lista as empresas que o avaliador esqueceu de avaliar.
 */
class AlertaAtrasoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $avaliador,
        public Collection $agendamentosAtrasados,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🚨 URGENTE: Avaliações em atraso — Lead Certo',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.gmb.alerta-atraso',
            with: [
                'avaliador'   => $this->avaliador,
                'agendamentos'=> $this->agendamentosAtrasados,
            ],
        );
    }
}
