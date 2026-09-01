<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmbPostImagem extends Model
{
    protected $table = 'gmb_post_imagens';

    protected $fillable = [
        'tenant_id',
        'titulo',
        'palavras_chave',
        'imagem_url',
        'nome_arquivo_original',
        'nome_arquivo_seo',
        'tamanho_bytes',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
