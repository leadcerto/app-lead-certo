<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Services\UazapiService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    public function __construct(private UazapiService $uazapi) {}

    public function view(): View
    {
        return view('configuracoes.whatsapp');
    }

    public function status(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        if (! $tenant->uazapi_instance_token) {
            return response()->json([
                'status' => 'disconnected',
                'phone'  => null,
                'connected_since' => null,
            ]);
        }

        $data = $this->uazapi->status($tenant->uazapi_instance_token);
        $connected = $data['status']['connected'] ?? false;

        if ($connected && $tenant->whatsapp_status !== 'connected') {
            $tenant->update([
                'whatsapp_status'           => 'connected',
                'whatsapp_phone'            => $data['status']['phone'] ?? null,
                'whatsapp_connected_since'  => now(),
            ]);
        }

        return response()->json([
            'status' => $connected ? 'connected' : 'disconnected',
            'phone'  => $tenant->fresh()->whatsapp_phone,
            'connected_since' => $tenant->whatsapp_connected_since,
        ]);
    }

    public function qrcode(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        // Cria a instância na primeira vez
        if (! $tenant->uazapi_instance_token) {
            $nome   = 'tenant-' . $tenant->id;
            $result = $this->uazapi->criarInstancia($nome);

            if (! $result || ! $result['token']) {
                return response()->json(['message' => 'Erro ao criar instância WhatsApp. Tente novamente.'], 500);
            }

            // Token opaco por tenant — usado na URL do webhook (não expõe instance_token)
            $webhookToken = Str::random(48);

            $tenant->update([
                'uazapi_instance_name'   => $result['name'],
                'uazapi_instance_token'  => $result['token'],
                'uazapi_webhook_token'   => $webhookToken,
            ]);
            $tenant->refresh();

            // URL única e indevassável por tenant
            $webhookUrl = config('app.url') . '/api/webhook/uazapi/' . $webhookToken;
            $this->uazapi->configurarWebhook($result['token'], $webhookUrl, ['messages', 'connection']);
        }

        // Já conectado?
        $statusData = $this->uazapi->status($tenant->uazapi_instance_token);
        if ($statusData['status']['connected'] ?? false) {
            return response()->json(['message' => 'WhatsApp já está conectado.'], 409);
        }

        // Solicita QR code
        $qr = $this->uazapi->conectar($tenant->uazapi_instance_token);

        if (! $qr) {
            return response()->json([
                'message' => 'QR Code ainda não disponível. Aguarde alguns segundos e tente novamente.',
            ], 503);
        }

        // Remove prefixo data:image/... se vier na resposta
        $qr = preg_replace('/^data:image\/[^;]+;base64,/', '', $qr);

        return response()->json(['qrcode' => $qr]);
    }
}
