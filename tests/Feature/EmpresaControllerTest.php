<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmpresaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAdmin(): User
    {
        return User::factory()->create(['tenant_id' => null, 'perfil' => 'admin']);
    }

    private function dadosValidos(array $sobrescreve = []): array
    {
        return array_merge([
            'nome'       => 'Frete Rio',
            'email'      => 'contato@frete.rio.br',
            'telefone'   => '21999990000',
            'nicho'      => 'frete',
            'dono_nome'  => 'Leonardo',
            'dono_email' => 'leonardo@frete.rio.br',
            'dono_senha' => 'senhaInicial123',
        ], $sobrescreve);
    }

    public function test_admin_lista_empresas_cadastradas(): void
    {
        $admin = $this->usuarioAdmin();
        Tenant::factory()->create(['nome' => 'Empresa A']);

        $response = $this->actingAs($admin)->get('/admin/empresas');

        $response->assertOk();
        $response->assertSee('Empresa A');
    }

    public function test_cria_empresa_dono_e_configuracao_padrao(): void
    {
        $admin = $this->usuarioAdmin();

        $response = $this->actingAs($admin)->post('/admin/empresas', $this->dadosValidos());

        $response->assertRedirect(route('admin.empresas.index'));

        $tenant = Tenant::where('email', 'contato@frete.rio.br')->first();
        $this->assertNotNull($tenant);
        $this->assertSame('Frete Rio', $tenant->nome);
        $this->assertSame('ativo', $tenant->status);

        // Usuário dono criado corretamente.
        $dono = User::where('tenant_id', $tenant->id)->where('perfil', 'dono')->first();
        $this->assertNotNull($dono);
        $this->assertSame('leonardo@frete.rio.br', $dono->email);
        $this->assertTrue(Hash::check('senhaInicial123', $dono->password));

        // TenantSetupService aplicado: Kanban + colunas + persona.
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertNotNull($kanban);
        $this->assertSame(8, KanbanColuna::where('kanban_id', $kanban->id)->count());
        $this->assertTrue(SdrPersona::where('tenant_id', $tenant->id)->where('is_default', true)->exists());
    }

    public function test_nao_cria_empresa_com_email_duplicado(): void
    {
        $admin = $this->usuarioAdmin();
        Tenant::factory()->create(['email' => 'ja-existe@frete.rio.br']);

        $response = $this->actingAs($admin)->post('/admin/empresas', $this->dadosValidos([
            'email' => 'ja-existe@frete.rio.br',
        ]));

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, Tenant::where('email', 'ja-existe@frete.rio.br')->count());
    }

    public function test_nao_cria_dono_com_email_de_usuario_ja_existente(): void
    {
        $admin       = $this->usuarioAdmin();
        $outroTenant = Tenant::factory()->create();
        User::factory()->create(['tenant_id' => $outroTenant->id, 'email' => 'ja-usuario@frete.rio.br']);

        $response = $this->actingAs($admin)->post('/admin/empresas', $this->dadosValidos([
            'dono_email' => 'ja-usuario@frete.rio.br',
        ]));

        $response->assertSessionHasErrors('dono_email');
    }

    public function test_dono_nao_acessa_cadastro_de_empresas(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($dono)->get('/admin/empresas');

        $response->assertForbidden();
    }

    public function test_dono_nao_cria_empresa(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);

        $response = $this->actingAs($dono)->post('/admin/empresas', $this->dadosValidos());

        $response->assertForbidden();
    }
}
