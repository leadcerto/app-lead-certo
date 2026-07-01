<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\PushContatoParaGoogleJob;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UazapiWebhookController extends Controller
{
    public function handle(Request $request, string $webhookToken): JsonResponse
    {
        // Autentica pelo token opaco na URL — lookup por coluna unique
        $tenant = Tenant::where('uazapi_webhook_token', $webhookToken)->first();

        if (! $tenant) {
            Log::warning('Uazapi webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
            abort(401);
        }

        $payload = $request->all();

        $tipo = $payload['EventType'] ?? null;

        Log::debug('Uazapi webhook recebido', ['tenant' => $tenant->id, 'EventType' => $tipo]);

        match ($tipo) {
            'messages'   => $this->handleMensagem($payload, $tenant),
            'connection' => $this->handleConexao($payload, $tenant),
            default      => null,
        };

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------
    // Mensagem recebida / enviada
    // -----------------------------------------------------------------

    private function handleMensagem(array $payload, Tenant $tenant): void
    {
        $msg = $payload['message'] ?? [];

        $fromMe   = $msg['fromMe'] ?? false;
        $isGroup  = $msg['isGroup'] ?? false;
        $chatId   = $msg['chatid'] ?? null; // ex: "5521997797960@s.whatsapp.net"
        $viaApi   = $msg['wasSentByApi'] ?? false;

        if (! $chatId || $isGroup) {
            return;
        }

        // Número limpo: "5521997797960"
        $telefone = preg_replace('/@.+$/', '', $chatId);
        $conteudo = $msg['text'] ?? null;
        $pushName = $msg['senderName'] ?? null;

        if ($fromMe) {
            // Franqueado respondeu pelo celular físico — passa para humano
            if (! $viaApi) {
                $this->transferirParaHumano($tenant, $telefone, $conteudo);
            }
            return;
        }

        // Mensagem recebida do lead
        $this->processarMensagemLead($tenant, $telefone, $conteudo, $pushName);
    }

    private function processarMensagemLead(Tenant $tenant, string $telefone, ?string $conteudo, ?string $pushName): void
    {
        // Busca ou cria contato — usa pushName como nome inicial se não tiver cadastro
        $novoContato = false;
        $contato = Contato::firstOrCreate(
            ['telefone' => $telefone],
            ['nome' => $pushName ?: $telefone, 'origem' => 'whatsapp']
        );

        if ($contato->wasRecentlyCreated) {
            $novoContato = true;
        }

        // Se o contato existia sem nome e agora chegou o pushName, atualiza
        if ($pushName && ($contato->nome === $contato->telefone || ! $contato->nome)) {
            $contato->update(['nome' => $pushName]);
        }

        // Busca ticket aberto para este contato+tenant
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if (! $ticket) {
            // Abre novo ticket
            $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

            $ticket = TicketAtendimento::create([
                'tenant_id'          => $tenant->id,
                'contato_id'         => $contato->id,
                'coluna_kanban'      => 'lead_novo',
                'agente_responsavel' => 'bot',
                'sdr_persona_id'     => $persona?->id,
                'status'             => 'aberto',
                'aberto_em'          => now(),
            ]);
        }

        // Salva a mensagem
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'  => $ticket->id,
                'tenant_id'  => $tenant->id,
                'remetente'  => 'contato',
                'tipo'       => 'texto',
                'conteudo'   => $conteudo,
                'enviado_em' => now(),
            ]);
        }

        // Garante vínculo contato↔tenant e envia pro Google se for contato novo
        $vinculo = VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        if ($novoContato || ! $vinculo->google_resource_name) {
            dispatch(new PushContatoParaGoogleJob($contato->id, $tenant->id));
        }

        // Se o bot está responsável, dispara resposta IA
        if ($ticket->agente_responsavel === 'bot' && $conteudo) {
            dispatch(new SdrResponderJob($ticket->id, $conteudo));
        }
    }

    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo): void
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

        // Muda responsável para humano se ainda estava com o bot
        if ($ticket->agente_responsavel === 'bot') {
            $ticket->update(['agente_responsavel' => 'humano']);
            Log::info("Ticket #{$ticket->id} transferido para humano (resposta pelo celular)");
        }

        // Salva a mensagem enviada pelo franqueado
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'  => $ticket->id,
                'tenant_id'  => $tenant->id,
                'remetente'  => 'agente',
                'tipo'       => 'texto',
                'conteudo'   => $conteudo,
                'enviado_em' => now(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Atualização de conexão
    // -----------------------------------------------------------------

    private function handleConexao(array $payload, Tenant $tenant): void
    {
        $status = $payload['data']['status'] ?? null;

        if ($status === 'open') {
            $tenant->update([
                'whatsapp_status'          => 'connected',
                'whatsapp_connected_since' => now(),
            ]);
        } elseif (in_array($status, ['close', 'connecting', 'timeout'])) {
            $tenant->update(['whatsapp_status' => 'disconnected']);
            Log::warning("Tenant #{$tenant->id} WhatsApp desconectado", ['status' => $status]);
        }
    }
}
