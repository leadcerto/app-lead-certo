<?php

namespace Tests\Feature;

use App\Jobs\MarcarNovoLeadEtiquetaJob;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarcarNovoLeadEtiquetaJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_novos_leads_quando_grupo_ja_provisionado(): void
    {
        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $etiqueta = Etiqueta::create(['tenant_id' => null, 'slug' => 'novos_leads', 'nome' => 'Novos Leads', 'ativo' => true]);
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $etiqueta->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/novos-1',
        ]);

        Http::fake(['people.googleapis.com/v1/contactGroups/novos-1/members:modify' => Http::response(['status' => 'OK'], 200)]);

        $contato = Contato::factory()->create();
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c999',
        ]);

        (new MarcarNovoLeadEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'novos_leads')->exists());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'novos-1/members:modify') && in_array('people/c999', $r['resourceNamesToAdd'] ?? []));
    }

    public function test_nao_marca_se_grupo_ainda_nao_provisionado(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c998',
        ]);

        Http::fake();

        (new MarcarNovoLeadEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        Http::assertNothingSent();
    }
}
