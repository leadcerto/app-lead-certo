<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChamadaPerdida extends Model
{
    protected $table = 'chamadas_perdidas';

    protected $fillable = [
        'tenant_id',
        'contato_id',
        'ticket_id',
        'numero_chamador',
        'numero_receptor',
        'chamou_em',
        'duracao_segundos',
        'mensagem_enviada',
        'mensagem_enviada_em',
        'origem_app',
    ];

    protected $casts = [
        'chamou_em'           => 'datetime',
        'mensagem_enviada_em' => 'datetime',
        'mensagem_enviada'    => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }
}
