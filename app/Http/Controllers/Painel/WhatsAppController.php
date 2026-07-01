<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Services\UazapiService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(private UazapiService $uazapi) {}

    public function view(): View
    {
        return view('configuracoes.whatsapp');
    }

    public function status(Request $request): JsonResponse
    {
        $status = $this->uazapi->verificarStatus();

        return response()->json([
            'status' => $status['status'] ?? 'disconnected',
            'phone'  => $status['phone'] ?? null,
            'connected_since' => null,
        ]);
    }

    public function qrcode(Request $request): JsonResponse
    {
        $status = $this->uazapi->verificarStatus();

        if (($status['status'] ?? '') === 'connected') {
            return response()->json([
                'message' => 'WhatsApp já está conectado.',
            ], 409);
        }

        $qr = $this->uazapi->gerarQrCode(1);

        if (! $qr) {
            return response()->json([
                'message' => 'QR Code ainda não disponível. Aguarde alguns segundos e tente de novo.',
            ], 503);
        }

        return response()->json(['qrcode' => $qr]);
    }
}
