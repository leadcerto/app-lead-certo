<?php

namespace App\Http\Controllers;

use App\Models\GmbQualidadeScore;
use App\Models\PerfilGmb;
use App\Services\GmbQualidadeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GmbQualidadeController extends Controller
{
    public function show(Request $request, PerfilGmb $perfil, GmbQualidadeService $service): View
    {
        abort_if($perfil->tenant_id !== $request->user()->tenantAtual(), 403);

        $score = GmbQualidadeScore::where('perfil_gmb_id', $perfil->id)->first()
            ?? $service->avaliar($perfil);

        return view('gmb-qualidade.show', ['perfil' => $perfil, 'score' => $score]);
    }

    public function reavaliar(Request $request, PerfilGmb $perfil, GmbQualidadeService $service): RedirectResponse
    {
        abort_if($perfil->tenant_id !== $request->user()->tenantAtual(), 403);

        $service->avaliar($perfil);

        return redirect()->route('admin.gmb-qualidade.show', $perfil)
            ->with('sucesso', 'Diagnóstico atualizado.');
    }
}
