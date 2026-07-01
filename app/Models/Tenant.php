<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'nome',
        'nicho',
        'status',
        'whatsapp_status',
        'whatsapp_phone',
        'whatsapp_connected_since',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(TicketAtendimento::class);
    }
}
