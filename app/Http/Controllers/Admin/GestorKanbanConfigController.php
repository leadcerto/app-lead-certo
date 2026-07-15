<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GestorKanbanConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GestorKanbanConfigController extends Controller
{
    public function view(): View
    {
        return view('admin.gestor-kanban', [
            'config' => GestorKanbanConfig::first(),
        ]);
    }

    public function show(): JsonResponse
    {
        $config = GestorKanbanConfig::first();

        return response()->json([
            'prompt_coluna'  => $config->prompt_coluna,
            'prompt_sintese' => $config->prompt_sintese,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'prompt_coluna'  => 'required|string',
            'prompt_sintese' => 'required|string',
        ]);

        $config = GestorKanbanConfig::first();
        $config->update([
            'prompt_coluna'  => $request->prompt_coluna,
            'prompt_sintese' => $request->prompt_sintese,
            'updated_by'     => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }
}
