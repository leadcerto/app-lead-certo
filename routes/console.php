<?php

use Illuminate\Support\Facades\Schedule;

// Sincroniza contatos do Google para todos os tenants a cada 15 minutos
// Delta sync: só busca novos/alterados desde o último sync via SyncToken —
// intervalo reduzido de 6h pra 15min (pedido do Leonardo, 2026-08-26: "time
// é fundamental" pro lead inicial; o delta sync é barato o suficiente pra
// rodar bem mais frequente sem pesar na cota da API do Google).
Schedule::command('contatos:sincronizar-google')
    ->everyFifteenMinutes()
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

// A cada 5 min — Reassume conversas onde o humano assumiu e sumiu além do timeout
Schedule::command('conversas:reassumir-agente')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/reassumir-agente.log'));

// A cada 5 min — Expira pausas de dúvida (Regra 2) não respondidas a tempo
Schedule::command('conversas:expirar-pausa-orientacao')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/expirar-pausa-orientacao.log'));

// A cada 15 min — Alerta tickets travados além do tempo máximo por coluna (Regra 3/12)
Schedule::command('kanban:monitorar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/kanban-monitorar.log'));

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

// A cada 2h — Posta reação casual em grupo de aquecimento (pedido do Leonardo, 2026-08-19)
// pra número não-oficial não ser expulso de grupo por inatividade. O próprio comando
// respeita o bloqueio de madrugada (23h-7h) e o teto de 1 post por grupo por dia.
Schedule::command('whatsapp:aquecimento-grupos')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/aquecimento-grupos.log'));

// Sábado 00:00 — Relatório semanal do Gestor do Kanban (números + análise + sugestão de prompt por coluna)
Schedule::command('kanban:gestor-semanal')
    ->weeklyOn(6, '00:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/gestor-kanban-semanal.log'));

// 08:00 — Verifica agendamentos de avaliação GMB em atraso e alerta avaliadores + admin
Schedule::command('avaliadores:checar-atraso')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/avaliadores-atraso.log'));

// A cada 1 minuto - Publica posts agendados do Google Meu Negócio cujo horário já chegou
Schedule::command('gmb:publicar-posts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/gmb-publicar-posts.log'));
