<?php

namespace Tests\Feature;

use App\Models\ContatoAvaliacao;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoAvaliacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function criarPerfil(Tenant $tenant, array $atributos = []): PerfilGmb
    {
        return PerfilGmb::create(array_merge([
            'tenant_id' => $tenant->id, 'nome' => 'Frete Rio', 'city' => 'Rio de Janeiro',
            'state' => 'RJ', 'link_gmb' => 'https://maps.google.com/?cid=1', 'ativo' => true,
        ], $atributos));
    }

    public function test_lista_contatos_do_perfil(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        ContatoAvaliacao::create(['tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'nome' => 'Maria', 'telefone' => '21988887777']);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfil->id}/contatos");

        $response->assertOk();
        $response->assertSee('Maria');
        $response->assertSee('21988887777');
    }

    public function test_adiciona_contatos_em_lote_com_nome_e_telefone(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $response = $this->actingAs($dono)->post("/admin/gmb/perfis-gmb/{$perfil->id}/contatos", [
            'lista' => "Maria Silva, 21988887777\nJoão Souza, 21999996666\n21977775555",
        ]);

        $response->assertRedirect(route('admin.perfis-gmb.contatos.index', $perfil));
        $this->assertSame(3, ContatoAvaliacao::where('perfil_id', $perfil->id)->count());
        $this->assertDatabaseHas('contatos_avaliacao', ['perfil_id' => $perfil->id, 'nome' => 'Maria Silva', 'telefone' => '21988887777']);
        $this->assertDatabaseHas('contatos_avaliacao', ['perfil_id' => $perfil->id, 'nome' => null, 'telefone' => '21977775555']);
    }

    public function test_ignora_linhas_vazias_ao_adicionar_em_lote(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);

        $this->actingAs($dono)->post("/admin/gmb/perfis-gmb/{$perfil->id}/contatos", [
            'lista' => "21988887777\n\n\n21999996666\n",
        ]);

        $this->assertSame(2, ContatoAvaliacao::where('perfil_id', $perfil->id)->count());
    }

    public function test_remove_contato(): void
    {
        $tenant  = Tenant::factory()->create();
        $dono    = $this->usuarioDono($tenant);
        $perfil  = $this->criarPerfil($tenant);
        $contato = ContatoAvaliacao::create(['tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'telefone' => '21988887777']);

        $response = $this->actingAs($dono)->delete("/admin/gmb/perfis-gmb/contatos/{$contato->id}");

        $response->assertRedirect(route('admin.perfis-gmb.contatos.index', $perfil));
        $this->assertDatabaseMissing('contatos_avaliacao', ['id' => $contato->id]);
    }

    public function test_nao_lista_contatos_de_perfil_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $dono         = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->get("/admin/gmb/perfis-gmb/{$perfilAlheio->id}/contatos");

        $response->assertForbidden();
    }

    public function test_nao_remove_contato_de_outro_tenant(): void
    {
        $tenant          = Tenant::factory()->create();
        $outroTenant     = Tenant::factory()->create();
        $dono            = $this->usuarioDono($tenant);
        $perfilAlheio    = $this->criarPerfil($outroTenant);
        $contatoAlheio   = ContatoAvaliacao::create(['tenant_id' => $outroTenant->id, 'perfil_id' => $perfilAlheio->id, 'telefone' => '21988887777']);

        $response = $this->actingAs($dono)->delete("/admin/gmb/perfis-gmb/contatos/{$contatoAlheio->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('contatos_avaliacao', ['id' => $contatoAlheio->id]);
    }
}
