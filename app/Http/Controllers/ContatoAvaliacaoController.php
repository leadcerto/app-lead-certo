<?php

namespace App\Http\Controllers;

use App\Models\ContatoAvaliacao;
use App\Models\PerfilGmb;
use Illuminate\Http\Request;

/**
 * Lista de telefones de clientes reais que o avaliador vai ligar, vinculada
 * a um Perfil GMB — não a um agendamento específico (decisão de 2026-08-17:
 * o avaliador escolhe da lista quem ainda não foi contatado, sem vínculo
 * automático com a data agendada).
 */
class ContatoAvaliacaoController extends Controller
{
    public function index(Request $request, PerfilGmb $perfil)
    {
        abort_if($perfil->tenant_id !== $request->user()->tenant_id, 403);

        $contatos = $perfil->contatos()->orderByDesc('created_at')->get();

        return view('admin.perfis-gmb.contatos', compact('perfil', 'contatos'));
    }

    /**
     * Adiciona contatos em lote: uma linha por contato, no formato
     * "Nome, Telefone" ou só "Telefone" (nome fica em branco).
     */
    public function store(Request $request, PerfilGmb $perfil)
    {
        abort_if($perfil->tenant_id !== $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'lista' => 'required|string',
        ]);

        $criados = 0;
        foreach (preg_split('/\r\n|\r|\n/', $validated['lista']) as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            $partes    = array_map('trim', explode(',', $linha, 2));
            $telefone  = count($partes) > 1 ? $partes[1] : $partes[0];
            $nome      = count($partes) > 1 ? $partes[0] : null;

            if ($telefone === '') {
                continue;
            }

            ContatoAvaliacao::create([
                'tenant_id' => $perfil->tenant_id,
                'perfil_id' => $perfil->id,
                'nome'      => $nome,
                'telefone'  => $telefone,
            ]);
            $criados++;
        }

        return redirect()->route('admin.perfis-gmb.contatos.index', $perfil)
            ->with('sucesso', "{$criados} contato(s) adicionado(s).");
    }

    public function destroy(Request $request, ContatoAvaliacao $contato)
    {
        abort_if($contato->tenant_id !== $request->user()->tenant_id, 403);

        $perfil = $contato->perfil_id;
        $contato->delete();

        return redirect()->route('admin.perfis-gmb.contatos.index', $perfil)
            ->with('sucesso', 'Contato removido.');
    }
}
