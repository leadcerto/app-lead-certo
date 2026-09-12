<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmbQualidadeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    public function test_acessar_diagnostico_pela_primeira_vez_calcula_e_exibe_a_nota(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");

        $response->assertOk();
        $response->assertViewIs('gmb-qualidade.show');
        $response->assertViewHas('score', fn ($score) => $score->nota_geral === 0);
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id]);
    }

    public function test_nao_acessa_diagnostico_de_perfil_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);
        $perfilAlheio = \App\Models\PerfilGmb::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'nome' => 'x', 'city' => 'x', 'state' => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=1', 'ativo' => true,
        ]);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfilAlheio->id}/qualidade");

        $response->assertForbidden();
    }

    public function test_reavaliar_recalcula_a_nota_apos_novo_post(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id, 'nota_geral' => 0]);

        GmbPost::create([
            'tenant_id' => $tenant->id, 'perfil_gmb_id' => $perfil->id, 'tipo' => 'novidade',
            'texto' => 'x', 'data_agendada' => now(), 'status' => 'publicado', 'publicado_em' => now(),
        ]);

        $response = $this->actingAs($dono)->post("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade/reavaliar");

        $response->assertRedirect("/admin/gmb/perfis-gmb/{$perfil->id}/qualidade");
        $this->assertDatabaseHas('gmb_qualidade_scores', ['perfil_gmb_id' => $perfil->id, 'nota_geral' => 100]);
    }
}
