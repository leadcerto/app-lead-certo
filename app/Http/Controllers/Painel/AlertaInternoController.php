<?php
// app/Http/Controllers/Painel/AlertaInternoController.php
namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AlertaInterno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaInternoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Regra 2 (Bloco 5) — dúvidas não respondidas nunca saem da lista por
        // volume de outros tipos de alerta (ex: ticket_travado, gerado a
        // cada 15min pelo Bloco 4). Prioridade: todos os alertas que pausam
        // um ticket (dúvida explícita, rejeição de área alucinada, handoff
        // prematuro — achado real 2026-08-20: só 'duvida_ia' tinha
        // prioridade, os outros dois guardrails podiam sumir da lista dos
        // 20 mais recentes sem ninguém nunca ver) primeiro, depois os demais
        // tipos mais recentes até completar 20.
        $tiposQuePausam = ['duvida_ia', 'rejeicao_area_alucinada', 'handoff_prematuro'];

        $duvidasPendentes = AlertaInterno::where('tenant_id', $tenantId)
            ->whereIn('tipo', $tiposQuePausam)
            ->whereNull('resposta')
            ->orderByDesc('created_at')
            ->get();

        $restantes = 20 - $duvidasPendentes->count();

        $outros = $restantes > 0
            ? AlertaInterno::where('tenant_id', $tenantId)
                ->where(function ($q) use ($tiposQuePausam) {
                    $q->whereNotIn('tipo', $tiposQuePausam)->orWhereNotNull('resposta');
                })
                ->orderByDesc('created_at')
                ->limit($restantes)
                ->get()
            : collect();

        $alertas = $duvidasPendentes->concat($outros);

        $naoLidos = AlertaInterno::where('tenant_id', $tenantId)
            ->whereNull('lido_em')
            ->count();

        return response()->json([
            'data'            => $alertas,
            'nao_lidos_count' => $naoLidos,
        ]);
    }

    public function marcarLido(Request $request, int $id): JsonResponse
    {
        $alerta = AlertaInterno::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $alerta->update(['lido_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function marcarTodosLidos(Request $request): JsonResponse
    {
        AlertaInterno::where('tenant_id', $request->user()->tenant_id)
            ->whereNull('lido_em')
            ->update(['lido_em' => now()]);

        return response()->json(['ok' => true]);
    }
}
