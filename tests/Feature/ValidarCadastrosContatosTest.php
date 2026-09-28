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

    /**
     * Regra do 8º dia (pedido do Leonardo, 24/09, confirmado em detalhe):
     * conta a partir da aberto_em do TICKET ATIVO atual (reabertura reinicia
     * a contagem) — não da data de criação do contato/vínculo. --dias=N filtra
     * só quem já bateu N dias; sem a flag, comportamento antigo (processa
     * tudo que estiver marcado, usado no dry-run/uso manual) continua igual.
     */
    public function test_flag_dias_so_processa_contato_com_ticket_ativo_ha_pelo_menos_n_dias(): void
    {
        $tenant = $this->setupTenantComEtiquetas();
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();

        $contatoRecente = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculoRecente = VinculoContatoTenant::create(['contato_id' => $contatoRecente->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/recente']);
        $vinculoRecente->etiquetas()->attach($emAnalise->id);
        \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contatoRecente->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now()->subDays(2), // só 2 dias — não bateu ainda
        ]);

        $contatoAntigo = Contato::factory()->create(['telefone' => '5521988887777']);
        $vinculoAntigo = VinculoContatoTenant::create(['contato_id' => $contatoAntigo->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/antigo8d']);
        $vinculoAntigo->etiquetas()->attach($emAnalise->id);
        \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contatoAntigo->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now()->subDays(9), // 9 dias — bateu
        ]);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_certo/members:modify'       => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id} --dias=8")
            ->assertExitCode(0);

        // Só o contato com ticket de 9 dias foi processado
        $this->assertTrue($vinculoAntigo->fresh()->etiquetas()->where('slug', 'lead_certo')->exists());
        // O recente (2 dias) continua intocado, ainda em leads_em_analise
        $this->assertTrue($vinculoRecente->fresh()->etiquetas()->where('slug', 'leads_em_analise')->exists());
        $this->assertFalse($vinculoRecente->fresh()->etiquetas()->where('slug', 'lead_certo')->exists());
    }

    /**
     * Sem --tenant, o comando roda pra todos os tenants com Google conectado
     * de uma vez — é assim que o agendamento diário (00h01) cobre a base
     * inteira sem precisar de uma linha de cron por tenant.
     */
    public function test_sem_tenant_processa_todos_os_tenants_com_google_conectado(): void
    {
        $tenantA = $this->setupTenantComEtiquetas();
        $tenantB = $this->setupTenantComEtiquetas();
        $emAnaliseA = Etiqueta::where('slug', 'leads_em_analise')->first();

        $contatoA = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculoA = VinculoContatoTenant::create(['contato_id' => $contatoA->id, 'tenant_id' => $tenantA->id, 'google_resource_name' => 'people/tenantA']);
        $vinculoA->etiquetas()->attach($emAnaliseA->id);

        $contatoB = Contato::factory()->create(['telefone' => '5521988887777']);
        $vinculoB = VinculoContatoTenant::create(['contato_id' => $contatoB->id, 'tenant_id' => $tenantB->id, 'google_resource_name' => 'people/tenantB']);
        $vinculoB->etiquetas()->attach($emAnaliseA->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_certo/members:modify'       => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan('contatos:validar-cadastros')->assertExitCode(0);

        $this->assertTrue($vinculoA->fresh()->etiquetas()->where('slug', 'lead_certo')->exists());
        $this->assertTrue($vinculoB->fresh()->etiquetas()->where('slug', 'lead_certo')->exists());
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
