<?php

namespace Tests\Feature;

use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerfilGmbControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function usuarioVendedor(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);
    }

    private function criarPerfil(Tenant $tenant, array $atributos = []): PerfilGmb
    {
        return PerfilGmb::create(array_merge([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Copacabana',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ], $atributos));
    }

    public function test_lista_apenas_perfis_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);

        $this->criarPerfil($tenant);
        $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->get('/admin/gmb/perfis-gmb');

        $response->assertOk();
        $response->assertViewHas('perfis', fn ($perfis) => $perfis->total() === 1);
    }

    public function test_cria_perfil_gmb(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/perfis-gmb', [
            'nome'     => 'Frete Rio — Barra',
            'city'     => 'Rio de Janeiro',
            'state'    => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=456',
        ]);

        $response->assertRedirect(route('admin.perfis-gmb.index'));
        $this->assertDatabaseHas('perfis_gmb', [
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Barra',
            'ativo'     => true,
        ]);
    }

    public function test_tela_de_edicao_carrega_os_dados_do_perfil_correto(): void
    {
        // Regressão: os parâmetros de rota/controller precisam bater
        // (senão o Laravel injeta um PerfilGmb vazio, sem erro nenhum).
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant, ['nome' => 'Frete Rio — Tijuca']);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/edit");

        $response->assertOk();
        $response->assertSee('Frete Rio — Tijuca');
    }

    public function test_atualiza_perfil_existente_sem_criar_registro_novo(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->put("/admin/gmb/perfis-gmb/{$perfil->id}", [
            'nome'     => 'Frete Rio — Copacabana (renomeado)',
            'city'     => 'Rio de Janeiro',
            'state'    => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=123',
        ]);

        $response->assertRedirect(route('admin.perfis-gmb.index'));
        $this->assertSame(1, PerfilGmb::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('perfis_gmb', [
            'id'   => $perfil->id,
            'nome' => 'Frete Rio — Copacabana (renomeado)',
        ]);
    }

    public function test_apaga_perfil_desativa_em_vez_de_remover(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->delete("/admin/gmb/perfis-gmb/{$perfil->id}");

        $response->assertRedirect(route('admin.perfis-gmb.index'));
        $this->assertDatabaseHas('perfis_gmb', ['id' => $perfil->id, 'ativo' => false]);
    }

    public function test_nao_edita_perfil_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfilAlheio->id}/edit");

        $response->assertForbidden();
    }

    public function test_nao_atualiza_perfil_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $dono         = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant, ['nome' => 'Original']);

        $response = $this->actingAs($dono)->put("/admin/gmb/perfis-gmb/{$perfilAlheio->id}", [
            'nome'     => 'Sequestrado',
            'city'     => 'Rio de Janeiro',
            'state'    => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=123',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('perfis_gmb', ['id' => $perfilAlheio->id, 'nome' => 'Original']);
    }

    public function test_nao_apaga_perfil_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $dono         = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->delete("/admin/gmb/perfis-gmb/{$perfilAlheio->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('perfis_gmb', ['id' => $perfilAlheio->id, 'ativo' => true]);
    }

    public function test_vendedor_nao_acessa_perfis_gmb(): void
    {
        $tenant    = Tenant::factory()->create();
        $vendedor  = $this->usuarioVendedor($tenant);

        $response = $this->actingAs($vendedor)->get('/admin/gmb/perfis-gmb');

        $response->assertForbidden();
    }
}
