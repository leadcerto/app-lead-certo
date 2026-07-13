<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketAtendimento extends Model
{
    protected $table = 'tickets_atendimento';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'contato_id',
        'coluna_kanban',
        'coluna_antes_encerrar',
        'agente_responsavel',
        'sdr_persona_id',
        'vendedor_id',
        'etapa_ia',
        'endereco_saida',
        'endereco_destino',
        'lista_itens',
        'followup_enviado',
        'tag_desfecho',
        'followup_agendado_em',
        'retorno_agendado_em',
        'status',
        'aberto_em',
        'encerrado_em',
        'origem',
        'formulario_id',
        'resumo_ia',
        'botoes_ativos',
        'followup_estagio_enviado',
        'pendente_desde',
    ];

    protected function casts(): array
    {
        return [
            'followup_enviado' => 'boolean',
            'aberto_em' => 'datetime',
            'encerrado_em' => 'datetime',
            'followup_agendado_em'  => 'datetime',
            'retorno_agendado_em'   => 'datetime',
            'botoes_ativos'         => 'array',
            'followup_estagio_enviado' => 'integer',
            'pendente_desde'        => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contato(): BelongsTo
    {
        return $this->belongsTo(Contato::class, 'contato_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(SdrPersona::class, 'sdr_persona_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class, 'ticket_id')->orderBy('enviado_em');
    }

    /**
     * Monta os campos pra encerrar o ticket guardando a coluna em que ele estava,
     * pra poder voltar pra lá se o lead reabrir a conversa depois — independente
     * de quem encerrou (humano, silêncio automático ou a própria IA).
     * Não sobrescreve a coluna guardada se o ticket já estava encerrado.
     */
    public function dadosParaEncerrar(array $extra = []): array
    {
        $updates = array_merge($extra, [
            'coluna_kanban' => 'encerrado',
            'status'        => 'encerrado',
        ]);

        if ($this->coluna_kanban !== 'encerrado') {
            $updates['coluna_antes_encerrar'] = $this->coluna_kanban;
        }

        return $updates;
    }
}
