<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\FeedbackAgente;
use App\Services\MediaProcessorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Redesenho 2026-08-20 (Leonardo): um bloco por agente (foto + composer
 * estilo WhatsApp interno) na tela de Suporte — cliente escreve, manda
 * imagem/arquivo/áudio (áudio vira texto transcrito), o agente só responde
 * em texto. Por trás continua roteado por SETOR (cargo), não por pessoa.
 */
class FeedbackAgenteController extends Controller
{
    public function setores(): View
    {
        // Um bloco por combinação (agente, cargo visível) — hoje só existe
        // Adriana/Gerente de Suporte, mas o organograma pode crescer sem
        // mudar essa tela.
        $blocos = Cargo::where('visivel_para_clientes', true)
            ->where('ativo', true)
            ->with('agentes')
            ->orderBy('ordem')
            ->get()
            ->flatMap(fn (Cargo $cargo) => $cargo->agentes->isNotEmpty()
                ? $cargo->agentes->map(fn ($agente) => ['cargo' => $cargo, 'agente' => $agente])
                : [['cargo' => $cargo, 'agente' => null]]
            );

        $historicos = FeedbackAgente::whereIn('cargo_id', $blocos->pluck('cargo.id')->unique())
            ->where('tenant_id', request()->user()->tenant_id)
            ->orderBy('created_at')
            ->get()
            ->groupBy('cargo_id');

        return view('equipe.setores', compact('blocos', 'historicos'));
    }

    public function store(Request $request, int $cargo, MediaProcessorService $media): RedirectResponse
    {
        $setor = Cargo::where('visivel_para_clientes', true)->findOrFail($cargo);

        $validated = $request->validate([
            'mensagem' => 'nullable|string|max:2000',
            'arquivo'  => [
                'nullable', 'file', 'max:16384',
                Rule::when($request->hasFile('arquivo'), 'mimes:jpg,jpeg,png,webp,mp3,ogg,webm,m4a,wav,pdf,doc,docx,xls,xlsx,txt,zip'),
            ],
        ]);

        if (empty($validated['mensagem']) && ! $request->hasFile('arquivo')) {
            return back()->withErrors(['mensagem' => 'Escreva uma mensagem ou anexe um arquivo.']);
        }

        $mensagem  = $validated['mensagem'] ?? '';
        $midiaUrl  = null;
        $tipoMidia = null;

        if ($arquivo = $request->file('arquivo')) {
            $extensao = strtolower($arquivo->getClientOriginalExtension());
            $ehAudio  = in_array($extensao, ['mp3', 'ogg', 'webm', 'm4a', 'wav'], true);
            $ehImagem = in_array($extensao, ['jpg', 'jpeg', 'png', 'webp'], true);

            $midiaUrl  = \Illuminate\Support\Facades\Storage::disk('public')->url($arquivo->store('feedback-agente', 'public'));
            $tipoMidia = $ehAudio ? 'audio' : ($ehImagem ? 'imagem' : 'arquivo');

            if ($ehAudio) {
                $transcricao = $media->transcreverArquivo(file_get_contents($arquivo->getRealPath()), $arquivo->getMimeType());
                $mensagem    = trim($mensagem . ' ' . ($transcricao ?: '[áudio recebido — não foi possível transcrever]'));
            } elseif ($mensagem === '') {
                $mensagem = $ehImagem ? '[Imagem enviada]' : '[Arquivo enviado]';
            }
        }

        // Quem responde de verdade é quem ocupa o cargo hoje — se tiver
        // mais de um agente no mesmo setor, fica com o primeiro.
        $agente = $setor->agentes()->first();

        FeedbackAgente::create([
            'user_id'       => $agente?->id,
            'cargo_id'      => $setor->id,
            'tenant_id'     => $request->user()->tenant_id,
            'autor_user_id' => $request->user()->id,
            'mensagem'      => $mensagem,
            'midia_url'     => $midiaUrl,
            'tipo_midia'    => $tipoMidia,
            'resposta'      => FeedbackAgente::RESPOSTA_PADRAO,
        ]);

        return back()->with('sucesso', 'Mensagem enviada!');
    }
}
