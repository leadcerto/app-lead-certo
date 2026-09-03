<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$token = App\Models\GoogleToken::where('tenant_id', 1)->first();
app(App\Services\GoogleService::class)->renovarToken($token);
$token->refresh();

echo "Testing GMB API with token for Tenant 1...\n";
$res = Illuminate\Support\Facades\Http::withToken($token->access_token)
    ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

echo "Status: " . $res->status() . "\n";
echo "Body: " . $res->body() . "\n";

echo "\nTesting Business Profile Performance API / MyBusinessBusinessInformation...\n";
$res2 = Illuminate\Support\Facades\Http::withToken($token->access_token)
    ->get('https://mybusinessbusinessinformation.googleapis.com/v1/accounts');
echo "Status 2: " . $res2->status() . "\n";
echo "Body 2: " . $res2->body() . "\n";
