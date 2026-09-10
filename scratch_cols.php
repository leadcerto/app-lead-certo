<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = 1; // Assuming tenant 1 or we can list all
$chaves = \App\Models\KanbanColuna::where('tenant_id', 1)->pluck('chave')->toArray();
echo "Chaves Tenant 1:\n";
print_r($chaves);

$colunas = \App\Models\KanbanColuna::where('tenant_id', 1)->get(['id', 'chave', 'label']);
echo "\nColunas Tenant 1:\n";
foreach($colunas as $c) {
    echo "{$c->id} - {$c->chave} - {$c->label}\n";
}

$kanbanConfigs = \App\Models\KanbanColunaConfig::where('tenant_id', 1)->get(['coluna_kanban', 'ia_contexto']);
echo "\nKanban Configs:\n";
foreach($kanbanConfigs as $k) {
    echo "{$k->coluna_kanban}:\n";
    if (strpos($k->ia_contexto, '[ATENDIMENTO]') !== false) {
         echo "Found [ATENDIMENTO] in prompt for {$k->coluna_kanban}\n";
    }
}
