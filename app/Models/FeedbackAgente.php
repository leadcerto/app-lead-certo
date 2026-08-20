<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackAgente extends Model
{
    protected $table = 'feedbacks_agente';

    protected $fillable = ['user_id', 'tenant_id', 'autor_user_id', 'mensagem', 'resposta'];

    /**
     * Resposta padrão sempre dada na hora — deliberadamente simples (pedido
     * do Leonardo 2026-08-20): não é a IA de atendimento analisando nada,
     * é só confirmar que o recado foi recebido e vai ser discutido.
     */
    public const RESPOSTA_PADRAO = 'Obrigado pelo seu feedback! Anotei tudo aqui e vou levar esse assunto '
        . 'pra nossa próxima reunião de equipe, pra a gente estudar com calma e ver os próximos passos. 🙏';

    public function agente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
