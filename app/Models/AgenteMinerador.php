<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AgenteMinerador extends Model
{
    protected $table = 'agentes_mineradores';

    protected $fillable = [
        'tenant_id', 'campanha_id', 'nome', 'tipo',
        'api_key_prefix', 'api_key_hash',
        'escopo', 'configuracoes', 'status',
        'ultima_execucao_em', 'contatos_importados',
    ];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return [
            'escopo'              => 'array',
            'configuracoes'       => 'array',
            'ultima_execucao_em'  => 'datetime',
            'contatos_importados' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(CampanhaMineracao::class, 'campanha_id');
    }

    /**
     * Gera uma nova API key, retorna o valor em texto claro (só neste momento)
     * e salva o hash no banco.
     *
     * @return string  A chave em texto claro — exibir UMA VEZ para o usuário
     */
    public static function gerarApiKey(): array
    {
        $raw    = 'mk_' . Str::random(40);
        $prefix = substr($raw, 0, 8);
        $hash   = hash('sha256', $raw);

        return [
            'raw'    => $raw,
            'prefix' => $prefix,
            'hash'   => $hash,
        ];
    }

    /**
     * Localiza o agente pela chave em texto claro.
     */
    public static function encontrarPorChave(string $rawKey): ?self
    {
        $prefix = substr($rawKey, 0, 8);
        $hash   = hash('sha256', $rawKey);

        return self::where('api_key_prefix', $prefix)
                   ->where('api_key_hash', $hash)
                   ->where('status', 'ativo')
                   ->first();
    }
}
