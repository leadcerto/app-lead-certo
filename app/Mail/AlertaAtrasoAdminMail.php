<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Relatório gerencial de atrasos enviado ao Admin.
 * Coloca em CC os avaliadores inadimplentes para gerar urgência.
 * Disparado automaticamente pelo CRON AlertarAtrasoCommand.
 */
class AlertaAtrasoAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param Collection $resumoPorAvaliador Agendamentos atrasados agrupados por avaliador
     * @param array      $emailsCC          E-mails dos avaliadores inadimplentes para CC
     */
    public function __construct(
        public Collection $resumoPorAvaliador,
        public array $emailsCC = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '📊 Relatório de Atrasos — Avaliações GMB — Lead Certo',
            cc: array_map(fn($email) => new Address($email), $this->emailsCC),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.gmb.alerta-atraso-admin',
            with: [
                'resumo' => $this->resumoPorAvaliador,
            ],
        );
    }
}
