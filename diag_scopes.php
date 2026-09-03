<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tokens = App\Models\GoogleToken::all();
foreach ($tokens as $tk) {
    echo "Tenant #{$tk->tenant_id} | Scopes: " . json_encode($tk->scopes) . "\n";
}
