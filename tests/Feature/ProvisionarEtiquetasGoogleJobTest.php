<?php

namespace Tests\Feature;

use App\Jobs\ProvisionarEtiquetasGoogleJob;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisionarEtiquetasGoogleJobTest extends TestCase
{
    use RefreshDatabase;

    private function criarEtiquetasGlobais(): void
    {
        foreach (['lead', 'cliente', 'fornecedor', 'parceiro', 'pessoal', 'colaborador', 'sem_nome', 'inativo', 'bloqueado', 'novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'] as $slug) {
            Etiqueta::create(['tenant_id' => null, 'slug' => $slug, 'nome' => ucfirst($slug), 'ativo' => true]);
        }
    }

    private function criarToken(Tenant $tenant): GoogleToken
    {
        // Bus::fake([ProvisionarEtiquetasGoogleJob::class]) já deve estar
        // ativo antes de chamar isto, senão o hook dispara o job de verdade.
        return GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
    }

    public function test_hook_dispara_o_job_quando_token_e_criado(): void
    {
        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();

        $this->criarToken($tenant);

        Bus::assertDispatched(ProvisionarEtiquetasGoogleJob::class);
    }

    public function test_cria_grupo_lead_e_pessoal_e_liga_as_etiquetas(): void
    {
        $this->criarEtiquetasGlobais();

        Http::fake(['people.googleapis.com/v1/contactGroups*' => Http::sequence()
            ->push(['resourceName' => 'contactGroups/lead-123'], 200)
            ->push(['resourceName' => 'contactGroups/pessoal-456'], 200)]);

        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();
        $token  = $this->criarToken($tenant);

        (new ProvisionarEtiquetasGoogleJob($token->id))->handle(app(GoogleService::class));

        $lead    = Etiqueta::whereNull('tenant_id')->where('slug', 'lead')->first();
        $pessoal = Etiqueta::whereNull('tenant_id')->where('slug', 'pessoal')->first();

        $this->assertSame('contactGroups/lead-123', $lead->googleGrupoParaTenant($tenant->id)?->google_group_resource_name);
        $this->assertSame('contactGroups/pessoal-456', $pessoal->googleGrupoParaTenant($tenant->id)?->google_group_resource_name);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'contactGroups')
            && ($request['contactGroup']['name'] ?? null) === 'Lead Certo - Lead');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'contactGroups')
            && ($request['contactGroup']['name'] ?? null) === 'Lead Certo - Pessoal');
    }

    public function test_nao_cria_grupo_duplicado_se_ja_provisionado(): void
    {
        $this->criarEtiquetasGlobais();
        Http::fake(['people.googleapis.com/v1/contactGroups*' => Http::response(['resourceName' => 'contactGroups/novo'], 200)]);

        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();
        $token  = $this->criarToken($tenant);

        $lead = Etiqueta::whereNull('tenant_id')->where('slug', 'lead')->first();
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $lead->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/ja-existe',
        ]);

        (new ProvisionarEtiquetasGoogleJob($token->id))->handle(app(GoogleService::class));

        $this->assertSame(1, EtiquetaGoogleGrupo::where('etiqueta_id', $lead->id)->where('tenant_id', $tenant->id)->count());
        Http::assertSent(fn ($request) => ($request['contactGroup']['name'] ?? null) === 'Lead Certo - Pessoal');
        Http::assertNotSent(fn ($request) => ($request['contactGroup']['name'] ?? null) === 'Lead Certo - Lead');
    }

    public function test_sem_etiquetas_globais_cadastradas_nao_faz_nada(): void
    {
        // Deletar as 4 etiquetas de validação criadas automaticamente pela migration
        Etiqueta::whereNull('tenant_id')
            ->whereIn('slug', ['novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'])
            ->delete();

        Http::fake();
        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();
        $token  = $this->criarToken($tenant);

        (new ProvisionarEtiquetasGoogleJob($token->id))->handle(app(GoogleService::class));

        Http::assertNothingSent();
    }

    public function test_cria_os_4_grupos_de_validacao_e_marca_base_existente_como_leads_em_analise(): void
    {
        $this->criarEtiquetasGlobais();
        Http::fake([
            'people.googleapis.com/v1/contactGroups' => Http::sequence()
                ->push(['resourceName' => 'contactGroups/lead-1'], 200)
                ->push(['resourceName' => 'contactGroups/pessoal-1'], 200)
                ->push(['resourceName' => 'contactGroups/novos-1'], 200)
                ->push(['resourceName' => 'contactGroups/analise-1'], 200)
                ->push(['resourceName' => 'contactGroups/certo-1'], 200)
                ->push(['resourceName' => 'contactGroups/invalido-1'], 200),
            'people.googleapis.com/v1/contactGroups/analise-1/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();

        $contato = \App\Models\Contato::factory()->create();
        $vinculo = \App\Models\VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123',
        ]);

        $token = $this->criarToken($tenant);
        (new ProvisionarEtiquetasGoogleJob($token->id))->handle(app(GoogleService::class));

        $leadsEmAnalise = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        $this->assertSame('contactGroups/analise-1', $leadsEmAnalise->googleGrupoParaTenant($tenant->id)?->google_group_resource_name);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'contactGroups/analise-1/members:modify')
            && in_array('people/c123', $request['resourceNamesToAdd'] ?? []));
    }
}
