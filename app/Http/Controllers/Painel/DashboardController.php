<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaContato;
use App\Models\MotivoDesfecho;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index');
    }

    public function dados(Request $request): JsonResponse
    {
        $periodo  = $request->query('periodo', 'hoje');
        $tenantId = $request->user()->tenant_id;

        [$inicio, $fim] = match ($periodo) {
            '7dias'  => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
            '30dias' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'mes'    => [now()->startOfMonth(), now()->endOfMonth()],
            default  => [now()->startOfDay(), now()->endOfDay()],
        };

        // Motivos marcados como "conta como venda" pra esse tenant — hoje só
        // 'venda_fechada' por padrão, mas o tenant pode renomear/adicionar
        // outros motivos que também contam (ver /kanban/motivos-desfecho).
        $chavesVenda = MotivoDesfecho::where('tenant_id', $tenantId)
            ->where('e_venda', true)
            ->pluck('chave');

        $base = TicketAtendimento::whereBetween('aberto_em', [$inicio, $fim]);

        $recebidos = (clone $base)->count();
        $fechados   = (clone $base)->whereIn('tag_desfecho', $chavesVenda)->count();
        $emAberto   = TicketAtendimento::where('status', 'aberto')->count();

        $taxaConversao = $recebidos > 0 ? round(($fechados / $recebidos) * 100, 1) : null;

        $kanban = TicketAtendimento::where('status', 'aberto')
            ->selectRaw('coluna_kanban, count(*) as total')
            ->groupBy('coluna_kanban')
            ->pluck('total', 'coluna_kanban');

        $motivosPerda = TicketAtendimento::whereBetween('encerrado_em', [$inicio, $fim])
            ->whereNotNull('tag_desfecho')
            ->whereNotIn('tag_desfecho', $chavesVenda)
            ->selectRaw('tag_desfecho, count(*) as count')
            ->groupBy('tag_desfecho')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) use ($fechados, $recebidos) {
                $total = max(1, $recebidos - $fechados);
                return [
                    'tag'        => $row->tag_desfecho,
                    'count'      => $row->count,
                    'percentual' => min(100, round(($row->count / $total) * 100)),
                ];
            });

        $semResposta2h = TicketAtendimento::where('status', 'aberto')
            ->where('agente_responsavel', 'bot')
            ->whereHas('mensagens', function ($q) {
                $q->where('remetente', 'lead')
                  ->where('enviado_em', '<=', now()->subHours(2));
            })
            ->count();

        $contatoIds = VinculoContatoTenant::where('tenant_id', $tenantId)->pluck('contato_id');
        $auditoriaPendentes = AuditoriaContato::where('status', 'pendente')
            ->whereIn('contato_id', $contatoIds)
            ->count();

        return response()->json([
            'leads_recebidos'    => $recebidos,
            'em_aberto'          => $emAberto,
            'fechados'           => $fechados,
            'taxa_conversao'     => $taxaConversao,
            'kanban'             => $kanban,
            'motivos_perda'      => $motivosPerda,
            'auditoria_pendentes' => $auditoriaPendentes,
            'alertas'            => ['sem_resposta_2h' => $semResposta2h],
        ]);
    }

    public function automacoes(): JsonResponse
    {
        $rotinas = [
            ['nome' => 'Sincronizar Google Contacts', 'log' => 'google-sync.log',        'horario' => 'a cada 6h'],
            ['nome' => 'Modelos OpenRouter',           'log' => 'openrouter-modelos.log', 'horario' => '00:01 diário'],
            ['nome' => 'Identificar Nomes (IA)',       'log' => 'identificar-nomes.log',  'horario' => '00:05 diário'],
            ['nome' => 'Limpar Nomes (IA)',            'log' => 'limpar-nomes.log',       'horario' => '00:10 diário'],
            ['nome' => 'Follow-up de Conversas',       'log' => 'followup-conversas.log', 'horario' => 'a cada 5min'],
            ['nome' => 'Limpar Conversas Antigas',     'log' => 'limpar-conversas.log',   'horario' => '02:00 diário'],
        ];

        $resultado = [];

        foreach ($rotinas as $r) {
            $path = storage_path('logs/' . $r['log']);

            if (! file_exists($path)) {
                $resultado[] = [
                    'nome'        => $r['nome'],
                    'horario'     => $r['horario'],
                    'status'      => 'nunca',
                    'quando'      => null,
                    'tempo_atras' => null,
                    'resumo'      => 'Ainda não executou',
                    'linhas'      => [],
                    'erros'       => 0,
                ];
                continue;
            }

            $mtime  = filemtime($path);
            $quando = date('d/m H:i', $mtime);
            $diff   = (int) round((time() - $mtime) / 60);

            if ($diff < 60) {
                $tempoAtras = "há {$diff}min";
            } elseif ($diff < 1440) {
                $h          = (int) floor($diff / 60);
                $tempoAtras = "há {$h}h";
            } else {
                $d          = (int) floor($diff / 1440);
                $tempoAtras = "há {$d}d";
            }

            $lines   = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $ultimas = array_slice($lines, -25);

            $erros  = 0;
            $resumo = '';

            foreach ($ultimas as $linha) {
                $l = trim($linha);
                if (str_contains($l, '✗') || str_contains($l, 'SQLSTATE') || str_contains($l, 'Undefined variable')) {
                    $erros++;
                }
                if (preg_match('/Total|Identificados:|Modelos|Nenhum|Importados|ignorados/i', $l)) {
                    $resumo = $l;
                }
            }

            if (! $resumo && ! empty($ultimas)) {
                $resumo = trim((string) end($ultimas));
            }

            $status = match (true) {
                $erros >= 5 => 'erro',
                $erros >= 1 => 'aviso',
                default     => 'ok',
            };

            $resultado[] = [
                'nome'        => $r['nome'],
                'horario'     => $r['horario'],
                'status'      => $status,
                'quando'      => $quando,
                'tempo_atras' => $tempoAtras,
                'resumo'      => $resumo ?: 'Executado com sucesso',
                'linhas'      => array_values($ultimas),
                'erros'       => $erros,
            ];
        }

        return response()->json($resultado);
    }
}
