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
                'DATE(created_at) as dia, modelo, tier, ' .
                'COUNT(*) as chamadas, ' .
                'SUM(tokens_input) as tokens_input, ' .
                'SUM(tokens_output) as tokens_output, ' .
                'ROUND(AVG(latencia_ms)) as latencia_media_ms'
            )
            ->where('created_at', '>=', now()->subDays($dias)->startOfDay())
            ->groupBy('dia', 'modelo', 'tier')
            ->orderByDesc('dia')
            ->orderBy('modelo')
            ->get();

        return response()->json([
            'data'         => $porDia,
            'total_hoje'   => IaUsage::whereDate('created_at', now()->toDateString())->count(),
            'total_7_dias' => IaUsage::where('created_at', '>=', now()->subDays(7)->startOfDay())->count(),
        ]);
    }
}
