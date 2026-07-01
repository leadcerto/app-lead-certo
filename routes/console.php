<?php

use Illuminate\Support\Facades\Schedule;

// Sincroniza contatos do Google para todos os tenants a cada 6 horas
// Delta sync: só busca novos/alterados desde o último sync via SyncToken
Schedule::command('contatos:sincronizar-google')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/google-sync.log'));
