<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QaAuditoria extends Model
{
    protected $table = 'qa_auditorias';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'sdr_persona_id', 'confidence_score',
        'sugestoes_melhoria', 'requer_revisao_humana',
        'revisado_por', 'revisado_em', 'status', 'payload_avaliacao',
    ];

    protected function casts(): array
    {
        return [
            'requer_revisao_humana' => 'boolean',
            'payload_avaliacao'     => 'array',
            'criado_em'             => 'datetime',
            'revisado_em'           => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(SdrPersona::class, 'sdr_persona_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
