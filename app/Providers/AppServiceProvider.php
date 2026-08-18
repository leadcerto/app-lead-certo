<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Achado ao vivo (2026-08-18): produção não tinha APP_LOCALE
        // configurado, e Carbon::translatedFormat() caía no default do
        // framework (inglês) — dia da semana aparecia "TUESDAY" em vez de
        // "terça-feira" no painel do avaliador. Fixar aqui garante o idioma
        // certo mesmo que o .env fique desconfigurado de novo.
        Carbon::setLocale(config('app.locale', 'pt_BR'));
    }
}
