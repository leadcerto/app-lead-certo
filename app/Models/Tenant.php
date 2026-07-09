<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'nome',
        'nicho',
        'status',
        'email',
        'dominio',
        'telefone',
        'whatsapp_status',
        'whatsapp_phone',
        'whatsapp_connected_since',
        'uazapi_instance_name',
        'uazapi_instance_token',
        'uazapi_webhook_token',
        'secretaria_token',
        'secretaria_mensagem_inicial',
        'ia_contexto',
        'tabela_precos_pdf_path',
        'tabela_precos_texto',
        'sdr_ativo',
        'retencao_conversas_dias',
    ];

    protected $casts = [
        'sdr_ativo'               => 'boolean',
        'retencao_conversas_dias' => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketAtendimento::class);
    }

    public function personas(): HasMany
    {
        return $this->hasMany(SdrPersona::class);
    }
}
