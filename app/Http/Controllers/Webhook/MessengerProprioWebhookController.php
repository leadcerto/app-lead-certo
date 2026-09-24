<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\PushContatoParaGoogleJob;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\MediaProcessorService;
use App\Services\SequenciaService;
use App\Services\TelefoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4 do plano do canal WhatsApp Messenger próprio (23/09) — recebe os
 * eventos do microserviço (leadcerto/integracoes/whatsapp-proprio). Espelha
 * o essencial de UazapiWebhookController::processarMensagemLead() (mesmo
 * fluxo de contato→ticket→sequência/SDR) sem tocar naquele controller —
 * decisão deliberada: UazapiWebhookController já é grande e delicado
 * (~700 linhas em produção), uma extração completa pra um serviço 100%
 * compartilhado é trabalho maior que o escopo desta fase justifica agora
 * (ver achado do planejamento). Diferenças reais em relação ao Uazapi:
 * - Payload já normalizado pelo próprio microserviço (numero limpo, sem
 *   regex de chatid) — não precisa de normalizarTelefone().
 * - Mídia chega decriptada (mediaBase64) — usa os métodos *MessengerProprio
 *   de MediaProcessorService em vez de baixar por token.
 * - Sem suporte a botões interativos nem chamada perdida nesta v1 (recursos
 *   ainda não confirmados como funcionais no protocolo Messenger via
 *   Baileys) — se algum evento chegar, cai no fluxo de texto normal.
 */
class MessengerProprioWebhookController extends Controller
{
    public function handle(Request $request, string $webhookToken): JsonResponse
    {
        $canal = WhatsappCanal::withoutGlobalScopes()
            ->where('webhook_token', $webhookToken)
            ->where('provider', 'messenger_proprio')
            ->first();

        if (! $canal) {
            Log::warning('MessengerProprio webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
            abort(401);
        }

        $tenant  = $canal->tenant;
        $payload = $request->all();
        $tipo    = $payload['tipo'] ?? null;

        Log::debug('MessengerProprio webhook recebido', ['tenant' => $tenant->id, 'canal' => $canal->id, 'tipo' => $tipo]);

        match ($tipo) {
            'mensagem' => $this->handleMensagem($payload, $tenant, $canal),
            'conexao'  => $this->handleConexao($payload, $canal),
            default    => Log::warning('MessengerProprio webhook: tipo não tratado', ['tipo' => $tipo, 'payload' => $payload]),
        };

        return response()->json(['ok' => true]);
    }

    private function handleConexao(array $payload, WhatsappCanal $canal): void
    {
        $status = $payload['status'] ?? null;

        if ($status === 'connected') {
            $canal->update([
                'status'          => 'connected',
                'phone'           => $payload['phone'] ?? $canal->phone,
                'connected_since' => now(),
            ]);
        } elseif ($status === 'disconnected') {
            $canal->update(['status' => 'disconnected']);
            Log::warning("Canal #{$canal->id} WhatsApp Messenger desconectado", ['motivo' => $payload['motivo'] ?? null]);
        }
    }

    private function handleMensagem(array $payload, Tenant $tenant, WhatsappCanal $canal): void
    {
        $telefone  = $payload['numero'] ?? null;
        $messageId = $payload['messageId'] ?? null;
        $fromMe    = (bool) ($payload['fromMe'] ?? false);

        if (! $telefone) {
            return;
        }

        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('MessengerProprio webhook: mensagem duplicada ignorada', ['messageId' => $messageId]);
            return;
        }

        if ($fromMe) {
            // v1: franqueado respondendo pelo próprio celular — só passa o
            // ticket pra humano, sem reprocessar mídia (menos crítico que o
            // caminho do lead; pode ser estendido depois se fizer falta).
            $this->transferirParaHumano($tenant, $telefone, $canal);
            return;
        }

        $this->processarMensagemLead($payload, $tenant, $telefone, $canal);
    }

    private function processarMensagemLead(array $payload, Tenant $tenant, string $telefone, WhatsappCanal $canal): void
    {
        $pushName = $payload['pushName'] ?? null;
        $conteudo = $payload['texto'] ?? null;

        $nomeExtracao = app(\App\Services\NomeExtracaoService::class);
        $nomeValido   = $nomeExtracao->pushNameValido($pushName) ? $nomeExtracao->formatarNome($pushName) : null;

        $novoContato = false;
        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $nomeValido ?: 'Sem Nome', 'origem' => 'whatsapp']);

        if ($contato->wasRecentlyCreated) {
            $novoContato = true;
        }

        if ($nomeValido && $contato->semNomeReal()) {
            $contato->update(['nome' => $nomeValido]);
        } elseif ($nomeValido) {
            app(\App\Services\ContatoSyncService::class)
                ->flagrarSeNumeroPossivelmenteReciclado($contato, $tenant->id, $nomeValido, $telefone);
        }

        // Mesma trava de corrida que UazapiWebhookController usa — duas
        // mensagens quase simultâneas do mesmo lead não podem criar dois
        // tickets/duas sequências de boas-vindas.
        [$ticket, $ticketNovo] = Cache::lock("ticket-resolve:messenger-proprio:{$tenant->id}:{$contato->id}", 10)
            ->block(5, function () use ($tenant, $contato, $canal, $conteudo) {
                $ticket = TicketAtendimento::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('contato_id', $contato->id)
                    ->whereIn('status', ['aberto', 'aguardando'])
                    ->latest()
                    ->first();

                $ticketNovo = false;
                if ($ticket) {
                    if ($ticket->whatsapp_canal_id !== $canal->id) {
                        $ticket->update(['whatsapp_canal_id' => $canal->id]);
                    }
                } else {
                    $reaberturaService = app(\App\Services\TicketReaberturaService::class);
                    $ticketEncerrado   = $reaberturaService->buscarTicketEncerrado($tenant->id, $contato->id);

                    if ($ticketEncerrado) {
                        $reaberturaService->reabrirSeNecessario($ticketEncerrado, $canal->id, $conteudo);
                        $ticket = $ticketEncerrado;
                    } elseif ($contato->excluidoDoFunilComercial()) {
                        return [null, false];
                    } else {
                        $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

                        $ticket = TicketAtendimento::create([
                            'tenant_id'          => $tenant->id,
                            'contato_id'         => $contato->id,
                            'whatsapp_canal_id'  => $canal->id,
                            'coluna_kanban'      => KanbanColuna::chaveDeEntrada($tenant->id),
                            'agente_responsavel' => 'bot',
                            'sdr_persona_id'     => $persona?->id,
                            'status'             => 'aberto',
                            'origem'             => 'whatsapp',
                            'aberto_em'          => now(),
                        ]);
                        $ticketNovo = true;
                    }
                }

                return [$ticket, $ticketNovo];
            });

        if (! $ticket) {
            return;
        }

        // Processa mídia, se houver — bytes já vieram decriptados no payload.
        $tipoMidia    = $payload['tipoMidia'] ?? null;
        $tipoMensagem = 'texto';
        $midiaUrl     = null;

        if ($tipoMidia && ! empty($payload['mediaBase64'])) {
            try {
                $bytes = base64_decode($payload['mediaBase64']);
                $mime  = $payload['mediaMimeType'] ?? 'application/octet-stream';

                $colunaConfig = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->first();
                $focoAnalise      = $tipoMidia === 'imagem' ? $colunaConfig?->foco_analise_imagem : null;
                $transcricaoAtiva = $colunaConfig?->transcricao_ativa ?? true;

                $processor = app(MediaProcessorService::class);

                if ($tipoMidia === 'imagem') {
                    $resultado    = $processor->processarImagemUnicaMessengerProprio($bytes, $mime, $conteudo, $focoAnalise, $transcricaoAtiva);
                    $conteudo     = $resultado['conteudo'];
                    $tipoMensagem = 'imagem';
                    $midiaUrl     = $resultado['midiaUrl'];

                    if ($resultado['itens']) {
                        $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                        $ticket->update(['lista_itens' => $listaAtual . $resultado['itens']]);
                    }
                } else {
                    $processado = $processor->processarMessengerProprio($tipoMidia, $bytes, $mime, $transcricaoAtiva);
                    if ($processado !== null) {
                        $conteudo     = $processado;
                        $tipoMensagem = match ($tipoMidia) {
                            'video' => 'video', 'audio', 'audio_arquivo' => 'audio', default => 'texto',
                        };
                        if (in_array($tipoMidia, ['audio', 'audio_arquivo', 'video'], true)) {
                            $midiaUrl = $processor->persistirUrlMessengerProprio($bytes, $mime, $tipoMensagem);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('MessengerProprio webhook: falha ao processar mídia', [
                    'tipoMidia' => $tipoMidia, 'erro' => $e->getMessage(),
                ]);
            }
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'           => $ticket->id,
                'tenant_id'           => $tenant->id,
                'remetente'           => 'lead',
                'tipo'                => $tipoMensagem,
                'conteudo'            => $conteudo,
                'midia_url'           => $midiaUrl,
                'provider_message_id' => $payload['messageId'] ?? null,
                'enviado_em'          => now(),
            ]);
        }

        if ($ticket->followup_estagio_enviado !== 0 || $ticket->followup_enviado) {
            $ticket->update(['followup_estagio_enviado' => 0, 'followup_enviado' => false]);
        }

        $vinculo = VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        if ($novoContato || ! $vinculo->google_resource_name) {
            dispatch(new PushContatoParaGoogleJob($contato->id, $tenant->id, $nomeValido ?? $pushName));
        }

        if ($ticketNovo) {
            app(SequenciaService::class)->iniciarParaTicket($ticket);
        } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
            $delay = SdrResponderJob::resolverDelay($tenant->id, $ticket->coluna_kanban);
            dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, $delay))
                ->delay(now()->addSeconds($delay));
        }
    }

    private function transferirParaHumano(Tenant $tenant, string $telefone, WhatsappCanal $canal): void
    {
        $contato = Contato::where('telefone', $telefone)->first();
        if (! $contato) {
            return;
        }

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if (! $ticket) {
            return;
        }

        $updates = [];
        if ($ticket->whatsapp_canal_id !== $canal->id) {
            $updates['whatsapp_canal_id'] = $canal->id;
        }
        if ($ticket->agente_responsavel === 'bot') {
            $updates['agente_responsavel'] = 'humano';
            Log::info("Ticket #{$ticket->id} transferido para humano (resposta pelo WhatsApp Messenger)");
        }
        if ($updates) {
            $ticket->update($updates);
        }
    }

    /**
     * Mesma proteção contra corrida com o sync do Google que
     * UazapiWebhookController::buscarOuCriarContato() já tem.
     */
    private function buscarOuCriarContato(string $telefone, array $atributos): Contato
    {
        try {
            return Contato::firstOrCreate(['telefone' => $telefone], $atributos);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $contato = Contato::withTrashed()->where('telefone', $telefone)->first();
            if (! $contato) {
                throw $e;
            }
            if ($contato->trashed()) {
                $contato->restore();
            }
            return $contato;
        }
    }
}
