<?php
// app/Models/AlertaInterno.php
namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertaInterno extends Model
{
    protected $table = 'alertas_internos';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'tipo',
        'titulo',
        'conteudo',
        'lido_em',
    ];

    protected $casts = [
        'lido_em' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketAtendimento::class, 'ticket_id');
    }
}
