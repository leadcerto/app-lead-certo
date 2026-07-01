<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Mensagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MensagemController extends Controller
{
    public function salvar(Request $request): JsonResponse
    {
        $request->validate([
            'ticket_id'  => 'required|integer|exists:tickets_atendimento,id',
            'tenant_id'  => 'required|integer|exists:tenants,id',
            'remetente'  => 'required|in:lead,bot,humano',
            'tipo'       => 'required|in:texto,imagem,audio,video,documento',
            'conteudo'   => 'nullable|string',
        ]);

        $mensagem = Mensagem::create($request->only([
            'ticket_id', 'tenant_id', 'remetente', 'tipo', 'conteudo',
        ]));

        return response()->json(['mensagem_id' => $mensagem->id], 201);
    }
}
