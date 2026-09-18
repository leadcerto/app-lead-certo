<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'service.key' => \App\Http\Middleware\EnsureServiceKey::class,
            'tenant'      => \App\Http\Middleware\EnsureTenant::class,
            'role'        => \App\Http\Middleware\CheckRole::class,
            'minerador'   => \App\Http\Middleware\EnsureMineradorKey::class,
        ]);

        // Achado real 2026-09-17: sem prioridade explícita, o Laravel roda
        // SubstituteBindings (resolve {model} da URL) ANTES de EnsureTenant
        // — nesse momento session('tenant_id')/request attribute ainda não
        // foram setados, então TenantScope não filtra nada e um id de
        // recurso de OUTRA empresa resolve normalmente via route-model-
        // binding implícito (achado em testes reais no MetaPostController,
        // que permitiam cancelar/publicar posts de outro tenant só sabendo
        // o id). EnsureTenant e CheckRole precisam rodar ANTES de
        // SubstituteBindings pra que o escopo por tenant já esteja ativo
        // quando o Laravel resolve o model da rota.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \App\Http\Middleware\EnsureTenant::class,
            \App\Http\Middleware\CheckRole::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
