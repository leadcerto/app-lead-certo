<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\SincronizarAgendaWhatsAppJob;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use App\Services\UazapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WhatsappCanalController extends Controller
{
    public function __construct(private UazapiService $uazapi) {}

    public function index(Request $request): JsonResponse
    {
        $canais = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'nao_oficial')
            ->orderBy('id')
            ->get(['id', 'status', 'phone', 'connected_since']);

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $nome     = 'tenant-' . $tenantId . '-' . Str::random(6);

        $result = $this->uazapi->criarInstancia($nome);

        if (! $result || ! $result['token']) {
            return response()->json(['message' => 'Erro ao criar instância WhatsApp. Tente novamente.'], 500);
        }

        $webhookToken = Str::random(48);

        $canal = WhatsappCanal::create([
            'tenant_id'     => $tenantId,
            'tipo'          => 'nao_oficial',
            'provider'      => 'uazapi',
            'status'        => 'connecting',
            'webhook_token' => $webhookToken,
            'config'        => [
                'instance_name'  => $result['name'],
                'instance_token' => $result['token'],
            ],
        ]);

        $webhookUrl = config('app.url') . '/api/webhook/uazapi/' . $webhookToken;
        $this->uazapi->configurarWebhook($result['token'], $webhookUrl, ['messages', 'connection']);

        // Vincula o canal recém-conectado a TODOS os Kanbans do tenant — decisão
        // do produto: um número novo já entra disponível pra prospecção, em vez de
        // ficar invisível até alguém visitar /kanban/config e vincular manualmente
        // (mesmo padrão da migration de backfill do Task 3).
        $kanbanIds = Kanban::where('tenant_id', $tenantId)->pluck('id');
        $canal->kanbans()->syncWithoutDetaching($kanbanIds);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    public function status(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        $data      = $this->uazapi->status($canal->tokenUazapi());
        $connected = $data['status']['connected'] ?? false;

        if ($connected && $canal->status !== 'connected') {
            $canal->update([
                'status'          => 'connected',
                'phone'           => $data['status']['phone'] ?? null,
                'connected_since' => now(),
            ]);

            SincronizarAgendaWhatsAppJob::dispatch($canal->id)->delay(now()->addSeconds(10));
        }

        return response()->json([
            'status'          => $connected ? 'connected' : 'disconnected',
            'phone'           => $canal->fresh()->phone,
            'connected_since' => $canal->connected_since,
        ]);
    }

    public function qrcode(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        $statusData = $this->uazapi->status($canal->tokenUazapi());
        if ($statusData['status']['connected'] ?? false) {
            return response()->json(['message' => 'WhatsApp já está conectado.'], 409);
        }

        $qr = $this->uazapi->conectar($canal->tokenUazapi());

        if (! $qr) {
            return response()->json([
                'message' => 'QR Code ainda não disponível. Aguarde alguns segundos e tente novamente.',
            ], 503);
        }

        $qr = preg_replace('/^data:image\/[^;]+;base64,/', '', $qr);

        return response()->json(['qrcode' => $qr]);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);

        $this->uazapi->deletarInstancia($canal->tokenUazapi());
        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
