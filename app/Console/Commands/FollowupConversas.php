<?php

namespace App\Console\Commands;

use App\Jobs\ConversationQAJob;
use App\Jobs\GerarResumoTicketJob;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use App\Services\SdrResponderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupConversas extends Command
{
    protected $signature = 'conversas:followup
                            {--dry-run : Mostra o que faria sem enviar}';

    protected $description = 'Envia follow-up para leads que pararam de responder (10min = reaquecimento, estágios 1/2/3 = reengajamento por silêncio, auto-mover = transferência automática de coluna, tudo configurável por coluna)';

    public function handle(SdrResponderService $sdr, HumanizacaoService $humanizacao): int
    {
        $dry = $this->option('dry-run');

        $enviados = 0;

        // ── Follow-up CURTO (10 min) ─────────────────────────────────────────
        // Última mensagem é do lead, enviada entre 10 min e 90 min atrás
        // Usamos a última mensagem pelo id máximo (compatível com only_full_group_by)
        $curtos = DB::table('tickets_atendimento as t')
            ->join(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em, m1.remetente as ultimo_remetente
                FROM mensagens m1
                INNER JOIN (
                    SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id
                ) m2 ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->where('t.agente_responsavel', 'bot')
            ->whereNotIn('t.etapa_ia', ['handoff'])
            ->where('t.status', 'aberto')
            ->where('ultima.ultimo_remetente', 'lead')
            ->whereBetween('ultima.ultima_em', [now()->subMinutes(90), now()->subMinutes(10)])
            ->select('t.id', 't.tenant_id')
            ->get();

        $this->info("Follow-up curto (10min): {$curtos->count()} tickets");

        foreach ($curtos as $row) {
            $ticket = TicketAtendimento::withoutGlobalScopes()
                ->with(['contato', 'mensagens', 'persona', 'tenant'])
                ->find($row->id);

            if (! $ticket) continue;

            // Achado real (Leonardo, 2026-07-30): desativar "Agente ativo nesta
            // coluna" só bloqueava a resposta ao vivo (SdrResponderJob já checava
            // isso) — o follow-up continuava disparando por chamar responder()
            // direto. Mesmo gate aplicado aqui.
            $configCurto = KanbanColunaConfig::withoutGlobalScopes()
                ->where('tenant_id', $ticket->tenant_id)
                ->where('coluna_kanban', $ticket->coluna_kanban)
                ->first();

            if (! $configCurto?->ia_ativo) {
                continue;
            }

            $this->line("  ↺ [curto] #{$ticket->id} — {$ticket->contato?->nome}");

            if (! $dry) {
                try {
                    $sdr->responder($ticket, gatilho: 'vacuo_10m');
                    $enviados++;
                } catch (\Exception $e) {
                    Log::warning('FollowupConversas: erro no curto', ['ticket_id' => $row->id, 'erro' => $e->getMessage()]);
                }
            }
        }

        // ── Estágios de silêncio (1/2/3) + Auto-mover de coluna ───────────────
        // Silêncio = tempo desde a última mensagem da conversa (de qualquer
        // remetente) até agora. Os limites de cada estágio, e o limite de
        // auto-mover, são configuráveis por coluna (kanban_coluna_configs).
        // Cada ticket só dispara um estágio de mensagem se ainda não tiver
        // disparado esse estágio (ou um maior). O auto-mover é independente
        // dos estágios de mensagem — dispara sozinho quando configurado.
        $horaAtual          = now()->hour;
        $emHorarioComercial = $horaAtual >= 8 && $horaAtual < 20;

        $configsPorColuna   = [];
        $estagiosDisparados = ['1' => 0, '2' => 0, '3' => 0];
        $janelaMetaDisparados = 0;
        $autoMovidos        = 0;

        // Avaliação contínua (24 horas por dia) de todos os tickets abertos.
        // Silêncio do lead é contado prioritariamente a partir da última mensagem enviada pelo LEAD
        // (t.ultima_mensagem_lead_em ou u_lead.ultima_lead_em), garantindo que mensagens de sequências
        // ou do bot não reiniciem indevidamente o contador de silêncio do cliente.
        $candidatos = DB::table('tickets_atendimento as t')
            ->leftJoin(DB::raw('(
                SELECT m1.ticket_id, m1.enviado_em as ultima_em
                FROM mensagens m1
                INNER JOIN (SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id) m2
                ON m1.id = m2.max_id
            ) as ultima'), 'ultima.ticket_id', '=', 't.id')
            ->leftJoin(DB::raw('(
                SELECT ml.ticket_id, MAX(ml.enviado_em) as ultima_lead_em
                FROM mensagens ml
                WHERE ml.remetente = "lead"
                GROUP BY ml.ticket_id
            ) as u_lead'), 'u_lead.ticket_id', '=', 't.id')
            ->where('t.status', 'aberto')
            ->select(
                't.id',
                't.tenant_id',
                't.coluna_kanban',
                't.followup_estagio_enviado',
                't.followup_enviado',
                't.janela_expira_em',
                't.agente_responsavel',
                't.etapa_ia',
                't.ultima_mensagem_lead_em',
                DB::raw('COALESCE(t.ultima_mensagem_lead_em, u_lead.ultima_lead_em, ultima.ultima_em, t.aberto_em) as ultima_lead_ou_geral'),
                DB::raw('COALESCE(ultima.ultima_em, t.aberto_em) as ultima_geral')
            )
            ->get();

        foreach ($candidatos as $row) {
            $chaveConfig = "{$row->tenant_id}:{$row->coluna_kanban}";
            if (! isset($configsPorColuna[$chaveConfig])) {
                $configsPorColuna[$chaveConfig] = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $row->tenant_id)
                    ->where('coluna_kanban', $row->coluna_kanban)
                    ->first();
            }
            $config = $configsPorColuna[$chaveConfig];

            // Silêncio real: prioriza a última interação do lead
            $marcoSilencio = $row->ultima_lead_ou_geral ?? $row->ultima_geral;
            $silencioSegundos = now()->diffInSeconds(Carbon::parse($marcoSilencio), absolute: true);

            $ticket = null; // carregado sob demanda, só se alguma ação for aplicável

            // ── Estágios de mensagem (1/2/3) — só pra ticket com o bot no controle ──
            if ($emHorarioComercial && $row->agente_responsavel === 'bot' && $row->etapa_ia !== 'handoff' && $row->followup_estagio_enviado < 3) {
                $limite1 = $config?->followup_estagio1_segundos ?? 3600;
                $limite2 = $config?->followup_estagio2_segundos ?? 7200;
                $limite3 = $config?->followup_estagio3_segundos ?? 21600;

                $estagioAlvo = match (true) {
                    $silencioSegundos >= $limite3 => 3,
                    $silencioSegundos >= $limite2 => 2,
                    $silencioSegundos >= $limite1 => 1,
                    default => 0,
                };

                if ($estagioAlvo > 0 && $estagioAlvo > $row->followup_estagio_enviado && $config?->ia_ativo) {
                    $ticket ??= TicketAtendimento::withoutGlobalScopes()
                        ->with(['contato', 'mensagens', 'persona', 'tenant'])
                        ->find($row->id);

                    if ($ticket) {
                        if ($ticket->tentativas_envio_falhas >= 3) {
                            $this->line("  ⚠ [envio travado] #{$ticket->id} — {$ticket->contato?->nome}");

                            if (! $dry && $ticket->tentativas_envio_falhas === 3) {
                                try {
                                    app(\App\Services\AlertaInternoService::class)->criar(
                                        $ticket->tenant_id,
                                        'envio_falhou',
                                        'Não consegui entregar a mensagem',
                                        'O canal recusou o envio 3 vezes seguidas (ex: janela de conversa expirada). Parei de tentar automaticamente — confira o ticket.',
                                        $ticket->id,
                                    );
                                    $ticket->increment('tentativas_envio_falhas');
                                } catch (\Exception $e) {
                                    Log::warning('FollowupConversas: erro ao alertar envio travado', [
                                        'ticket_id' => $ticket->id, 'erro' => $e->getMessage(),
                                    ]);
                                }
                            }
                        } else {
                            $this->line("  ↺ [estágio {$estagioAlvo}] #{$ticket->id} — {$ticket->contato?->nome}");

                            if (! $dry) {
                                try {
                                    $respostaEnviada = $sdr->responder($ticket, gatilho: "estagio_{$estagioAlvo}");
                                    if ($respostaEnviada !== null) {
                                        $ticket->update(['followup_estagio_enviado' => $estagioAlvo]);
                                        $estagiosDisparados[(string) $estagioAlvo]++;
                                        $enviados++;
                                    }
                                } catch (\Exception $e) {
                                    Log::warning('FollowupConversas: erro no estágio', [
                                        'ticket_id' => $row->id, 'estagio' => $estagioAlvo, 'erro' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            // ── Gatilho de Janela Meta (< 6h) ──────────────────────────────
            // Disparado no horário comercial quando a janela oficial da Meta
            // está a menos de 6 horas de fechar e o lead está em silêncio (>= 1h).
            // Envia um follow-up matinal/preventivo para reengajar o lead antes
            // que a janela expire.
            $janelaExpiraEm = $row->janela_expira_em ? Carbon::parse($row->janela_expira_em) : null;
            $segundosAteExpirarJanela = $janelaExpiraEm ? now()->diffInSeconds($janelaExpiraEm, false) : null;

            if ($emHorarioComercial
                && $row->agente_responsavel === 'bot'
                && $row->etapa_ia !== 'handoff'
                && $config?->ia_ativo
                && ! $row->followup_enviado
                && $segundosAteExpirarJanela !== null
                && $segundosAteExpirarJanela > 0
                && $segundosAteExpirarJanela <= (6 * 3600)
                && $silencioSegundos >= 3600
            ) {
                $ticket ??= TicketAtendimento::withoutGlobalScopes()
                    ->with(['contato', 'mensagens', 'persona', 'tenant'])
                    ->find($row->id);

                if ($ticket && $ticket->tentativas_envio_falhas < 3 && ! $ticket->aguardando_orientacao_em) {
                    $this->line("  ⏳ [janela meta < 6h] #{$ticket->id} — {$ticket->contato?->nome} (expira em: " . round($segundosAteExpirarJanela / 3600, 1) . "h)");

                    if (! $dry) {
                        try {
                            $respostaEnviada = $sdr->responder($ticket, gatilho: 'janela_meta_6h');
                            if ($respostaEnviada !== null) {
                                $ticket->update(['followup_enviado' => true]);
                                $janelaMetaDisparados++;
                                $enviados++;
                            }
                        } catch (\Exception $e) {
                            Log::warning('FollowupConversas: erro no follow-up de janela meta', [
                                'ticket_id' => $row->id, 'erro' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // ── Auto-mover de coluna por silêncio (24h/7d) ───────────────────
            if ($config?->auto_mover_ativo && $config->auto_mover_coluna_destino
                && $config->auto_mover_coluna_destino !== $row->coluna_kanban
                && $silencioSegundos >= ($config->auto_mover_segundos ?? PHP_INT_MAX)
            ) {
                $ticket ??= TicketAtendimento::withoutGlobalScopes()
                    ->with(['contato', 'mensagens', 'persona', 'tenant'])
                    ->find($row->id);

                if ($ticket && $ticket->coluna_kanban === $row->coluna_kanban) {
                    $this->line("  → [auto-mover → {$config->auto_mover_coluna_destino}] #{$ticket->id} — {$ticket->contato?->nome} (silêncio: {$silencioSegundos}s / limite: {$config->auto_mover_segundos}s)");

                    if (! $dry) {
                        try {
                            $this->aplicarMovimentoAutomatico($ticket, $config->auto_mover_coluna_destino, $config->auto_mover_mensagem, $humanizacao);
                            $autoMovidos++;
                        } catch (\Exception $e) {
                            Log::warning('FollowupConversas: erro no auto-mover', [
                                'ticket_id' => $row->id, 'destino' => $config->auto_mover_coluna_destino, 'erro' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        $this->info("Estágio 1: {$estagiosDisparados['1']} · Estágio 2: {$estagiosDisparados['2']} · Estágio 3: {$estagiosDisparados['3']} · Janela Meta (<6h): {$janelaMetaDisparados} · Auto-movidos: {$autoMovidos}");
        $this->info("Total enviados: {$enviados}");
        if ($dry) $this->warn('DRY-RUN — nada foi enviado.');

        return Command::SUCCESS;
    }

    /**
     * Move o ticket automaticamente para $destino por silêncio prolongado.
     * Se $mensagem estiver preenchida, envia antes de mover (e registra no
     * histórico). Se o destino for uma coluna de papel Encerramento ou
     * TransferenciaHumana (independente da chave/nome da coluna), aplica os
     * mesmos efeitos que o fluxo manual equivalente (status/tag/relatórios de
     * IA, ou transferência pra humano) — pra não deixar o ticket num estado
     * inconsistente (coluna encerrada mas status ainda "aberto").
     */
    private function aplicarMovimentoAutomatico(TicketAtendimento $ticket, string $destino, ?string $mensagem, HumanizacaoService $humanizacao): void
    {
        if ($mensagem) {
            $telefone = $ticket->contato?->telefone;
            $canal    = $ticket->canal;

            // Achado Importante 5 da revisão final: resolvia o token via tokenUazapi()
            // direto, que é sempre null pra um canal Covercut — a despedida configurada
            // era silenciosamente descartada (sem log nenhum) e o ticket movia mesmo
            // assim. Roteando por $canal->servico()->enviarTexto(), o Covercut também
            // ganha de graça a checagem de janela de conversa (bloqueia se expirada).
            if ($telefone && $canal) {
                $contato     = $ticket->contato;
                $nomeContato = $contato?->nome;
                $temNome     = $contato && ! $contato->semNomeReal();
                $texto       = str_replace('{nome}', $temNome ? $nomeContato : '', $mensagem);
                if (! $temNome) {
                    $texto = preg_replace('/\{nome\},?\s*/u', '', $texto);
                    $texto = preg_replace('/(^|\s)Sem Nome,?\s*/iu', '$1', $texto);
                }

                $enviado = $canal->servico()->enviarTexto($canal, $telefone, $texto);

                if ($enviado) {
                    Mensagem::create([
                        'ticket_id'  => $ticket->id,
                        'tenant_id'  => $ticket->tenant_id,
                        'remetente'  => 'bot',
                        'tipo'       => 'texto',
                        'conteudo'   => $texto,
                        'enviado_em' => now(),
                    ]);
                } else {
                    Log::warning('FollowupConversas: envio da mensagem de auto-mover falhou ou foi bloqueado, ticket move sem enviar', [
                        'ticket_id' => $ticket->id,
                    ]);
                }
            } else {
                Log::warning('FollowupConversas: sem canal ou telefone, mensagem de auto-mover não enviada', [
                    'ticket_id' => $ticket->id,
                ]);
            }
        }

        $papelDestino = \App\Models\KanbanColuna::papelDe($ticket->tenant_id, $destino);

        if ($papelDestino === \App\Enums\PapelColunaKanban::Encerramento) {
            $ticket->update($ticket->dadosParaEncerrar([
                'tag_desfecho' => 'sem_resposta_automatico',
                'encerrado_em' => now(),
            ], $destino));
            ConversationQAJob::dispatch($ticket->id);
            GerarResumoTicketJob::dispatch($ticket->id)->delay(now()->addSeconds(5));
        } elseif ($papelDestino === \App\Enums\PapelColunaKanban::TransferenciaHumana) {
            $ticket->update([
                'coluna_kanban'      => $destino,
                'agente_responsavel' => 'humano',
            ]);
        } else {
            $ticket->update(['coluna_kanban' => $destino]);
        }

        Log::info("FollowupConversas: ticket #{$ticket->id} movido automaticamente por silêncio para '{$destino}'");
    }
}
