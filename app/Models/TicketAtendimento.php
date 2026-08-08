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
                // Bloco 5 — default agora é 'sistema' (política automática:
                // auto-mover, webhook, botões), não 'ia'. 'ia' só é gravado
                // quando SdrResponderService marca explicitamente, no único
                // ponto onde a própria IA decide mover a coluna em tempo
                // real (ver SdrResponderService.php, token de movimento).
                $origem = $ticket->origemMudancaColuna ?? 'sistema';

                KanbanColunaHistorico::create([
                    'tenant_id'       => $ticket->tenant_id,
                    'ticket_id'       => $ticket->id,
                    'coluna'          => $ticket->coluna_kanban,
                    'coluna_anterior' => $colunaAnterior,
                    'entrou_em'       => now(),
                    'origem'          => $origem,
                ]);

                static::alertarSeMigracaoAtipica($ticket, $colunaAnterior, $origem);
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
                // Bloco 5 — fecha o alerta pendente ANTES de limpar o campo
                // abaixo, senão a pausa "desapareceria" sem deixar rastro de
                // por que o alerta nunca foi respondido de verdade.
                if ($ticket->aguardando_orientacao_em) {
                    app(\App\Services\AlertaInternoService::class)->fecharDuvidaPendente(
                        $ticket->tenant_id,
                        $ticket->id,
                        'Mudou de coluna antes de receber orientação — pausa descartada.',
                    );
                }

                $ticket->aguardando_orientacao_em = null;
                $ticket->mensagem_espera_enviada  = false;
            }
        });
    }

    /**
     * Bloco 5 — decide se a IA encerrou a coluna anterior com o checklist de
     * objetivos completo. Usado só pra suprimir o alerta de migração atípica
     * em fechamentos rotineiros da IA (ver alertarSeMigracaoAtipica()) — um
     * fechamento com objetivos pendentes é o caso que a Regra 13 realmente
     * quer sinalizar, não o mero fato de ter fechado via token.
     *
     * Por essa altura (dentro de updated(), disparado pelo mesmo ->update()
     * que já rodou o hook updating() acima), $ticket->objetivos_cumpridos já
     * foi zerado pelo reset automático de coluna — por isso lê getOriginal(),
     * que ainda reflete o que estava marcado ANTES desta mudança de coluna.
     *
     * Coluna sem nenhum objetivo configurado (Frente 1 da base de
     * conhecimento é opt-in) não tem o que julgar como incompleto — decisão
     * de produto confirmada: trata como cumprido, não alerta.
     */
    private static function objetivosCumpridosAoEncerrar(self $ticket, string $colunaAnterior): bool
    {
        $idsExigidos = \App\Models\KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $colunaAnterior)
            ->where('ativo', true)
            ->pluck('id')
            ->all();

        if (empty($idsExigidos)) {
            return true;
        }

        $cumpridos = $ticket->getOriginal('objetivos_cumpridos') ?? [];

        return empty(array_diff($idsExigidos, $cumpridos));
    }

    /**
     * Regra 13 (Bloco 4) — migração atípica: movida manualmente por um
     * humano e/ou pulando mais de uma posição na ordem das colunas. Só
     * alerta, nunca bloqueia a movimentação (decisão de produto fechada —
     * evita travar um caso legítimo, ex. pular direto pra Encerrado, por
     * engano). Se os dois motivos se aplicarem ao mesmo evento, gera um
     * alerta só, não dois.
     *
     * Ordem desconhecida pra qualquer um dos dois lados (coluna sem registro
     * em kanban_colunas pro tenant — comum em testes e em tenants com
     * chaves de coluna sem cadastro formal) significa que o salto não pode
     * ser calculado: assume que não houve salto, não bloqueia nem falso-alarma.
     *
     * Colunas de papel Encerramento ou TransferenciaHumana não fazem parte
     * da ordem "normal" do funil — são desvios de fluxo, não etapas
     * sequenciais. Fluxos automáticos de altíssima frequência passam por
     * elas rotineiramente e produzem distância ordinal grande sem que isso
     * seja uma migração atípica de verdade: encerramento automático por
     * silêncio (FollowupConversas) pula de qualquer coluna intermediária
     * direto pro Encerramento, e reabertura de ticket (webhooks Uazapi/
     * Covercut) volta do Encerramento pra uma coluna bem anterior. Contar
     * esses saltos geraria ruído puro em operação normal, contrariando a
     * decisão de produto de só alertar o que é de fato incomum. Essa
     * exclusão vale só pra origem 'sistema' (Bloco 5). Origem 'ia' fechando
     * pra Encerramento via token só é suprimida quando o checklist de
     * objetivos da coluna anterior estava completo (ver
     * objetivosCumpridosAoEncerrar()) — um fechamento com objetivos
     * pendentes é o "engano" real que a Regra 13 quer sinalizar, não o mero
     * fato de ter fechado. Origem 'humano' continua alertando independente
     * do papel envolvido.
     */
    private static function alertarSeMigracaoAtipica(self $ticket, ?string $colunaAnterior, string $origem): void
    {
        if ($colunaAnterior === null) {
            return; // entrada inicial (criação do ticket), não é uma migração
        }

        $ordemAntes  = \App\Models\KanbanColuna::ordemDe($ticket->tenant_id, $colunaAnterior);
        $ordemDepois = \App\Models\KanbanColuna::ordemDe($ticket->tenant_id, $ticket->coluna_kanban);

        $papelAntes  = \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $colunaAnterior);
        $papelDepois = \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $ticket->coluna_kanban);
        $papelForaDaOrdem = fn (?\App\Enums\PapelColunaKanban $papel) => $papel === \App\Enums\PapelColunaKanban::Encerramento
            || $papel === \App\Enums\PapelColunaKanban::TransferenciaHumana;

        // Bloco 5 — a supressão de salto vira uma decisão por origem:
        // 'sistema' continua suprimido perto de Encerramento/TransferenciaHumana
        // (rotina de auto-fechamento/reabertura, ruído puro). 'ia' fechando
        // via token pra Encerramento só é suprimido se o checklist de
        // objetivos da coluna anterior estava completo — fechamento incompleto
        // é o "engano" real que vale alertar.
        $saltoSuprimido = match ($origem) {
            'sistema' => $papelForaDaOrdem($papelAntes) || $papelForaDaOrdem($papelDepois),
            'ia'      => $papelDepois === \App\Enums\PapelColunaKanban::Encerramento
                && static::objetivosCumpridosAoEncerrar($ticket, $colunaAnterior),
            default   => false,
        };

        $pulou = $ordemAntes !== null && $ordemDepois !== null
            && abs($ordemDepois - $ordemAntes) > 1
            && ! $saltoSuprimido;

        if ($origem !== 'humano' && ! $pulou) {
            return;
        }

        $motivos = [];
        if ($origem === 'humano') {
            $motivos[] = 'movida manualmente por um atendente';
        }
        if ($pulou) {
            $motivos[] = "pulou de \"{$colunaAnterior}\" direto pra \"{$ticket->coluna_kanban}\"";
        }

        try {
            app(\App\Services\AlertaInternoService::class)->criar(
                $ticket->tenant_id,
                'migracao_atipica',
                'Migração atípica de coluna',
                'O ticket foi ' . implode(' e ', $motivos) . '.',
                $ticket->id,
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('TicketAtendimento: erro ao criar alerta de migração atípica', [
                'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
            ]);
        }
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
        'tentativas_envio_falhas',
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
            'tentativas_envio_falhas' => 'integer',
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
