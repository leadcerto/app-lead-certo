<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\KanbanColunaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanColunaConfigController extends Controller
{
    public function show(Request $request, string $coluna): JsonResponse
    {
        $config = KanbanColunaConfig::where('tenant_id', $request->user()->tenant_id)
            ->where('coluna_kanban', $coluna)
            ->first();

        return response()->json([
            'coluna_kanban'      => $coluna,
            'objetivo'           => $config?->objetivo           ?? '',
            'seq_objetivo'       => $config?->seq_objetivo       ?? '',
            'ia_objetivo'        => $config?->ia_objetivo        ?? '',
            'ia_contexto'        => $config?->ia_contexto        ?? '',
            'ia_ativo'           => $config?->ia_ativo           ?? false,
            'sdr_delay_segundos' => $config?->sdr_delay_segundos ?? 45,
            'button_settings'    => $config?->button_settings    ?? [],
        ]);
    }

    public function update(Request $request, string $coluna): JsonResponse
    {
        $validated = $request->validate([
            'objetivo'            => 'nullable|string|max:1000',
            'seq_objetivo'        => 'nullable|string|max:1000',
            'ia_objetivo'         => 'nullable|string|max:1000',
            'ia_contexto'         => 'nullable|string|max:50000',
            'ia_ativo'            => 'sometimes|boolean',
            'sdr_delay_segundos'  => 'sometimes|integer|min:5|max:86400',
            'button_settings'     => 'sometimes|array|max:3',
            'button_settings.*.text'   => 'required_with:button_settings|string|max:20',
            'button_settings.*.action' => 'required_with:button_settings|string|in:move_column,trigger_ia,opt_out,open_url,call',
            'button_settings.*.target' => 'nullable|string|max:255',
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
