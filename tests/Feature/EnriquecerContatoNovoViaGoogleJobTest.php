<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnriquecerContatoNovoViaGoogleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_nome_encontrado_no_google_pro_lead_novo(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'names'        => [['displayName' => 'Rodrigo Alves']],
                'phoneNumbers' => [['value' => '5521999998888']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Rodrigo Alves', $contato->fresh()->nome);
    }

    public function test_sem_google_token_nao_faz_nada(): void
    {
        Http::fake();

        $tenant  = Tenant::factory()->create(); // sem GoogleToken
        $contato = Contato::factory()->create(['telefone' => '5521999997777', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        Http::assertNothingSent();
        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }

    public function test_sem_resultado_no_google_nao_faz_nada(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response(['results' => []], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999996666', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }
}
