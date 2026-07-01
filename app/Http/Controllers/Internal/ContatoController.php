<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Contato;
use App\Models\VinculoContatoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContatoController extends Controller
{
    public function upsert(Request $request): JsonResponse
    {
        $request->validate([
            'telefone'  => 'required|string|max:20',
            'origem'    => 'required|string|max:50',
            'tenant_id' => 'required|integer|exists:tenants,id',
        ]);

        $telefone = preg_replace('/\D/', '', $request->telefone);
        $nome     = trim($request->nome ?? '') ?: null;

        $contato = Contato::firstOrCreate(
            ['telefone' => $telefone],
            ['nome' => $nome, 'origem' => $request->origem, 'opt_out' => false]
        );

        if ($contato->opt_out) {
            return response()->json(['opt_out' => true]);
        }

        $vinculo = VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $request->tenant_id,
        ]);

        if (! $contato->wasRecentlyCreated && $nome) {
            if (! $contato->nome) {
                // Contato existia sem nome → atualiza master (seguro)
                $contato->update(['nome' => $nome]);
            } elseif (strtolower(trim($nome)) !== strtolower(trim($contato->nome))) {
                // pushName difere do master → fila de auditoria, master intacto
                $vinculo->update([
                    'nome_sugerido'      => $nome,
                    'auditoria_pendente' => true,
                ]);
            }
        }

        return response()->json([
            'contato_id'         => $contato->id,
            'opt_out'            => false,
            'novo'               => $contato->wasRecentlyCreated,
            'nome'               => $contato->nome,
            'auditoria_pendente' => (bool) $vinculo->auditoria_pendente,
        ]);
    }
}
