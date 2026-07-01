<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->ativo) {
            if ($request->expectsJson()) {
                return response()->json(['erro' => 'Acesso negado.'], 403);
            }
            return redirect()->route('dashboard')->with('erro', 'Acesso negado.');
        }

        // 'gestor' é alias legado de 'gerente'
        $perfil = $user->perfil === 'gestor' ? 'gerente' : $user->perfil;

        if (! in_array($perfil, $roles, true)) {
            if ($request->expectsJson()) {
                return response()->json(['erro' => 'Seu perfil não tem acesso a esta área.'], 403);
            }
            abort(403, 'Seu perfil não tem acesso a esta área.');
        }

        return $next($request);
    }
}
