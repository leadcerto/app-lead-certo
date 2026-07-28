<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WhatsappCanal extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_canais';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'tipo',
        'provider',
        'status',
        'phone',
        'connected_since',
        'webhook_token',
        'config',
    ];

    protected $casts = [
        'connected_since' => 'datetime',
        'config'          => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function kanbans(): BelongsToMany
    {
        return $this->belongsToMany(Kanban::class, 'kanban_whatsapp_canais');
    }

    public function tokenUazapi(): ?string
    {
        return $this->config['instance_token'] ?? null;
    }
}
