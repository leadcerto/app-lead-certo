<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValidarCadastrosContatosTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenantComEtiquetas(): Tenant
    {
        Bus::fake([\App\Jobs\ProvisionarEtiquetasGoogleJob::class]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        foreach (['leads_em_analise', 'lead_certo', 'lead_invalido'] as $i => $slug) {
            $etiqueta = Etiqueta::firstOrCreate(['tenant_id' => null, 'slug' => $slug], ['nome' => $slug, 'ativo' => true]);
            EtiquetaGoogleGrupo::create([
                'etiqueta_id' => $etiqueta->id, 'tenant_id' => $tenant->id,
                'google_group_resource_name' => "contactGroups/{$slug}",
            ]);
        }

        return $tenant;
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake();

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id} --dry-run")
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists());
    }

    public function test_sem_dry_run_aplica_lead_certo_e_remove_leads_em_analise(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_certo/members:modify'       => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'lead_certo')->exists());
        $this->assertFalse($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'lead_certo/members:modify')
            && in_array('people/c1', $r['resourceNamesToAdd'] ?? []));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'leads_em_analise/members:modify')
            && in_array('people/c1', $r['resourceNamesToRemove'] ?? []));
    }

    public function test_telefone_invalido_vai_pra_lead_invalido(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '55481126376']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c2']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_invalido/members:modify'    => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'lead_invalido')->exists());
    }

    public function test_nao_crasha_quando_merge_apaga_vinculo(): void
    {
        $tenant = $this->setupTenantComEtiquetas();

        // Cria dois contatos com variantes do mesmo número (uma com prefixo 55,
        // outra sem). Ambos canônicos, será tratado como merge.
        // O contato com id menor será o canônico, o outro será mesclado nele.
        $contatoCanon = Contato::factory()->create(['telefone' => '5521994359537']);
        $contatoAntigo = Contato::factory()->create(['telefone' => '21994359537']);

        // Ambos têm vínculos pro mesmo tenant
        $vinculoCanon = VinculoContatoTenant::create(['contato_id' => $contatoCanon->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/canon']);
        $vinculoAntigo = VinculoContatoTenant::create(['contato_id' => $contatoAntigo->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/antigo']);

        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculoCanon->etiquetas()->attach($emAnalise->id);
        $vinculoAntigo->etiquetas()->attach($emAnalise->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_certo/members:modify'       => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        // O vínculo "antigo" foi apagado (pelo merge de contatos)
        $this->assertNull(VinculoContatoTenant::find($vinculoAntigo->id));

        // O vínculo canônico ainda existe e agora tem a etiqueta "lead_certo"
        $vinculoCanon->refresh();
        $this->assertTrue($vinculoCanon->etiquetas()->where('slug', 'lead_certo')->exists());
    }

    public function test_falha_add_nao_atualiza_pivot_local(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'lead_certo/members:modify')) {
                // ADD falha
                return Http::response(['error' => 'rate_limited'], 500);
            }
            // REMOVE sucede
            return Http::response(['status' => 'OK'], 200);
        });

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        // O pivot local NÃO foi atualizado (porque ADD falhou)
        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists());
        $this->assertFalse($vinculo->etiquetas()->where('slug', 'lead_certo')->exists());
    }
}
