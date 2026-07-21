<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KanbanColunaConfigController extends Controller
{
    public function show(Request $request, string $coluna): JsonResponse
    {
        $config = KanbanColunaConfig::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->first();

        return response()->json([
            'coluna_kanban'               => $coluna,
            'objetivo'                    => $config?->objetivo                    ?? '',
            'seq_objetivo'                => $config?->seq_objetivo                ?? '',
            'ia_objetivo'                 => $config?->ia_objetivo                 ?? '',
            'ia_contexto'                 => $config?->ia_contexto                 ?? '',
            'foco_analise_imagem'         => $config?->foco_analise_imagem         ?? '',
            'ia_ativo'                    => $config?->ia_ativo                    ?? false,
            'sdr_delay_segundos'          => $config?->sdr_delay_segundos          ?? 45,
            'followup_estagio1_segundos'  => $config?->followup_estagio1_segundos  ?? 3600,
            'followup_estagio2_segundos'  => $config?->followup_estagio2_segundos  ?? 7200,
            'followup_estagio3_segundos'  => $config?->followup_estagio3_segundos  ?? 21600,
            'auto_mover_ativo'            => $config?->auto_mover_ativo            ?? false,
            'auto_mover_coluna_destino'   => $config?->auto_mover_coluna_destino   ?? '',
            'auto_mover_segundos'         => $config?->auto_mover_segundos         ?? 259200,
            'auto_mover_mensagem'         => $config?->auto_mover_mensagem         ?? '',
        ]);
    }

    public function update(Request $request, string $coluna): JsonResponse
    {
        $validated = $request->validate([
            'objetivo'                    => 'nullable|string|max:1000',
            'seq_objetivo'                => 'nullable|string|max:1000',
            'ia_objetivo'                 => 'nullable|string|max:1000',
            'ia_contexto'                 => 'nullable|string|max:50000',
            'foco_analise_imagem'         => 'nullable|string|max:1000',
            'ia_ativo'                    => 'sometimes|boolean',
            'sdr_delay_segundos'          => 'sometimes|integer|min:5|max:86400',
            'followup_estagio1_segundos'  => 'sometimes|integer|min:60|max:604800',
            'followup_estagio2_segundos'  => 'sometimes|integer|min:60|max:604800',
            'followup_estagio3_segundos'  => 'sometimes|integer|min:60|max:604800',
            'auto_mover_ativo'            => 'sometimes|boolean',
            'auto_mover_coluna_destino'   => [
                'sometimes', 'nullable', 'string',
                Rule::in(KanbanColuna::chavesDoTenant($request->user()->tenant_id)),
            ],
            'auto_mover_segundos'         => 'sometimes|integer|min:60|max:31536000',
            'auto_mover_mensagem'         => 'nullable|string|max:1000',
        ]);

        $update = array_filter($validated, fn($v) => $v !== null);

        KanbanColunaConfig::updateOrCreate(
            [
                'tenant_id'     => $request->user()->tenant_id,
                'coluna_kanban' => $coluna,
            ],
            $update
        );

        return response()->json(['ok' => true]);
    }
}
