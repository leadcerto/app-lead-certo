<?php

namespace Tests\Feature;

use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiretorMarketingCruzaTenantGmbTest extends TestCase
{
    use RefreshDatabase;

    // Achado real 2026-08-20: os 4 controllers de GMB liam
    // $request->user()->tenant_id direto — corrigido pra tenantAtual(),
    // que respeita o ?tenant_id= trocado pelo EnsureTenant. Este teste
    // prova o caso de uso real: a Nathanel (perfil diretor_marketing,
    // tenant "casa" = Lead Certo) acessando o GMB de um cliente específico.

    public function test_diretor_marketing_lista_perfis_gmb_do_cliente_escolhido_via_query_param(): void
    {
        $leadCerto = Tenant::factory()->create();
        $cliente   = Tenant::factory()->create();
        $nathanel  = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'diretor_marketing', 'ativo' => true]);

        PerfilGmb::create([
            'tenant_id' => $cliente->id, 'nome' => 'Frete Rio — Copacabana',
            'city' => 'Rio de Janeiro', 'state' => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=123', 'ativo' => true,
        ]);

        $response = $this->actingAs($nathanel)->get("/admin/gmb/perfis-gmb?tenant_id={$cliente->id}");

        $response->assertOk();
        $response->assertViewHas('perfis', fn ($perfis) => $perfis->total() === 1);
    }

    public function test_diretor_marketing_cria_perfil_gmb_no_tenant_do_cliente_escolhido_nao_no_proprio(): void
    {
        $leadCerto = Tenant::factory()->create();
        $cliente   = Tenant::factory()->create();
        $nathanel  = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'diretor_marketing', 'ativo' => true]);

        $response = $this->actingAs($nathanel)->post("/admin/gmb/perfis-gmb?tenant_id={$cliente->id}", [
            'nome' => 'Frete Rio — Barra', 'city' => 'Rio de Janeiro', 'state' => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=456',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('perfis_gmb', ['tenant_id' => $cliente->id, 'nome' => 'Frete Rio — Barra']);
        $this->assertDatabaseMissing('perfis_gmb', ['tenant_id' => $leadCerto->id]);
    }

    public function test_diretor_marketing_nao_acessa_agendamento_de_tenant_que_nao_escolheu(): void
    {
        // Regressão: sem o ?tenant_id= (ou com outro valor), continua
        // vendo/agindo só no tenant que escolheu — não vira acesso total tipo admin.
        $leadCerto      = Tenant::factory()->create();
        $clienteEscolhido = Tenant::factory()->create();
        $clienteAlheio    = Tenant::factory()->create();
        $nathanel  = User::factory()->create(['tenant_id' => $leadCerto->id, 'perfil' => 'diretor_marketing', 'ativo' => true]);

        PerfilGmb::create([
            'tenant_id' => $clienteAlheio->id, 'nome' => 'Outro cliente',
            'city' => 'São Paulo', 'state' => 'SP',
            'link_gmb' => 'https://maps.google.com/?cid=999', 'ativo' => true,
        ]);

        $response = $this->actingAs($nathanel)->get("/admin/gmb/perfis-gmb?tenant_id={$clienteEscolhido->id}");

        $response->assertOk();
        $response->assertViewHas('perfis', fn ($perfis) => $perfis->total() === 0);
    }
}
