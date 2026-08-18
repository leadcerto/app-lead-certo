<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AvaliadorControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function dadosValidos(array $sobrescreve = []): array
    {
        return array_merge([
            'nome'  => 'Regis Rodrigues',
            'email' => 'regis@frete.rio.br',
            'senha' => 'senhaInicial123',
            'city'  => 'Rio de Janeiro',
            'state' => 'RJ',
        ], $sobrescreve);
    }

    public function test_lista_apenas_avaliadores_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);
        User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'avaliador', 'nome' => 'Meu Avaliador']);
        User::factory()->create(['tenant_id' => $outroTenant->id, 'perfil' => 'avaliador', 'nome' => 'Avaliador Alheio']);
        User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'nome' => 'Vendedor Comum']);

        $response = $this->actingAs($dono)->get('/admin/gmb/avaliadores');

        $response->assertOk();
        $response->assertSee('Meu Avaliador');
        $response->assertDontSee('Avaliador Alheio');
        $response->assertDontSee('Vendedor Comum');
    }

    public function test_cria_avaliador(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/avaliadores', $this->dadosValidos());

        $response->assertRedirect(route('admin.avaliadores.index'));
        $avaliador = User::where('email', 'regis@frete.rio.br')->first();
        $this->assertNotNull($avaliador);
        $this->assertSame('avaliador', $avaliador->perfil);
        $this->assertSame($tenant->id, $avaliador->tenant_id);
        $this->assertTrue(Hash::check('senhaInicial123', $avaliador->password));
    }

    public function test_nao_cria_avaliador_com_email_duplicado(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'ja-existe@frete.rio.br']);

        $response = $this->actingAs($dono)->post('/admin/gmb/avaliadores', $this->dadosValidos([
            'email' => 'ja-existe@frete.rio.br',
        ]));

        $response->assertSessionHasErrors('email');
    }

    public function test_edita_avaliador_sem_trocar_senha(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $avaliador = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'avaliador', 'city' => 'Rio de Janeiro', 'state' => 'RJ']);
        $senhaAntes = $avaliador->password;

        $response = $this->actingAs($dono)->put("/admin/gmb/avaliadores/{$avaliador->id}", [
            'nome' => 'Nome Atualizado', 'email' => $avaliador->email,
            'city' => 'São Paulo', 'state' => 'SP', 'ativo' => '1',
        ]);

        $response->assertRedirect(route('admin.avaliadores.index'));
        $avaliador->refresh();
        $this->assertSame('Nome Atualizado', $avaliador->nome);
        $this->assertSame('São Paulo', $avaliador->city);
        $this->assertSame($senhaAntes, $avaliador->password);
    }

    public function test_desativa_avaliador(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $avaliador = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'avaliador', 'ativo' => true]);

        $response = $this->actingAs($dono)->delete("/admin/gmb/avaliadores/{$avaliador->id}");

        $response->assertRedirect(route('admin.avaliadores.index'));
        $this->assertFalse($avaliador->fresh()->ativo);
    }

    public function test_nao_edita_avaliador_de_outro_tenant(): void
    {
        $tenant          = Tenant::factory()->create();
        $outroTenant     = Tenant::factory()->create();
        $dono            = $this->usuarioDono($tenant);
        $avaliadorAlheio = User::factory()->create(['tenant_id' => $outroTenant->id, 'perfil' => 'avaliador']);

        $response = $this->actingAs($dono)->get("/admin/gmb/avaliadores/{$avaliadorAlheio->id}/editar");

        $response->assertForbidden();
    }

    public function test_avaliador_nao_edita_perfil_de_usuario_que_nao_e_avaliador(): void
    {
        // Regressão de escopo: essa tela é só pra perfil 'avaliador', não serve
        // como brecha pra editar dono/vendedor pela URL.
        $tenant   = Tenant::factory()->create();
        $dono     = $this->usuarioDono($tenant);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $response = $this->actingAs($dono)->get("/admin/gmb/avaliadores/{$vendedor->id}/editar");

        $response->assertForbidden();
    }

    public function test_vendedor_nao_acessa_cadastro_de_avaliadores(): void
    {
        $tenant   = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $response = $this->actingAs($vendedor)->get('/admin/gmb/avaliadores');

        $response->assertForbidden();
    }
}
