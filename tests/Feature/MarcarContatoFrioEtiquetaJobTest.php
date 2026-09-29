<?php

namespace Tests\Feature;

use App\Jobs\MarcarContatoFrioEtiquetaJob;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarcarContatoFrioEtiquetaJobTest extends TestCase
{
    use RefreshDatabase;

    private function criarTenantComGrupoFriosProvisionado(): array
    {
        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $frios = Etiqueta::updateOrCreate(['tenant_id' => null, 'slug' => 'frios'], ['nome' => 'Frios', 'cor' => '#0284C7', 'ativo' => true]);
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $frios->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/frios-1',
        ]);

        return [$tenant, $token, $frios];
    }

    public function test_marca_etiqueta_frios_quando_contato_veio_de_grupo_whatsapp(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class, \App\Jobs\EnriquecerContatoNovoViaGoogleJob::class, \App\Jobs\MarcarNovoLeadEtiquetaJob::class]);
        [$tenant] = $this->criarTenantComGrupoFriosProvisionado();

        Http::fake(['*members:modify*' => Http::response(['status' => 'OK'], 200)]);

        $contato = Contato::factory()->create(['origem' => 'whatsapp_grupo']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c777',
        ]);

        (new MarcarContatoFrioEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'frios')->exists());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'frios-1/members:modify') && in_array('people/c777', $r['resourceNamesToAdd'] ?? []));
    }

    public function test_nao_marca_frios_quando_contato_nao_veio_de_grupo(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class, \App\Jobs\EnriquecerContatoNovoViaGoogleJob::class, \App\Jobs\MarcarNovoLeadEtiquetaJob::class]);
        [$tenant] = $this->criarTenantComGrupoFriosProvisionado();

        Http::fake();

        $contato = Contato::factory()->create(['origem' => 'whatsapp_webhook']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c778',
        ]);

        (new MarcarContatoFrioEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        $vinculo->refresh();
        $this->assertFalse($vinculo->etiquetas()->where('slug', 'frios')->exists());
        Http::assertNothingSent();
    }

    public function test_nao_marca_se_grupo_frios_ainda_nao_provisionado(): void
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class, \App\Jobs\EnriquecerContatoNovoViaGoogleJob::class, \App\Jobs\MarcarNovoLeadEtiquetaJob::class]);

        $tenant = Tenant::factory()->create();
        Http::fake();

        $contato = Contato::factory()->create(['origem' => 'whatsapp_grupo']);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c779',
        ]);

        (new MarcarContatoFrioEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        Http::assertNothingSent();
    }
}
