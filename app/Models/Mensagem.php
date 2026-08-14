<?php

namespace App\Models;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Jobs\IdentificarNomeConversaJob;
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

        static::created(function (Mensagem $mensagem) {
            match ($mensagem->remetente) {
                'humano' => static::avaliarObjetivosSeHumanoAssumiu($mensagem),
                'lead'   => static::identificarNomeSeAindaInvalido($mensagem),
                default  => null,
            };
        });
    }

    /**
     * Achado real (2026-08-13): quando é um humano que conduz a conversa
     * manualmente (não a IA), nada observava o que ele escreveu pra
     * atualizar a checklist de objetivos da coluna — o ticket nunca
     * avançava sozinho nesse caminho, só quando a própria IA respondia.
     * Hook único aqui (em vez de em cada controller de webhook/painel)
     * cobre os três canais de mensagem humana de uma vez — regra
     * fundamental de paridade entre canais do CLAUDE.md.
     */
    private static function avaliarObjetivosSeHumanoAssumiu(Mensagem $mensagem): void
    {
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
    }

    /**
     * Achado real (2026-08-14): quando o lead se identifica pelo próprio
     * nome dentro da conversa (texto ou áudio transcrito), sem o bot ter
     * perguntado diretamente, nada capturava isso — o contato ficava com o
     * telefone como nome (placeholder da criação) até alguém corrigir
     * manualmente. Hook único aqui, mesmo padrão do bloco de objetivos
     * acima — cobre qualquer canal de entrada de mensagem do lead.
     */
    private static function identificarNomeSeAindaInvalido(Mensagem $mensagem): void
    {
        $ticket = TicketAtendimento::withoutGlobalScopes()->find($mensagem->ticket_id);
        if (! $ticket) {
            return;
        }

        $contato = Contato::find($ticket->contato_id);
        if (! $contato || ! $contato->semNomeReal()) {
            return;
        }

        IdentificarNomeConversaJob::dispatch($mensagem->id);
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
