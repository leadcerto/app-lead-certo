<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Kanban;
use App\Models\WhatsappCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanCanalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        $vinculadosIds = $kanban->canais()->pluck('whatsapp_canais.id')->all();

        // Achado real 2026-08-20: sem o campo 'app', a tela não conseguia
        // distinguir WhatsApp Business de WhatsApp Messenger aqui — mostrava só
        // "Não-Oficial" genérico, igual pros dois tipos, confundindo qual número
        // é qual (mesmo problema que a tela de Configurações já tinha corrigido).
        $canais = WhatsappCanal::where('tenant_id', $tenantId)
            ->orderBy('tipo')
            ->orderBy('id')
            ->get(['id', 'tipo', 'provider', 'app', 'status', 'phone'])
            ->map(fn (WhatsappCanal $c) => [
                'id'        => $c->id,
                'tipo'      => $c->tipo,
                'provider'  => $c->provider,
                'app'       => $c->app,
                'status'    => $c->status,
                'phone'     => $c->phone,
                'vinculado' => in_array($c->id, $vinculadosIds, true),
            ]);

        return response()->json($canais);
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal_ids'   => 'present|array',
            'canal_ids.*' => 'integer',
        ]);

        $tenantId = $request->user()->tenant_id;
        $kanban   = Kanban::where('tenant_id', $tenantId)->where('tipo', 'vendas')->firstOrFail();

        $idsValidos = WhatsappCanal::where('tenant_id', $tenantId)
            ->whereIn('id', $dados['canal_ids'])
            ->pluck('id')
            ->all();

        $kanban->canais()->sync($idsValidos);

        return response()->json(['sincronizado' => true]);
    }
}
