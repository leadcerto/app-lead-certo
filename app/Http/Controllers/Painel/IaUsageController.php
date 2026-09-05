<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\IaUsage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IaUsageController extends Controller
{
    public function view(): View
    {
        return view('ia-monitor.index');
    }

    public function index(Request $request): JsonResponse
    {
        $dias = (int) $request->query('dias', 30);

        $porDia = IaUsage::selectRaw(
                'DATE(ia_usages.created_at) as dia, ' .
                'COALESCE(users.nome, "Sistema / Automático") as membro_equipe, ' .
                'ia_usages.modelo, ' .
                'ia_usages.tier, ' .
                'COUNT(*) as chamadas, ' .
                'SUM(tokens_input) as tokens_input, ' .
                'SUM(tokens_output) as tokens_output, ' .
                'ROUND(AVG(latencia_ms)) as latencia_media_ms'
            )
            ->leftJoin('users', 'ia_usages.agente_id', '=', 'users.id')
            ->where('ia_usages.created_at', '>=', now()->subDays($dias)->startOfDay())
            ->groupBy('dia', 'membro_equipe', 'ia_usages.modelo', 'ia_usages.tier')
            ->orderByDesc('dia')
            ->orderBy('membro_equipe')
            ->orderBy('ia_usages.modelo')
            ->get();

        return response()->json([
            'data'         => $porDia,
            'total_hoje'   => IaUsage::whereDate('created_at', now()->toDateString())->count(),
            'total_7_dias' => IaUsage::where('created_at', '>=', now()->subDays(7)->startOfDay())->count(),
        ]);
    }
}
