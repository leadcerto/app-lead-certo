<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Mensagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidiaController extends Controller
{
    private const ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'mp4', 'ogg', 'pdf'];
    private const MAX_SIZE_KB   = 10240; // 10MB

    public function salvar(Request $request): JsonResponse
    {
        $request->validate([
            'ticket_id'  => 'required|integer|exists:tickets_atendimento,id',
            'tenant_id'  => 'required|integer|exists:tenants,id',
            'remetente'  => 'required|in:lead,bot,humano',
            'tipo'       => 'required|in:imagem,audio,video,documento',
            'arquivo'    => 'required|file|max:' . self::MAX_SIZE_KB,
        ]);

        $arquivo = $request->file('arquivo');
        $ext = strtolower($arquivo->getClientOriginalExtension());

        if (! in_array($ext, self::ALLOWED_TYPES)) {
            return response()->json(['message' => 'Tipo de arquivo não permitido.'], 422);
        }

        $path = $arquivo->store(
            "midias/{$request->tenant_id}/{$request->ticket_id}",
            'public'
        );

        $mensagem = Mensagem::create([
            'ticket_id'  => $request->ticket_id,
            'tenant_id'  => $request->tenant_id,
            'remetente'  => $request->remetente,
            'tipo'       => $request->tipo,
            'conteudo'   => null,
            'midia_url'  => '/storage/' . $path,
        ]);

        return response()->json([
            'mensagem_id' => $mensagem->id,
            'midia_url'   => $mensagem->midia_url,
        ], 201);
    }
}
