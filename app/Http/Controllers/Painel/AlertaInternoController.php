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

        $alertas = AlertaInterno::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate(20);

        $naoLidos = AlertaInterno::where('tenant_id', $tenantId)
            ->whereNull('lido_em')
            ->count();

        return response()->json([
            'data'            => $alertas->items(),
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
