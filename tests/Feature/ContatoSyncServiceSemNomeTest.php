<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real (Leonardo, 2026-08-16): o time da empresa cliente (Frete Rio)
 * digita o nome real de um lead direto no Google Contatos deles, mas o CRM
 * continuava mostrando "Sem Nome" pra sempre — o merge do sync só preenchia
 * campos vazios (empty()), e "Sem Nome" é uma string preenchida, não vazia.
 */
class ContatoSyncServiceSemNomeTest extends TestCase
{
    use RefreshDatabase;

    private function criarToken(): GoogleToken
    {
        $tenant = Tenant::factory()->create();

        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'franqueado@empresa.com',
            'access_token'  => 'access-token-teste',
            'refresh_token' => 'refresh-token-teste',
            'token_type'    => 'Bearer',
            'expires_at'    => now()->addHour(),
            'scopes'        => ['contacts'],
        ]);
    }

    private function fakeConexoesGoogle(string $telefone, string $nomeReal): void
    {
        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName'  => 'people/c123456789',
                    'etag'          => 'etag-123',
                    'names'         => [['displayName' => $nomeReal]],
                    'phoneNumbers'  => [['value' => $telefone]],
                ]],
                'nextSyncToken' => 'sync-token-abc',
            ], 200),
        ]);
    }

    public function test_nome_real_do_google_sobrescreve_sem_nome_travado(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Sem Nome']);
        $this->fakeConexoesGoogle('5521999998888', 'Rodrigo Alves');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $this->assertSame('Rodrigo Alves', $contato->fresh()->nome);
    }

    public function test_nome_real_do_google_sobrescreve_quando_nome_local_e_igual_ao_telefone(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521999997777', 'nome' => '5521999997777']);
        $this->fakeConexoesGoogle('5521999997777', 'Marcia Souza');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $this->assertSame('Marcia Souza', $contato->fresh()->nome);
    }

    public function test_nome_real_local_nao_e_sobrescrito_por_nome_diferente_do_google(): void
    {
        // Nome já real dos dois lados, só grafado diferente — similaridade alta
        // o suficiente pra não cair em conflito de "número reciclado", mas o
        // nome local já é de verdade e não deve ser trocado.
        $contato = Contato::factory()->create(['telefone' => '5521999996666', 'nome' => 'Rodrigo Alves Silva']);
        $this->fakeConexoesGoogle('5521999996666', 'Rodrigo Alves');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $this->assertSame('Rodrigo Alves Silva', $contato->fresh()->nome);
    }
}
