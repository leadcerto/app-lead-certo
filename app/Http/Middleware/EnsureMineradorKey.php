<?php

namespace App\Http\Middleware;

use App\Models\AgenteMinerador;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMineradorKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $request->header('X-Minerador-Key');

        if (! $rawKey) {
            return response()->json(['erro' => 'Chave de minerador obrigatória.'], 401);
        }

        $agente = AgenteMinerador::encontrarPorChave($rawKey);

        if (! $agente) {
            return response()->json(['erro' => 'Chave de minerador inválida ou inativa.'], 401);
        }

        // Injeta contexto do agente no request para uso nos controllers
        $request->merge([
            '_agente_id'      => $agente->id,
            '_agente_tipo'    => $agente->tipo,
            '_agente_tenant'  => $agente->tenant_id,
            '_campanha_id'    => $agente->campanha_id,
        ]);

        // Atualiza horário da última execução
        $agente->update(['ultima_execucao_em' => now()]);

        return $next($request);
    }
}
