<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Service-Key');

        if (! $key || $key !== config('app.service_key')) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
