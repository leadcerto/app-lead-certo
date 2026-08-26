<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido do Leonardo (2026-08-16): mapeamento de campos Lead Certo ↔ Google
 * Contatos — Nome/Nome do meio (id)/Sobrenome (descritor)/E-mail/Telefone já
 * eram enviados; só faltava "Empresa" (contatos.empresa → organizations).
 */
class GoogleServiceOrganizacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTokenValido(Tenant $tenant): GoogleToken
    {
        return GoogleToken::create([
            'tenant_id'     => $tenant->id,
            'google_email'  => 'teste@gmail.com',
            'access_token'  => 'access-valido',
            'refresh_token' => 'refresh-token-123',
            'token_type'    => 'Bearer',
            'expires_at'    => Carbon::now()->addHour(),
            'scopes'        => ['contacts'],
        ]);
    }

    public function test_criar_contato_envia_empresa_como_organizations(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c123'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarTokenValido($tenant);
        $contato = Contato::factory()->create(['telefone' => '5511955556666', 'nome' => 'Marcos', 'empresa' => 'Faxa Marketing']);

        app(GoogleService::class)->criarContato($token, $contato);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'createContact')
            && ($request['organizations'][0]['name'] ?? null) === 'Faxa Marketing');
    }

    public function test_criar_contato_sem_empresa_nao_envia_organizations(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c123'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarTokenValido($tenant);
        $contato = Contato::factory()->create(['telefone' => '5511955557777', 'nome' => 'Ana', 'empresa' => null]);

        app(GoogleService::class)->criarContato($token, $contato);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'createContact')
            && ! array_key_exists('organizations', $request->data()));
    }

    public function test_enriquecer_contato_envia_empresa_e_lista_no_update_person_fields(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant  = Tenant::factory()->create();
        $token   = $this->criarTokenValido($tenant);
        $contato = Contato::factory()->create(['telefone' => '5511955558888', 'nome' => 'Beto', 'empresa' => 'Transportes Beto']);

        app(GoogleService::class)->enriquecerContato($token, 'people/c123', 'etag-antigo', $contato);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'updatePersonFields')
            && str_contains($request->url(), 'organizations')
            && ($request['organizations'][0]['name'] ?? null) === 'Transportes Beto');
    }
}
