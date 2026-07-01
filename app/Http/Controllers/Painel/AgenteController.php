<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\ConviteAgente;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AgenteController extends Controller
{
    public function view(): View
    {
        return view('configuracoes.agentes');
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $agentes = User::where('tenant_id', $tenantId)
            ->where('id', '!=', $request->user()->id)
            ->orderBy('nome')
            ->get(['id', 'nome', 'email', 'perfil', 'ativo']);

        $convites = ConviteAgente::where('tenant_id', $tenantId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'email', 'perfil', 'nome', 'expires_at', 'token']);

        return response()->json([
            'agentes' => $agentes,
            'convites' => $convites,
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:200',
            'perfil' => 'required|string|in:dono,diretor,gerente,gestor,vendedor,auditor,growth_manager,revops,pos_venda',
            'nome'  => 'nullable|string|max:150',
        ]);

        $tenantId = $request->user()->tenant_id;

        if (User::where('tenant_id', $tenantId)->where('email', $request->email)->exists()) {
            return response()->json(['message' => 'Este e-mail já pertence a um agente da equipe.'], 409);
        }

        // Invalidate previous pending invite for same email
        ConviteAgente::where('tenant_id', $tenantId)
            ->where('email', $request->email)
            ->whereNull('accepted_at')
            ->delete();

        $convite = ConviteAgente::create([
            'tenant_id'  => $tenantId,
            'email'      => $request->email,
            'perfil'     => $request->perfil,
            'nome'       => $request->nome,
            'token'      => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);

        $link = url("/convite/{$convite->token}");

        return response()->json(['convite_id' => $convite->id, 'link' => $link], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'perfil' => 'required|string|in:dono,diretor,gerente,gestor,vendedor,auditor,growth_manager,revops,pos_venda',
            'ativo'  => 'boolean',
        ]);

        $tenantId = $request->user()->tenant_id;

        $agente = User::where('tenant_id', $tenantId)->findOrFail($id);
        $agente->update($request->only(['perfil', 'ativo']));

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $agente = User::where('tenant_id', $tenantId)->findOrFail($id);
        $agente->update(['ativo' => false]);

        return response()->json(['ok' => true]);
    }

    public function destroyConvite(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        ConviteAgente::where('tenant_id', $tenantId)->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Public: aceitar convite ───────────────────────────────────────────────

    public function aceitarForm(string $token): View|RedirectResponse
    {
        $convite = ConviteAgente::where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return view('convite.aceitar', compact('convite', 'token'));
    }

    public function aceitarStore(Request $request, string $token): RedirectResponse
    {
        $convite = ConviteAgente::where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $request->validate([
            'nome'     => 'required|string|max:150',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = User::where('email', $convite->email)->exists()
            ? null
            : $convite->email;

        if (! $email) {
            return back()->withErrors(['email' => 'Este e-mail já está registrado. Faça login.']);
        }

        User::create([
            'tenant_id' => $convite->tenant_id,
            'nome'      => $request->nome ?? $convite->nome ?? '',
            'email'     => $convite->email,
            'password'  => Hash::make($request->password),
            'perfil'    => $convite->perfil,
            'ativo'     => true,
        ]);

        $convite->update(['accepted_at' => now()]);

        return redirect('/login')->with('status', 'Conta criada com sucesso! Faça login.');
    }
}
