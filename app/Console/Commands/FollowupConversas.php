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
        $autoMovidos        = 0;

        // Achado real (Leonardo, 2026-08-13): o auto-mover por silêncio está
        // configurado e ativo (ex: 24h/48h/72h → encerrado) mas nunca disparava
        // pra nenhum ticket assumido por um humano — o filtro `agente_responsavel
        // = 'bot'' abaixo cortava TODOS eles fora da lista de candidatos antes de
        // sequer chegar na checagem de tempo. Auto-mover é um "escape" pra ticket
        // parado independente de quem devia responder (humano esqueceu, bot
        // encerrou controle, etc.) — não faz sentido restringir só ao bot. Os
        // Estágios de mensagem (nudge ao lead) continuam bot-only logo abaixo,
        // porque não faz sentido o bot "cutucar" o lead enquanto um humano já
        // assumiu a conversa.
        // Achado real (Leonardo, 2026-08-16): tickets sem NENHUMA mensagem (ex:
        // origem='ligacao' — chamada perdida que abre um ticket mas nunca gera
        // uma mensagem de texto) ficavam de fora desta lista pra sempre, porque
        // o INNER JOIN com a subquery de "última mensagem" simplesmente não
        // encontrava nenhuma linha pra eles. Resultado: 23 tickets da coluna
        // "Novo" (alguns com mais de 3 semanas parados) nunca eram avaliados
        // nem pro nudge de estágio nem pro auto-mover — ficavam abertos pra
        // sempre. Trocado pra LEFT JOIN + COALESCE(última mensagem, aberto_em)
        // — sem mensagem nenhuma, a "última atividade" passa a ser a abertura
        // do ticket, que é o marco correto de silêncio nesse caso.
        $candidatos = $emHorarioComercial
            ? DB::table('tickets_atendimento as t')
                ->leftJoin(DB::raw('(
                    SELECT m1.ticket_id, m1.enviado_em as ultima_em
                    FROM mensagens m1
                    INNER JOIN (SELECT ticket_id, MAX(id) as max_id FROM mensagens GROUP BY ticket_id) m2
                    ON m1.id = m2.max_id
                ) as ultima'), 'ultima.ticket_id', '=', 't.id')
                ->whereNotIn('t.etapa_ia', ['handoff'])
                ->where('t.status', 'aberto')
                ->select('t.id', 't.tenant_id', 't.coluna_kanban', 't.followup_estagio_enviado', 't.agente_responsavel', DB::raw('COALESCE(ultima.ultima_em, t.aberto_em) as ultima_em'))
                ->get()
            : collect();

        foreach ($candidatos as $row) {
            $chaveConfig = "{$row->tenant_id}:{$row->coluna_kanban}";
            if (! isset($configsPorColuna[$chaveConfig])) {
                $configsPorColuna[$chaveConfig] = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $row->tenant_id)
                    ->where('coluna_kanban', $row->coluna_kanban)
                    ->first();
            }
            $config = $configsPorColuna[$chaveConfig];

            $silencioSegundos = now()->diffInSeconds(Carbon::parse($row->ultima_em), absolute: true);

            $ticket = null; // carregado sob demanda, só se alguma ação for aplicável

            // ── Estágios de mensagem (1/2/3) — só pra ticket com o bot no controle ──
            if ($row->agente_responsavel === 'bot' && $row->followup_estagio_enviado < 3) {
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
                        // Bloco 5 — depois de 3 falhas seguidas de envio (canal
                        // recusando, ex: janela expirada), para de chamar a IA
                        // pra esse ticket nesse ciclo e alerta uma vez só.
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
                                    // Sobe pra 4 só pra não repetir o alerta no próximo ciclo
                                    // sem mexer no contador real de falhas do envio em si.
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

            // ── Auto-mover de coluna por silêncio ─────────────────────────────
            if ($config?->auto_mover_ativo && $config->auto_mover_coluna_destino
                && $config->auto_mover_coluna_destino !== $row->coluna_kanban
                && $silencioSegundos >= ($config->auto_mover_segundos ?? PHP_INT_MAX)
            ) {
                $ticket ??= TicketAtendimento::withoutGlobalScopes()
                    ->with(['contato', 'mensagens', 'persona', 'tenant'])
                    ->find($row->id);

                if ($ticket && $ticket->coluna_kanban === $row->coluna_kanban) {
                    $this->line("  → [auto-mover → {$config->auto_mover_coluna_destino}] #{$ticket->id} — {$ticket->contato?->nome}");

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

        $this->info("Estágio 1: {$estagiosDisparados['1']} · Estágio 2: {$estagiosDisparados['2']} · Estágio 3: {$estagiosDisparados['3']} · Auto-movidos: {$autoMovidos}");
        if (! $emHorarioComercial) {
            $this->warn('Fora do horário comercial (8h-20h) — estágios de silêncio e auto-mover não disparam nesta execução.');
        }

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
                $nomeContato = $ticket->contato?->nome;
                $temNome     = $nomeContato && $nomeContato !== $telefone;
                $texto       = str_replace('{nome}', $temNome ? $nomeContato : '', $mensagem);

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
