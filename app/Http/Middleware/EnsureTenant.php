<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Admin (tenant_id null) acessa tudo; pode filtrar por ?tenant_id=X
        if ($user->isAdmin()) {
            $tenantId = $request->query('tenant_id');
        } else {
            $tenantId = $user->tenant_id;
        }

        if ($tenantId) {
            session(['tenant_id' => $tenantId]);
            $request->attributes->set('tenant_id', $tenantId);
        }

        return $next($request);
    }
}
