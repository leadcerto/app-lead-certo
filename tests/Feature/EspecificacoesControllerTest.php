<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EspecificacoesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(string $perfil): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => $perfil,
            'ativo'     => true,
        ]);
    }

    public function test_dono_ve_a_lista_com_a_spec_do_gestor_do_kanban(): void
    {
        $user = $this->criarUsuario('dono');

        $response = $this->actingAs($user)->get(route('admin.especificacoes'));

        $response->assertOk();
        $response->assertSee('2026-07-11-gestor-kanban-semanal-design.md');
    }

    public function test_perfil_sem_permissao_recebe_403(): void
    {
        $user = $this->criarUsuario('vendedor');

        $response = $this->actingAs($user)->get(route('admin.especificacoes'));

        $response->assertForbidden();
    }

    public function test_show_renderiza_a_spec_em_html(): void
    {
        $user = $this->criarUsuario('admin');

        $response = $this->actingAs($user)->get(
            route('admin.especificacoes.show', '2026-07-11-gestor-kanban-semanal-design.md')
        );

        $response->assertOk();
        $response->assertSee('Gestor do Kanban', false);
        $response->assertSee('<h2', false);
    }

    public function test_show_com_arquivo_inexistente_retorna_404(): void
    {
        $user = $this->criarUsuario('dono');

        $response = $this->actingAs($user)->get(
            route('admin.especificacoes.show', 'nao-existe.md')
        );

        $response->assertNotFound();
    }

    public function test_show_bloqueia_tentativa_de_path_traversal(): void
    {
        $user = $this->criarUsuario('dono');

        $response = $this->actingAs($user)->get('/admin/especificacoes/' . urlencode('../../../../.env'));

        $response->assertNotFound();
    }
}
