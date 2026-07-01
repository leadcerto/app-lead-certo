<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\TicketAtendimento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index');
    }

    public function dados(Request $request): JsonResponse
    {
        $periodo = $request->query('periodo', 'hoje');

        [$inicio, $fim] = match ($periodo) {
            '7dias'  => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
            '30dias' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'mes'    => [now()->startOfMonth(), now()->endOfMonth()],
            default  => [now()->startOfDay(), now()->endOfDay()],  // hoje
        };

        $base = TicketAtendimento::whereBetween('aberto_em', [$inicio, $fim]);

        $recebidos = (clone $base)->count();
        $fechados   = (clone $base)->where('tag_desfecho', 'venda_fechada')->count();
        $emAberto   = TicketAtendimento::where('status', 'aberto')->count();

        $taxaConversao = $recebidos > 0 ? round(($fechados / $recebidos) * 100, 1) : null;

        $kanban = TicketAtendimento::where('status', 'aberto')
            ->selectRaw('coluna_kanban, count(*) as total')
            ->groupBy('coluna_kanban')
            ->pluck('total', 'coluna_kanban');

        $motivosPerda = TicketAtendimento::whereBetween('encerrado_em', [$inicio, $fim])
            ->whereNotNull('tag_desfecho')
            ->where('tag_desfecho', '!=', 'venda_fechada')
            ->selectRaw('tag_desfecho, count(*) as count')
            ->groupBy('tag_desfecho')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) use ($fechados, $recebidos) {
                $total = max(1, $recebidos - $fechados);
                return [
                    'tag'        => $row->tag_desfecho,
                    'count'      => $row->count,
                    'percentual' => round(($row->count / $total) * 100),
                ];
            });

        $semResposta2h = TicketAtendimento::where('status', 'aberto')
            ->where('agente_responsavel', 'bot')
            ->whereHas('mensagens', function ($q) {
                $q->where('remetente', 'lead')
                  ->where('enviado_em', '<=', now()->subHours(2));
            })
            ->count();

        return response()->json([
            'leads_recebidos' => $recebidos,
            'em_aberto'       => $emAberto,
            'fechados'        => $fechados,
            'taxa_conversao'  => $taxaConversao,
            'kanban'          => $kanban,
            'motivos_perda'   => $motivosPerda,
            'alertas'         => ['sem_resposta_2h' => $semResposta2h],
        ]);
    }
}
