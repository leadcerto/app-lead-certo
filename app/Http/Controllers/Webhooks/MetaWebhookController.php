<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarCommentToDmJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    /**
     * Validação do Webhook pelo Meta Developer Console (GET /webhooks/meta).
     */
    public function verificar(Request $request): Response|JsonResponse
    {
        $mode        = $request->query('hub_mode') ?: $request->query('hub.mode');
        $verifyToken = $request->query('hub_verify_token') ?: $request->query('hub.verify_token');
        $challenge   = $request->query('hub_challenge') ?: $request->query('hub.challenge');

        $esperado = config('services.meta.webhook_verify_token', 'leadcerto_meta_secret_webhook_2026');

        if ($mode === 'subscribe' && $verifyToken === $esperado) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('MetaWebhook: verificação falhou', [
            'mode'        => $mode,
            'verifyToken' => $verifyToken,
        ]);

        return response()->json(['error' => 'Token inválido'], 403);
    }

    /**
     * Recepção de eventos da Meta em tempo real (POST /webhooks/meta).
     */
    public function receber(Request $request): JsonResponse
    {
        $payload = $request->all();
        $object  = $payload['object'] ?? '';

        Log::info('MetaWebhook recebido', ['object' => $object]);

        if (empty($payload['entry']) || ! is_array($payload['entry'])) {
            return response()->json(['status' => 'ignored'], 200);
        }

        foreach ($payload['entry'] as $entry) {
            $targetId = $entry['id'] ?? '';

            // ── 1. Eventos de Comentários no Instagram (object = instagram) ──
            if ($object === 'instagram' && ! empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (($change['field'] ?? '') === 'comments') {
                        $value = $change['value'] ?? [];
                        $commentId = $value['id'] ?? '';
                        $texto     = $value['text'] ?? '';
                        $fromId    = $value['from']['id'] ?? '';
                        $fromUser  = $value['from']['username'] ?? '';
                        $mediaId   = $value['media']['id'] ?? '';

                        if ($commentId && $texto) {
                            ProcessarCommentToDmJob::dispatch(
                                commentId: $commentId,
                                postId: $mediaId,
                                textoComentario: $texto,
                                fromId: $fromId,
                                fromName: $fromUser,
                                fromUsername: $fromUser,
                                plataforma: 'instagram',
                                targetId: $targetId
                            );
                        }
                    }
                }
            }

            // ── 2. Eventos de Comentários no Facebook (object = page) ────────
            if ($object === 'page' && ! empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (($change['field'] ?? '') === 'feed') {
                        $val = $change['value'] ?? [];
                        if (($val['item'] ?? '') === 'comment' && ($val['verb'] ?? '') === 'add') {
                            $commentId = $val['comment_id'] ?? '';
                            $postId    = $val['post_id'] ?? '';
                            $texto     = $val['message'] ?? '';
                            $fromId    = $val['from']['id'] ?? '';
                            $fromName  = $val['from']['name'] ?? '';

                            if ($commentId && $texto) {
                                ProcessarCommentToDmJob::dispatch(
                                    commentId: $commentId,
                                    postId: $postId,
                                    textoComentario: $texto,
                                    fromId: $fromId,
                                    fromName: $fromName,
                                    fromUsername: null,
                                    plataforma: 'facebook',
                                    targetId: $targetId
                                );
                            }
                        }
                    }
                }
            }

            // ── 3. Mensagens Recebidas no Direct / Messenger ─────────────────
            if (! empty($entry['messaging'])) {
                foreach ($entry['messaging'] as $msgEvent) {
                    $this->processarMensagemDireta($msgEvent, $object === 'instagram' ? 'instagram' : 'facebook', $targetId);
                }
            }
        }

        return response()->json(['status' => 'processed'], 200);
    }

    /**
     * Processa mensagem enviada pelo lead via Direct do Instagram ou Messenger.
     */
    private function processarMensagemDireta(array $msgEvent, string $plataforma, string $targetId): void
    {
        $senderId = $msgEvent['sender']['id'] ?? '';
        $texto    = $msgEvent['message']['text'] ?? '';
        $isEcho   = ! empty($msgEvent['message']['is_echo']);

        if ($isEcho || empty($texto) || empty($senderId)) {
            return;
        }

        // Descobre tenant
        $tenantId = null;
        if ($plataforma === 'instagram') {
            $conta = MetaContaInstagram::withoutGlobalScopes()->where('instagram_business_id', $targetId)->first();
            $tenantId = $conta?->tenant_id;
        } else {
            $pag = MetaPagina::withoutGlobalScopes()->where('facebook_page_id', $targetId)->first();
            $tenantId = $pag?->tenant_id;
        }

        if (! $tenantId) {
            return;
        }

        $origemTicket = $plataforma === 'instagram' ? 'instagram_direct' : 'facebook_messenger';

        $contato = Contato::withoutGlobalScopes()
            ->where('observacoes', 'like', "%meta_user_id:{$senderId}%")
            ->first();

        if (! $contato) {
            $contato = Contato::create([
                'nome'        => 'Lead ' . ucfirst($plataforma),
                'observacoes' => "meta_user_id:{$senderId} | plataforma:{$plataforma}",
                'canal_origem'=> $origemTicket,
            ]);
        }

        VinculoContatoTenant::firstOrCreate([
            'tenant_id'  => $tenantId,
            'contato_id' => $contato->id,
        ]);

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contato_id', $contato->id)
            ->where('status', 'aberto')
            ->latest()
            ->first();

        if (! $ticket) {
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'contato_id'    => $contato->id,
                'coluna_kanban' => 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
        }

        Mensagem::create([
            'tenant_id' => $tenantId,
            'ticket_id' => $ticket->id,
            'remetente' => 'lead',
            'tipo'      => 'texto',
            'conteudo'  => $texto,
            'criado_em' => now(),
        ]);
    }
}
