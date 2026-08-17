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
 * E-mail de alerta manual disparado pelo Admin.
 * Enviado para avaliadores que possuem tarefas pendentes/enviadas na semana.
 */
class AlertaAvaliadorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $avaliador,
        public Collection $tarefas,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '📋 Você tem avaliações pendentes — Lead Certo',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.gmb.alerta-avaliador',
            with: [
                'avaliador' => $this->avaliador,
                'tarefas'   => $this->tarefas,
            ],
        );
    }
}
