<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\ContatoSyncService;
use App\Services\TelefoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContatoSyncServiceNormalizaTelefoneTest extends TestCase
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

    private function fakeConexoesGoogle(string $telefoneBruto): void
    {
        Http::fake([
            '*people/me/connections*' => Http::response([
                'connections' => [[
                    'resourceName'  => 'people/c123456789',
                    'etag'          => 'etag-123',
                    'names'         => [['displayName' => 'Cliente Teste']],
                    'phoneNumbers'  => [['value' => $telefoneBruto]],
                ]],
                'nextSyncToken' => 'sync-token-abc',
            ], 200),
        ]);
    }

    public function test_telefone_antigo_sem_9_e_normalizado_pro_padrao_canonico(): void
    {
        // Formato antigo de celular (DDD 21 + 8 dígitos, sem o "9" na frente e
        // sem o "55") — é exatamente o que o Google devolve pra contatos
        // salvos há anos na agenda.
        $this->fakeConexoesGoogle('2199998888');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $esperado = app(TelefoneService::class)->normalizar('2199998888');

        $this->assertNotNull($esperado);
        $this->assertSame('5521999998888', $esperado);
        $this->assertDatabaseHas('contatos', ['telefone' => $esperado]);
        $this->assertDatabaseMissing('contatos', ['telefone' => '2199998888']);
    }

    public function test_telefone_ja_canonico_permanece_igual(): void
    {
        $this->fakeConexoesGoogle('5521999998888');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $this->assertDatabaseHas('contatos', ['telefone' => '5521999998888']);
    }

    public function test_contato_do_google_casa_com_contato_ja_criado_em_formato_canonico(): void
    {
        // Um lead que já mandou mensagem pelo WhatsApp (webhook cria telefone
        // canônico) não pode virar um segundo Contato quando o mesmo número
        // aparece na agenda do Google em formato antigo.
        Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Cliente Teste']);

        $this->fakeConexoesGoogle('2199998888');

        $token = $this->criarToken();
        app(ContatoSyncService::class)->sincronizar($token, $token->tenant_id);

        $this->assertSame(1, Contato::count());
        $this->assertSame(1, Contato::where('telefone', '5521999998888')->count());
    }
}
