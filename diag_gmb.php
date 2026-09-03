<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$posts = App\Models\GmbPost::where('status', 'falha')->latest()->limit(5)->get();
echo "FAILED POSTS:\n";
foreach ($posts as $p) {
    echo "ID: {$p->id} | Perfil ID: {$p->perfil_gmb_id} | Perfil Nome: " . ($p->perfil?->nome ?? 'N/A') . "\n";
    echo "Location ID: " . ($p->perfil?->google_location_id ?? 'NULL') . "\n";
    echo "Log Erro: {$p->log_erro}\n";
    echo "----------------------------------------\n";
}

echo "\nPERFIS GMB:\n";
$perfis = App\Models\PerfilGmb::all();
foreach ($perfis as $pf) {
    echo "Perfil #{$pf->id}: {$pf->nome} ({$pf->city}/{$pf->state}) | Location ID: " . ($pf->google_location_id ?: 'VAZIO') . " | Link: {$pf->link_gmb}\n";
}

echo "\nGOOGLE TOKENS:\n";
$tokens = App\Models\GoogleToken::all();
foreach ($tokens as $tk) {
    echo "Token Tenant #{$tk->tenant_id} | Email: {$tk->email} | Expira em: {$tk->expires_at}\n";
}
