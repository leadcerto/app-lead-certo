<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cadastro de avaliadores (dentro do módulo Google Meu Negócio) — não é um
 * CRUD geral de usuários, só perfil 'avaliador'.
 */
class AvaliadorController extends Controller
{
    public function index(Request $request)
    {
        $avaliadores = User::where('tenant_id', $request->user()->tenant_id)
            ->where('perfil', 'avaliador')
            ->withCount('agendamentosAvaliacao')
            ->orderBy('nome')
            ->paginate(20);

        return view('admin.avaliadores.index', compact('avaliadores'));
    }

    public function create()
    {
        return view('admin.avaliadores.create', [
            'senhaSugerida' => Str::password(12, symbols: false),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'nome'     => 'required|string|max:200',
            'email'    => ['required', 'email', 'max:200', Rule::unique('users', 'email')],
            'whatsapp' => 'nullable|string|max:30',
            'senha'    => 'required|string|min:8|max:100',
            'city'     => 'required|string|max:100',
            'state'    => 'required|string|size:2',
        ]);

        User::create([
            'tenant_id' => $tenantId,
            'nome'      => $validated['nome'],
            'email'     => $validated['email'],
            'whatsapp'  => $validated['whatsapp'] ?? null,
            'password'  => Hash::make($validated['senha']),
            'perfil'    => 'avaliador',
            'city'      => $validated['city'],
            'state'     => strtoupper($validated['state']),
            'ativo'     => true,
        ]);

        return redirect()->route('admin.avaliadores.index')
            ->with('sucesso', 'Avaliador cadastrado com sucesso!');
    }

    /**
     * Nome do parâmetro precisa casar com o nome gerado pela rota
     * (ver PerfilGmbController — mesmo cuidado com binding implícito).
     */
    public function edit(Request $request, User $avaliador)
    {
        abort_if($avaliador->tenant_id !== $request->user()->tenant_id || $avaliador->perfil !== 'avaliador', 403);

        return view('admin.avaliadores.edit', compact('avaliador'));
    }

    public function update(Request $request, User $avaliador)
    {
        abort_if($avaliador->tenant_id !== $request->user()->tenant_id || $avaliador->perfil !== 'avaliador', 403);

        $validated = $request->validate([
            'nome'     => 'required|string|max:200',
            'email'    => ['required', 'email', 'max:200', Rule::unique('users', 'email')->ignore($avaliador->id)],
            'whatsapp' => 'nullable|string|max:30',
            'senha'    => 'nullable|string|min:8|max:100',
            'city'     => 'required|string|max:100',
            'state'    => 'required|string|size:2',
            'ativo'    => 'boolean',
        ]);

        $avaliador->nome     = $validated['nome'];
        $avaliador->email    = $validated['email'];
        $avaliador->whatsapp = $validated['whatsapp'] ?? null;
        $avaliador->city     = $validated['city'];
        $avaliador->state    = strtoupper($validated['state']);
        $avaliador->ativo    = $request->has('ativo');

        if (!empty($validated['senha'])) {
            $avaliador->password = Hash::make($validated['senha']);
        }

        $avaliador->save();

        return redirect()->route('admin.avaliadores.index')
            ->with('sucesso', 'Avaliador atualizado com sucesso!');
    }

    public function destroy(Request $request, User $avaliador)
    {
        abort_if($avaliador->tenant_id !== $request->user()->tenant_id || $avaliador->perfil !== 'avaliador', 403);

        $avaliador->update(['ativo' => false]);

        return redirect()->route('admin.avaliadores.index')
            ->with('sucesso', 'Avaliador desativado.');
    }
}
