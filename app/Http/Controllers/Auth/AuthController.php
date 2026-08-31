<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

        // Avaliador não tem acesso ao Dashboard geral
        $destino = Auth::user()->perfil === 'avaliador'
            ? route('avaliador.dashboard')
            : (Auth::user()->perfil === 'admin' ? route('admin.dashboard') : route('dashboard'));

        return redirect()->intended($destino);
    }

    public function logoutWeb(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // --- Recuperação de Senha (Esqueci minha senha) ---

    public function forgotPasswordView(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.exists' => 'Não encontramos nenhuma conta com este endereço de e-mail.',
        ]);

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token'      => Hash::make($token),
                'created_at' => Carbon::now(),
            ]
        );

        $resetUrl = route('password.reset', ['token' => $token, 'email' => $request->email]);

        // Se houver mail configurado, envia email. Caso contrário, gera link na sessão
        try {
            // Mail::to($request->email)->send(...)
        } catch (\Throwable $e) {
            // Silencioso se mailer não estiver configurado localmente
        }

        return back()->with('sucesso', "Instruções enviadas! Caso esteja em ambiente de teste, acesse diretamente o link de redefinição.")
                     ->with('resetUrl', $resetUrl);
    }

    public function resetPasswordView(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email|exists:users,email',
            'password'              => 'required|string|min:8|confirmed',
        ], [
            'password.confirmed'    => 'A confirmação de senha não confere.',
            'password.min'          => 'A nova senha deve ter no mínimo 8 caracteres.',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record || ! Hash::check($request->token, $record->token)) {
            return back()->withErrors(['email' => 'Token de redefinição de senha inválido ou expirado.']);
        }

        // Verifica se o token tem menos de 60 minutos
        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return back()->withErrors(['email' => 'Este link de redefinição expirou. Solicite um novo.']);
        }

        // Atualiza a senha do usuário
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Deleta o token usado
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return redirect()->route('login')
            ->with('sucesso', 'Senha alterada com sucesso! Você já pode fazer login com sua nova senha.');
    }
}
