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
        ]);
    }

    public function toggleSdrAtivo(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $tenant->update(['sdr_ativo' => $request->boolean('sdr_ativo')]);
        return response()->json(['sdr_ativo' => $tenant->sdr_ativo]);
    }
}
