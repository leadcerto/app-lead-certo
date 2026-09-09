<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ids = [4634, 4635, 4636, 4638, 2749];

echo "\n=== TICKETS PROBLEMÁTICOS ===\n";
foreach ($ids as $id) {
    $t = \App\Models\TicketAtendimento::withoutGlobalScopes()
        ->with(['contato', 'tenant'])
        ->find($id);

    if (!$t) {
        echo "Ticket #{$id}: NÃO ENCONTRADO\n";
        continue;
    }

    $msgBot = \App\Models\Mensagem::withoutGlobalScopes()
        ->where('ticket_id', $id)
        ->where('remetente', 'bot')
        ->count();

    $msgLead = \App\Models\Mensagem::withoutGlobalScopes()
        ->where('ticket_id', $id)
        ->where('remetente', 'lead')
        ->count();

    $ultimaMsgLead = \App\Models\Mensagem::withoutGlobalScopes()
        ->where('ticket_id', $id)
        ->where('remetente', 'lead')
        ->orderByDesc('enviado_em')
        ->first();

    $ultimaMsgBot = \App\Models\Mensagem::withoutGlobalScopes()
        ->where('ticket_id', $id)
        ->where('remetente', 'bot')
        ->orderByDesc('enviado_em')
        ->first();

    // Verificar se há sequência ativa para a coluna do ticket
    $sequencias = \App\Models\Sequencia::withoutGlobalScopes()
        ->where('tenant_id', $t->tenant_id)
        ->where('coluna_kanban', $t->coluna_kanban)
        ->where('ativo', true)
        ->get();

    // Jobs pendentes (agendados)
    $jobsPendentes = \Illuminate\Support\Facades\DB::table('jobs')
        ->where('payload', 'like', "%{$id}%")
        ->count();

    echo "\n--- Ticket #{$id} ---\n";
    echo "  Tenant: " . ($t->tenant->nome ?? 'N/A') . " (ID: {$t->tenant_id})\n";
    echo "  Contato: " . ($t->contato->nome ?? 'N/A') . " | Tel: " . ($t->contato->telefone ?? 'N/A') . "\n";
    echo "  Coluna: {$t->coluna_kanban}\n";
    echo "  Agente: {$t->agente_responsavel}\n";
    echo "  Status: {$t->status}\n";
    echo "  Criado em: {$t->aberto_em}\n";
    echo "  Msgs bot: {$msgBot} | Msgs lead: {$msgLead}\n";
    echo "  Última msg lead: " . ($ultimaMsgLead?->enviado_em ?? 'N/A') . " | Conteúdo: " . substr($ultimaMsgLead?->conteudo ?? '', 0, 60) . "\n";
    echo "  Última msg bot: " . ($ultimaMsgBot?->enviado_em ?? 'N/A') . "\n";
    echo "  Sequências na coluna: " . $sequencias->count() . "\n";
    echo "  Jobs pendentes na fila: {$jobsPendentes}\n";

    if ($sequencias->count() > 0) {
        foreach ($sequencias as $seq) {
            $totalMsgs = $seq->mensagens()->where('ativo', true)->count();
            echo "    Sequência '{$seq->nome}': {$totalMsgs} mensagens\n";
        }
    }
}

echo "\n=== JOBS PENDENTES NA FILA (SdrResponder & Sequencia) ===\n";
$jobs = \Illuminate\Support\Facades\DB::table('jobs')
    ->where(function($q) {
        $q->where('payload', 'like', '%SdrResponderJob%')
          ->orWhere('payload', 'like', '%SequenciaMensagemJob%');
    })
    ->get();

echo "Total de jobs pendentes: " . $jobs->count() . "\n";
foreach ($jobs->groupBy(function($j) {
    $p = json_decode($j->payload, true);
    return $p['displayName'] ?? 'Desconhecido';
}) as $tipo => $grupo) {
    echo "  {$tipo}: " . $grupo->count() . " jobs\n";
}

echo "\n=== FAILED JOBS (últimas 20) ===\n";
$failed = \Illuminate\Support\Facades\DB::table('failed_jobs')
    ->orderByDesc('failed_at')
    ->limit(20)
    ->get();

foreach ($failed as $j) {
    $p = json_decode($j->payload, true);
    echo "  [" . $j->failed_at . "] " . ($p['displayName'] ?? 'N/A') . " — " . substr($j->exception, 0, 120) . "\n";
}
