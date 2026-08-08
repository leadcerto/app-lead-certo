<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\KanbanColunaHistorico;

class TicketAtendimento extends Model
{
    protected $table = 'tickets_atendimento';

    /**
     * NÃO-PERSISTIDA — quem está causando a mudança de coluna neste update
     * específico. Setada só pelos dois endpoints de movimentação manual
     * (KanbanController::mover/moverParaOutros, Task 6) antes de chamar
     * ->update(). Lida pelo hook updated() abaixo pra gravar 'origem' no
     * histórico (Bloco 4, Regra 13) — sem isso o hook não teria como saber
     * quem iniciou a mudança, já que dezenas de pontos diferentes do código
     * chamam ->update(['coluna_kanban' => ...]).
     */
    public ?string $origemMudancaColuna = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::created(function (TicketAtendimento $ticket) {
            KanbanColunaHistorico::create([
                'tenant_id'       => $ticket->tenant_id,
                'ticket_id'       => $ticket->id,
                'coluna'          => $ticket->coluna_kanban,
                'coluna_anterior' => null,
                'entrou_em'       => now(),
            ]);
        });

        static::updated(function (TicketAtendimento $ticket) {
            if ($ticket->wasChanged('coluna_kanban')) {
                $colunaAnterior = $ticket->getOriginal('coluna_kanban');
                $origem = $ticket->origemMudancaColuna ?? 'ia';

                KanbanColunaHistorico::create([
                    'tenant_id'       => $ticket->tenant_id,
                    'ticket_id'       => $ticket->id,
                    'coluna'          => $ticket->coluna_kanban,
                    'coluna_anterior' => $colunaAnterior,
                    'entrou_em'       => now(),
                    'origem'          => $origem,
                ]);
            }
        });

        // Achado 2 da revisão final: objetivos_cumpridos é por coluna — se o
        // ticket muda de coluna sem que quem disparou o update tenha decidido
        // explicitamente o que fazer com o checklist antigo, ele tem que zerar
        // aqui, senão ids da coluna anterior vazam pro card na coluna nova.
        // Centralizado no model pra cobrir TODOS os caminhos de movimento
        // (drag-and-drop manual, botões do WhatsApp, followup automático,
        // webhook, token da IA) de uma vez só, em vez de replicar em cada um.
        static::updating(function (TicketAtendimento $ticket) {
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('objetivos_cumpridos')) {
                $ticket->objetivos_cumpridos = [];
            }
            // Regra 2 (Bloco 3): uma dúvida pausada é específica do contexto da
            // coluna atual — se o ticket muda de coluna enquanto aguarda
            // orientação (manual ou automático), a pausa não faz mais sentido.
            // Mesmo raciocínio do reset de objetivos_cumpridos acima.
            if ($ticket->isDirty('coluna_kanban') && ! $ticket->isDirty('aguardando_orientacao_em')) {
                $ticket->aguardando_orientacao_em = null;
                $ticket->mensagem_espera_enviada  = false;
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'contato_id',
        'whatsapp_canal_id',
        'janela_expira_em',
        'janela_origem_anuncio',
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
        'visualizado_em',
        'objetivos_cumpridos',
        'aguardando_orientacao_em',
        'mensagem_espera_enviada',
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
            'visualizado_em'        => 'datetime',
            'janela_expira_em'      => 'datetime',
            'janela_origem_anuncio' => 'boolean',
            'objetivos_cumpridos'   => 'array',
            'aguardando_orientacao_em' => 'datetime',
            'mensagem_espera_enviada'  => 'boolean',
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

    public function canal(): BelongsTo
    {
        return $this->belongsTo(WhatsappCanal::class, 'whatsapp_canal_id');
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
     * Nome de exibição pro atendente deste ticket — usado, por ex., pra
     * identificar quem "enviou" o eco automático de transcrição de áudio no
     * WhatsApp (UazapiWebhookController/CovercutWebhookController). Cai pra
     * persona padrão do tenant se o ticket não tiver uma associada, e por
     * fim pro rótulo genérico "Atendente" se nem isso existir.
     */
    public function nomePersonaDisplay(): string
    {
        return $this->persona?->nome_display
            ?? $this->tenant->personas()->where('is_default', true)->where('ativo', true)->value('nome_display')
            ?? 'Atendente';
    }

    /**
     * Monta os campos pra encerrar o ticket guardando a coluna em que ele estava,
     * pra poder voltar pra lá se o lead reabrir a conversa depois — independente
     * de quem encerrou (humano, silêncio automático ou a própria IA).
     * Não sobrescreve a coluna guardada se o ticket já estava encerrado.
     */
    public function dadosParaEncerrar(array $extra = [], ?string $colunaDestino = null): array
    {
        $colunaDestino ??= \App\Models\KanbanColuna::primeiraChaveComPapel($this->tenant_id, \App\Enums\PapelColunaKanban::Encerramento)
            ?? 'encerrado';

        $updates = array_merge($extra, [
            'coluna_kanban' => $colunaDestino,
            'status'        => 'encerrado',
        ]);

        if (\App\Models\KanbanColuna::papelDe($this->tenant_id, $this->coluna_kanban) !== \App\Enums\PapelColunaKanban::Encerramento) {
            $updates['coluna_antes_encerrar'] = $this->coluna_kanban;
        }

        return $updates;
    }
}
