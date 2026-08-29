<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillEtiquetasValidacaoContatos;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillEtiquetasValidacaoContatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisiona_grupos_e_marca_base_de_tenant_ja_conectado(): void
    {
        $tenant = Tenant::factory()->create();
        $token = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $contato = Contato::factory()->create();
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);

        Http::fake([
            'people.googleapis.com/v1/contactGroups' => Http::sequence()
                ->push(['resourceName' => 'contactGroups/lead'], 200)
                ->push(['resourceName' => 'contactGroups/pessoal'], 200)
                ->push(['resourceName' => 'contactGroups/novos'], 200)
                ->push(['resourceName' => 'contactGroups/analise'], 200)
                ->push(['resourceName' => 'contactGroups/certo'], 200)
                ->push(['resourceName' => 'contactGroups/invalido'], 200),
            'people.googleapis.com/v1/contactGroups/analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:backfill-etiquetas-validacao --tenant={$tenant->id}")
            ->assertExitCode(0);

        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $this->assertNotNull($emAnalise->googleGrupoParaTenant($tenant->id));
    }
}
