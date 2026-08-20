<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackAgente extends Model
{
    protected $table = 'feedbacks_agente';

    protected $fillable = [
        'user_id', 'cargo_id', 'tenant_id', 'autor_user_id', 'mensagem', 'midia_url', 'tipo_midia', 'resposta',
        'status', 'relatorio_analise', 'implementacao_faz_sentido',
        'tempo_estimado_execucao', 'empresas_beneficiadas_estimado',
    ];

    protected function casts(): array
    {
        return ['implementacao_faz_sentido' => 'boolean'];
    }

    /**
     * Resposta padrão sempre dada na hora (2026-08-20, redesenho por
     * setor): não é a IA de atendimento analisando nada em tempo real — só
     * confirma o recebimento e explica o processo real por trás (vira um
     * caso, é analisado, decisão de implementar considera quantas empresas
     * se beneficiariam, não só o pedido de uma empresa só).
     */
    public const RESPOSTA_PADRAO = 'Obrigado pela mensagem! Registrei os detalhes e vou aprofundar aqui internamente — '
        . 'vamos analisar se faz sentido implementar, o tempo estimado e quantas empresas se beneficiariam, '
        . 'já que buscamos sempre atender a necessidade da maioria de quem usa o sistema. Qualquer novidade, te retornamos.';

    public function agente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_user_id');
    }
}
