<?php

namespace App\Console\Commands;

use App\Models\GoogleToken;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VerificarGoogleGmbAutorizacaoCommand extends Command
{
    protected $signature = 'gmb:verificar-autorizacao {--tenant= : ID do Tenant a verificar}';
    protected $description = 'Verifica se a API do Google Meu Negócio foi liberada pelo Google e testa as permissões de postagem';

    public function handle(GoogleService $googleService): int
    {
        $this->info("========================================================");
        $this->info("  AUDITOR DE AUTORIZAÇÃO: GOOGLE MEU NEGÓCIO (GMB) API  ");
        $this->info("========================================================\n");

        $tenantId = $this->option('tenant');
        $query = GoogleToken::withoutGlobalScopes();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->warn("⚠️  Nenhum token Google conectado encontrado no banco de dados.");
            $this->line("   Para testar com a sua conta Google real:");
            $this->line("   1. Acesse o Painel em: http://127.0.0.1:8000/integracoes");
            $this->line("   2. Clique em 'Conectar Google' e autorize o acesso.");
            $this->line("   3. Execute este comando novamente: php artisan gmb:verificar-autorizacao\n");
            return self::FAILURE;
        }

        foreach ($tokens as $token) {
            $tenant = Tenant::find($token->tenant_id);
            $this->info("--------------------------------------------------------");
            $this->info("Tenant: #{$token->tenant_id} - " . ($tenant?->nome ?? 'Tenant'));
            $this->info("E-mail Google: {$token->google_email}");
            $this->info("Scopes no Token: " . implode(', ', (array) $token->scopes));
            $this->info("--------------------------------------------------------");

            // 1. Garante token atualizado
            if ($token->expires_at && $token->expires_at->isPast()) {
                $this->line("⏳ Token expirado. Renovando access_token...");
                $renovou = $googleService->renovarToken($token);
                if ($renovou) {
                    $token->refresh();
                    $this->info("✅ Token renovado com sucesso! Válido até: {$token->expires_at->format('d/m/Y H:i:s')}");
                } else {
                    $this->error("❌ Falha ao renovar token. Reconecte a conta em /integracoes.");
                    continue;
                }
            } else {
                $this->info("✅ Access Token ativo (Válido até: " . ($token->expires_at?->format('d/m/Y H:i:s') ?? 'N/A') . ")");
            }

            $accessToken = $token->access_token;

            // 2. Teste 1: Account Management API
            $this->line("\n[Teste 1/3] Consultando My Business Account Management API...");
            $res1 = Http::withToken($accessToken)
                ->timeout(15)
                ->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');

            $status1 = $res1->status();
            $body1 = $res1->json();

            if ($status1 === 200) {
                $accounts = $body1['accounts'] ?? [];
                $totalAcc = count($accounts);
                $this->info("✅ Status 200 OK — Account Management API LIBERADA!");
                $this->info("   Contas encontradas: {$totalAcc}");

                foreach ($accounts as $acc) {
                    $accName = $acc['name'] ?? 'N/A';
                    $accTitle = $acc['accountName'] ?? $acc['name'] ?? 'Sem nome';
                    $this->line("   -> Conta: {$accTitle} ({$accName})");

                    // 3. Teste 2: Business Information API (Locais / Unidades)
                    $this->line("\n[Teste 2/3] Buscando unidades/locais para a conta {$accName}...");
                    $res2 = Http::withToken($accessToken)
                        ->timeout(15)
                        ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$accName}/locations", [
                            'readMask' => 'name,title,storefrontAddress,websiteUri',
                        ]);

                    if ($res2->successful()) {
                        $locations = $res2->json('locations') ?? [];
                        $this->info("✅ Status 200 OK — Locais listados com sucesso! (" . count($locations) . " localizações)");
                        foreach ($locations as $loc) {
                            $this->line("      📍 " . ($loc['title'] ?? 'Sem título') . " | ID: " . ($loc['name'] ?? 'N/A'));
                        }
                    } else {
                        $this->warn("⚠️  Não foi possível listar locais via Business Information API ({$res2->status()})");
                        $this->line("      Resposta: " . $res2->body());
                    }

                    // 4. Teste 3: LocalPosts API v4
                    $this->line("\n[Teste 3/3] Verificando permissão de Postagens (LocalPosts v4)...");
                    $res3 = Http::withToken($accessToken)
                        ->timeout(15)
                        ->get("https://mybusiness.googleapis.com/v4/{$accName}/locations");

                    if ($res3->status() === 200 || $res3->status() === 404) {
                        $this->info("✅ API de Postagens v4 acessível!");
                    } else {
                        $this->line("   Status retornado: " . $res3->status() . " - " . ($res3->json('error.message') ?? $res3->body()));
                    }
                }
            } elseif ($status1 === 403) {
                $erro = $res1->json('error.message') ?? $res1->body();
                $this->error("❌ Google retornou HTTP 403 (Acesso Negado ou API Desativada)");
                $this->line("   Detalhes do erro do Google: {$erro}\n");

                if (str_contains($erro, 'SERVICE_DISABLED') || str_contains($erro, 'has not been used in project')) {
                    $this->warn("👉 AÇÃO NECESSÁRIA NO GOOGLE CLOUD:");
                    $this->line("   A API 'My Business Account Management API' precisa ser ATIVADA no seu projeto.");
                    $this->line("   Acesse o link direto:");
                    $this->line("   https://console.developers.google.com/apis/api/mybusinessaccountmanagement.googleapis.com/overview\n");
                } elseif (str_contains($erro, 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')) {
                    $this->warn("👉 AÇÃO NECESSÁRIA NO PAINEL:");
                    $this->line("   O token atual não possui o escopo 'business.manage'.");
                    $this->line("   Acesse /integracoes, desconecte e reconecte a conta Google para autorizar o novo escopo.\n");
                } else {
                    $this->warn("👉 STATUS DE APROVAÇÃO DO GOOGLE:");
                    $this->line("   O Google exige verificação/aprovação para acesso ao Google Business Profile API.");
                    $this->line("   Se a solicitação já foi enviada ao Google, este erro indica que a liberação ainda está em análise pelo Google.");
                }
            } else {
                $this->error("❌ Resposta inesperada do Google: HTTP {$status1}");
                $this->line("   " . $res1->body());
            }
        }

        $this->info("\n========================================================");
        $this->info("                  FIM DO DIAGNÓSTICO                    ");
        $this->info("========================================================\n");

        return self::SUCCESS;
    }
}
