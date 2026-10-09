<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Painel\Concerns\ResolveKanbanDoRequest;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KanbanColunaConfigController extends Controller
{
    use ResolveKanbanDoRequest;

    public function show(Request $request, string $coluna): JsonResponse
    {
        $kanban = $this->resolverKanban($request);
        $colunaReal = KanbanColuna::where('kanban_id', $kanban->id)->where('chave', $coluna)->firstOrFail();

        $config = KanbanColunaConfig::where('tenant_id', $request->user()->tenant_id)
            ->where('kanban_coluna_id', $colunaReal->id)
            ->first();

        return response()->json([
            'coluna_kanban'               => $coluna,
            'objetivo'                    => $config?->objetivo                    ?? '',
            'seq_objetivo'                => $config?->seq_objetivo                ?? '',
            'ia_objetivo'                 => $config?->ia_objetivo                 ?? '',
            'ia_contexto'                 => $config?->ia_contexto                 ?? '',
            'foco_analise_imagem'         => $config?->foco_analise_imagem         ?? '',
            'transcricao_ativa'           => $config?->transcricao_ativa           ?? true,
            'ia_ativo'                    => $config?->ia_ativo                    ?? false,
            'sdr_delay_segundos'          => $config?->sdr_delay_segundos          ?? 45,
            'followup_estagio1_segundos'  => $config?->followup_estagio1_segundos  ?? 3600,
            'followup_estagio2_segundos'  => $config?->followup_estagio2_segundos  ?? 7200,
            'followup_estagio3_segundos'  => $config?->followup_estagio3_segundos  ?? 21600,
            'auto_mover_ativo'            => $config?->auto_mover_ativo            ?? false,
            'auto_mover_coluna_destino'   => $config?->auto_mover_coluna_destino   ?? '',
            'auto_mover_segundos'         => $config?->auto_mover_segundos         ?? 259200,
            'auto_mover_mensagem'         => $config?->auto_mover_mensagem         ?? '',
            'exclusao_definitiva_ativo'   => $config?->exclusao_definitiva_ativo   ?? false,
            'exclusao_definitiva_dias'    => $config?->exclusao_definitiva_dias    ?? 90,
            'timeout_reassuncao_ativo'    => $config?->timeout_reassuncao_ativo    ?? false,
            'timeout_reassuncao_segundos' => $config?->timeout_reassuncao_segundos ?? 3600,
            'aguardando_orientacao_mensagem' => $config?->aguardando_orientacao_mensagem ?? '',
            'tempo_maximo_permanencia_minutos' => $config?->tempo_maximo_permanencia_minutos ?? null,
            'duvida_timeout_ativo'    => $config?->duvida_timeout_ativo    ?? false,
            'duvida_timeout_segundos' => $config?->duvida_timeout_segundos ?? 3600,
        ]);
    }

    public function update(Request $request, string $coluna): JsonResponse
    {
        $kanban = $this->resolverKanban($request);
        $colunaReal = KanbanColuna::where('kanban_id', $kanban->id)->where('chave', $coluna)->firstOrFail();

        $validated = $request->validate([
            'objetivo'                    => 'nullable|string|max:1000',
            'seq_objetivo'                => 'nullable|string|max:1000',
            'ia_objetivo'                 => 'nullable|string|max:1000',
            'ia_contexto'                 => 'nullable|string|max:50000',
            'foco_analise_imagem'         => 'nullable|string|max:1000',
            'transcricao_ativa'           => 'sometimes|boolean',
            'ia_ativo'                    => 'sometimes|boolean',
            'sdr_delay_segundos'          => 'sometimes|integer|min:5|max:86400',
            'followup_estagio1_segundos'  => 'sometimes|integer|min:60|max:604800',
            'followup_estagio2_segundos'  => 'sometimes|integer|min:60|max:604800',
            'followup_estagio3_segundos'  => 'sometimes|integer|min:60|max:604800',
            'auto_mover_ativo'            => 'sometimes|boolean',
            'auto_mover_coluna_destino'   => [
                'sometimes', 'nullable', 'string',
                Rule::in(KanbanColuna::chavesDoTenant($request->user()->tenant_id, $kanban->id)),
            ],
            'auto_mover_segundos'         => 'sometimes|integer|min:60|max:31536000',
            'auto_mover_mensagem'         => 'nullable|string|max:1000',
            'exclusao_definitiva_ativo'   => 'sometimes|boolean',
            'exclusao_definitiva_dias'    => 'sometimes|integer|min:1|max:3650',
            'timeout_reassuncao_ativo'    => 'sometimes|boolean',
            'timeout_reassuncao_segundos' => 'sometimes|integer|min:60|max:604800',
            'aguardando_orientacao_mensagem' => 'nullable|string|max:1000',
            'tempo_maximo_permanencia_minutos' => 'sometimes|nullable|integer|min:1|max:43200',
            'duvida_timeout_ativo'    => 'sometimes|boolean',
            'duvida_timeout_segundos' => 'sometimes|integer|min:60|max:604800',
        ]);

        $update = array_filter($validated, fn($v) => $v !== null);

        // tempo_maximo_permanencia_minutos: null é um valor válido e intencional pra
        // esse campo específico (= coluna não monitorada, Regra 12) — diferente dos
        // outros campos nullable acima, não pode ser descartado pelo array_filter.
        if (array_key_exists('tempo_maximo_permanencia_minutos', $validated)) {
            $update['tempo_maximo_permanencia_minutos'] = $validated['tempo_maximo_permanencia_minutos'];
        }

        $tenantId = $request->user()->tenant_id;

        // Linhas gravadas antes do kanban_coluna_id existir (ou que o backfill não
        // alcançou) ainda têm esse campo null — tratamos como a mesma linha pela
        // chave antiga (coluna_kanban) em vez de criar uma duplicata, e já
        // completamos o kanban_coluna_id nela (auto-cura).
        $configExistente = KanbanColunaConfig::where('tenant_id', $tenantId)
            ->where(function ($query) use ($colunaReal, $coluna) {
                $query->where('kanban_coluna_id', $colunaReal->id)
                    ->orWhere(function ($query2) use ($coluna) {
                        $query2->whereNull('kanban_coluna_id')->where('coluna_kanban', $coluna);
                    });
            })
            ->first();

        $dadosFinais = array_merge($update, ['coluna_kanban' => $coluna, 'kanban_coluna_id' => $colunaReal->id]);

        if ($configExistente) {
            $configExistente->update($dadosFinais);
        } else {
            KanbanColunaConfig::create(array_merge($dadosFinais, ['tenant_id' => $tenantId]));
        }

        return response()->json(['ok' => true]);
    }
}
