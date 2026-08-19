<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappGrupoPost extends Model
{
    protected $table = 'whatsapp_grupo_posts';

    public $timestamps = false;

    protected $fillable = [
        'whatsapp_canal_id',
        'grupo_chatid',
        'conteudo',
        'postado_em',
    ];

    protected $casts = [
        'postado_em' => 'datetime',
    ];

    public function canal(): BelongsTo
    {
        return $this->belongsTo(WhatsappCanal::class, 'whatsapp_canal_id');
    }
}
