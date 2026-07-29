<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsappCanalOficialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $canais = WhatsappCanal::where('tenant_id', $request->user()->tenant_id)
            ->where('tipo', 'oficial')
            ->orderBy('id')
            ->get(['id', 'status', 'phone', 'connected_since', 'config']);

        // phone_number_id não é segredo, mas o resto de config (webhook_secret) sim —
        // devolve só o phone_number_id pro frontend saber mostrar, nunca o segredo.
        $canais->transform(function ($canal) {
            $canal->phone_number_id = $canal->config['phone_number_id'] ?? null;
            unset($canal->config);
            return $canal;
        });

        return response()->json($canais);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number_id' => 'required|string|max:100',
            'telefone'        => 'required|string|max:20',
            'apelido'         => 'nullable|string|max:100',
        ]);

        $tenantId = $request->user()->tenant_id;

        $jaExiste = WhatsappCanal::where('tenant_id', $tenantId)
            ->where('tipo', 'oficial')
            ->whereJsonContains('config->phone_number_id', $validated['phone_number_id'])
            ->exists();

        if ($jaExiste) {
            return response()->json(['message' => 'Este número já está conectado neste tenant.'], 422);
        }

        $webhookUrl = config('app.url') . '/api/webhook/covercut';
        $baseUrl    = config('services.covercut.base_url');

        $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])
            ->post("{$baseUrl}/numbers/webhook", [
                'from'        => $validated['phone_number_id'],
                'webhook_url' => $webhookUrl,
                'enabled'     => true,
            ]);

        if (! $response->successful()) {
            return response()->json(['message' => 'Erro ao registrar o webhook na Covercut. Confira o phone_number_id.'], 502);
        }

        $webhookSecret = $response->json('webhook_secret');

        $canal = WhatsappCanal::create([
            'tenant_id' => $tenantId,
            'tipo'      => 'oficial',
            'provider'  => 'covercut',
            'status'    => 'connected',
            'phone'     => $validated['telefone'],
            'connected_since' => now(),
            'config'    => [
                'phone_number_id' => $validated['phone_number_id'],
                'webhook_secret'  => $webhookSecret,
                'apelido'         => $validated['apelido'] ?? null,
            ],
        ]);

        // Mesmo padrão já usado para número não-oficial (WhatsappCanalController::store):
        // vincula a todos os Kanbans do tenant, pra rotear mensagem inbound sem passo manual.
        $kanbanIds = Kanban::where('tenant_id', $tenantId)->pluck('id');
        $canal->kanbans()->syncWithoutDetaching($kanbanIds);

        return response()->json(['id' => $canal->id, 'status' => $canal->status], 201);
    }

    public function destroy(WhatsappCanal $canal): JsonResponse
    {
        abort_if($canal->tenant_id !== auth()->user()->tenant_id, 404);
        abort_if($canal->tipo !== 'oficial', 404);

        $baseUrl = config('services.covercut.base_url');
        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if ($phoneNumberId) {
            Http::withHeaders([
                    'X-API-Key'    => config('services.covercut.api_key'),
                    'X-API-Secret' => config('services.covercut.api_secret'),
                ])
                ->post("{$baseUrl}/numbers/webhook", ['from' => $phoneNumberId, 'action' => 'delete']);
        }

        $canal->delete();

        return response()->json(['excluido' => true]);
    }
}
