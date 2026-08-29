<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillEtiquetasValidacaoContatos;
use App\Jobs\ProvisionarEtiquetasGoogleJob;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillEtiquetasValidacaoContatosTest extends TestCase
{
    use RefreshDatabase;

    private function criarEtiquetasFunilGlobais(): void
    {
        // 'lead' e 'pessoal' NAO sao semeadas pela migration (so as 4 de
        // validacao sao) -- precisam ser criadas aqui, igual o teste
        // irmao ProvisionarEtiquetasGoogleJobTest ja faz.
        foreach (['lead', 'pessoal'] as $slug) {
            Etiqueta::firstOrCreate(['tenant_id' => null, 'slug' => $slug], ['nome' => ucfirst($slug), 'ativo' => true]);
        }
    }

    public function test_provisiona_grupos_e_marca_base_de_tenant_ja_conectado(): void
    {
        $this->criarEtiquetasFunilGlobais();

        $tenant = Tenant::factory()->create();

        // Bus::fake ANTES de criar o token -- senao GoogleToken::booted()
        // dispara o job de verdade, sem Http::fake() ainda registrado.
        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $token = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $contato = Contato::factory()->create();
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c1',
        ]);

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

        $emAnalise = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        $this->assertNotNull($emAnalise->googleGrupoParaTenant($tenant->id));

        $vinculo = VinculoContatoTenant::where('tenant_id', $tenant->id)->first();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists(), 'o vinculo devia ter sido marcado leads_em_analise de verdade, nao so o grupo criado');
    }

    public function test_erro_sem_tenant_informado(): void
    {
        $this->artisan('contatos:backfill-etiquetas-validacao')
            ->assertExitCode(1);
    }

    public function test_erro_tenant_sem_google_token(): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan("contatos:backfill-etiquetas-validacao --tenant={$tenant->id}")
            ->assertExitCode(1);
    }
}
