<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function view(): View
    {
        $tenant = request()->user()->tenant;
        return view('configuracoes.whatsapp', [
            'sdrAtivo'      => (bool) $tenant->sdr_ativo,
            'retencaoDias'  => $tenant->retencao_conversas_dias,
        ]);
    }

    public function salvarRetencao(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dias' => 'nullable|integer|min:1|max:3650',
        ]);

        $tenant = $request->user()->tenant;
        $tenant->update(['retencao_conversas_dias' => $validated['dias'] ?? null]);

        return response()->json(['ok' => true, 'dias' => $tenant->fresh()->retencao_conversas_dias]);
    }

    public function toggleSdrAtivo(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->update(['sdr_ativo' => $request->boolean('sdr_ativo')]);
        return response()->json(['sdr_ativo' => $tenant->sdr_ativo]);
    }
}
