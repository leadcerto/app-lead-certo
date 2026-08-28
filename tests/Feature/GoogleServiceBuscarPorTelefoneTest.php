<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleServiceBuscarPorTelefoneTest extends TestCase
{
    use RefreshDatabase;

    private function tokenValido(): GoogleToken
    {
        $tenant = Tenant::factory()->create();

        return GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
    }

    public function test_busca_por_telefone_retorna_a_pessoa_encontrada(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [[
                'person' => [
                    'names'        => [['displayName' => 'Rodrigo Alves']],
                    'phoneNumbers' => [['value' => '5521999998888']],
                ],
            ]],
        ], 200)]);

        $resultado = app(GoogleService::class)->buscarContatoPorTelefone($this->tokenValido(), '5521999998888');

        $this->assertSame('Rodrigo Alves', $resultado['names'][0]['displayName']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'searchContacts')
            && str_contains($request->url(), 'readMask=names%2CphoneNumbers%2Corganizations%2CemailAddresses')
            && ! str_contains($request->url(), 'memberships'));
    }

    public function test_busca_sem_resultado_retorna_null(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response(['results' => []], 200)]);

        $resultado = app(GoogleService::class)->buscarContatoPorTelefone($this->tokenValido(), '5521900000000');

        $this->assertNull($resultado);
    }
}
