<?php

namespace App\Models;

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
    }

    protected $fillable = [
        'ticket_id',
        'tenant_id',
        'remetente',
        'tipo',
        'conteudo',
        'midia_url',
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
