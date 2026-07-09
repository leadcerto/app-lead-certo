<?php

use Illuminate\Support\Facades\Schedule;

// Sincroniza contatos do Google para todos os tenants a cada 6 horas
// Delta sync: só busca novos/alterados desde o último sync via SyncToken
Schedule::command('contatos:sincronizar-google')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/google-sync.log'));

// 00:01 — Atualiza lista de modelos gratuitos do OpenRouter
// Detecta e loga quando modelos saem ou entram no plano gratuito
Schedule::command('openrouter:atualizar-modelos')
    ->dailyAt('00:01')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/openrouter-modelos.log'));

// 00:05 — Identifica nomes de contatos "Sem Nome" lendo conversas (usa modelos atualizados acima)
Schedule::command('contatos:identificar-nomes --limit=20')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/identificar-nomes.log'));

// 00:10 — Limpa nomes com números embutidos e corrige capitalização via IA
Schedule::command('contatos:limpar-nomes --lote=30')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/limpar-nomes.log'));

// A cada 5 min — Follow-up para leads que pararam de responder (10min curto / 12h longo)
Schedule::command('conversas:followup')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/followup-conversas.log'));

// 00:15 — Enriquece contatos com email, profissão e empresa extraídos das conversas via IA
Schedule::command('contatos:enriquecer-conversas --limit=30')
    ->dailyAt('00:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/enriquecer-contatos.log'));

// 02:00 — Deleta tickets/mensagens mais antigos que o limite de retenção por tenant
Schedule::command('conversas:limpar-antigas')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/limpar-conversas.log'));
