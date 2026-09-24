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
        'app',
        'perfil_aquecimento',
        'aquecimento_iniciado_em',
        'status',
        'phone',
        'connected_since',
        'webhook_token',
        'config',
    ];

    protected $casts = [
        'connected_since'         => 'datetime',
        'config'                  => 'array',
        'aquecimento_iniciado_em' => 'datetime',
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

    /**
     * Fase 3 do plano do canal WhatsApp Messenger próprio (23/09): o
     * microserviço (leadcerto/integracoes/whatsapp-proprio) não usa token
     * por instância como a Uazapi — a autenticação é uma chave só,
     * compartilhada, configurada no .env do Laravel. O que identifica QUAL
     * conexão usar é o sessionId, salvo aqui do mesmo jeito que o
     * instance_token da Uazapi.
     */
    public function sessionIdMessengerProprio(): ?string
    {
        return $this->config['session_id'] ?? null;
    }

    public function servico(): \App\Services\Canais\CanalWhatsappInterface
    {
        return match ($this->provider) {
            'covercut'          => app(\App\Services\Canais\CovercutChannelService::class),
            'messenger_proprio' => app(\App\Services\Canais\MessengerProprioChannelService::class),
            default             => app(\App\Services\Canais\UazapiChannelService::class),
        };
    }
}
