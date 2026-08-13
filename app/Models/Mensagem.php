<?php

namespace App\Models;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mensagem extends Model
{
    protected $table = 'mensagens';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        // Achado real (2026-08-13): quando é um humano que conduz a conversa
        // manualmente (não a IA), nada observava o que ele escreveu pra
        // atualizar a checklist de objetivos da coluna — o ticket nunca
        // avançava sozinho nesse caminho, só quando a própria IA respondia.
        // Hook único aqui (em vez de em cada controller de webhook/painel)
        // cobre os três canais de mensagem humana de uma vez — regra
        // fundamental de paridade entre canais do CLAUDE.md.
        static::created(function (Mensagem $mensagem) {
            if ($mensagem->remetente !== 'humano') {
                return;
            }

            $ticket = TicketAtendimento::withoutGlobalScopes()->find($mensagem->ticket_id);
            if (! $ticket) {
                return;
            }

            $config = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->first();

            if (! $config?->ia_ativo) {
                return;
            }

            $idsAtivos = KanbanColunaObjetivo::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->where('ativo', true)
                ->pluck('id');

            if ($idsAtivos->isEmpty()) {
                return;
            }

            $jaCumpridos = collect($ticket->objetivos_cumpridos ?? []);
            if ($idsAtivos->diff($jaCumpridos)->isEmpty()) {
                return; // checklist já completa, nada pendente
            }

            AvaliarObjetivosPorMensagemHumanaJob::dispatch($ticket->id);
        });
    }

    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'remetente',
        'tipo',
        'conteudo',
        'midia_url',
        'provider_message_id',
        'enviado_em',
    ];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class);
    }
}
