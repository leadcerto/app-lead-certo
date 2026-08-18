<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        if (! $user->ativo) {
            return response()->json(['message' => 'Sua conta está inativa. Entre em contato com o suporte.'], 403);
        }

        $token = $user->createToken('painel')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'        => $user->id,
                'nome'      => $user->nome,
                'perfil'    => $user->perfil,
                'tenant_id' => $user->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado.']);
    }

    // --- Web (sessão Blade) ---

    public function loginWeb(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user && ! $user->ativo) {
            return back()->withErrors(['email' => 'Sua conta está inativa. Entre em contato com o suporte.']);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
        }

        $request->session()->regenerate();
        session(['tenant_id' => Auth::user()->tenant_id]);

        // Avaliador não tem acesso ao Dashboard geral — manda direto pro
        // painel dele em vez de cair numa tela que não faz sentido pro perfil.
        $destino = Auth::user()->perfil === 'avaliador'
            ? route('avaliador.dashboard')
            : route('dashboard');

        return redirect()->intended($destino);
    }

    public function logoutWeb(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
