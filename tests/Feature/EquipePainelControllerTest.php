<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipePainelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_todos_usuarios_podem_ver_funcoes(): void
    {
        $tenant = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        Cargo::create([
            'nome'      => 'Diretora de Marketing',
            'tipo'      => 'marketing',
            'descricao' => 'Gestão de marketing',
            'ordem'     => 1,
        ]);

        $response = $this->actingAs($vendedor)->get(route('equipe.funcoes'));

        $response->assertOk();
        $response->assertSee('Diretora de Marketing');
        $response->assertDontSee('Nova Função'); // Botão restrito ao admin
    }

    public function test_apenas_admin_pode_criar_funcao(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $response = $this->actingAs($admin)->post(route('equipe.funcoes.store'), [
            'nome'      => 'Gestor de Automação',
            'tipo'      => 'operacional',
            'icone'     => '⚡',
            'descricao' => 'Automações via n8n e webhook',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cargos', [
            'nome' => 'Gestor de Automação',
            'tipo' => 'operacional',
        ]);
    }

    public function test_usuario_comum_bloqueado_de_criar_funcao(): void
    {
        $tenant = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $response = $this->actingAs($vendedor)->post(route('equipe.funcoes.store'), [
            'nome'      => 'Função Não Autorizada',
            'tipo'      => 'operacional',
            'descricao' => 'Teste',
        ]);

        $response->assertForbidden();
    }

    public function test_todos_usuarios_podem_ver_agentes_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $cargo = Cargo::create(['nome' => 'Diretora de Marketing', 'tipo' => 'marketing', 'descricao' => 'mkt']);
        $ia = User::factory()->create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Nathanel Fernandes',
            'email'     => 'nathanel@leadcerto.com',
            'is_ia'     => true,
        ]);
        $ia->cargos()->attach($cargo->id);

        $response = $this->actingAs($vendedor)->get(route('equipe.agentes-ia'));

        $response->assertOk();
        $response->assertSee('Nathanel Fernandes');
        $response->assertSee('Diretora de Marketing');
        $response->assertDontSee('Novo Agente IA'); // Botão restrito ao admin
    }

    public function test_admin_cria_agente_ia_com_multiplas_funcoes(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);

        $c1 = Cargo::create(['nome' => 'Diretora de Marketing', 'tipo' => 'marketing', 'descricao' => 'mkt']);
        $c2 = Cargo::create(['nome' => 'Gestor Comercial', 'tipo' => 'comercial', 'descricao' => 'com']);

        $response = $this->actingAs($admin)->post(route('equipe.agentes-ia.store'), [
            'nome'              => 'Nathanel Fernandes',
            'email'             => 'nathanel.test@leadcerto.com',
            'gemini_email'      => 'nathanelllfernandees@gmail.com',
            'gemini_instrucoes' => 'Supervisão de campanhas e Kanban',
            'cargos'            => [$c1->id, $c2->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email'        => 'nathanel.test@leadcerto.com',
            'is_ia'        => true,
            'gemini_email' => 'nathanelllfernandees@gmail.com',
        ]);

        $agente = User::where('email', 'nathanel.test@leadcerto.com')->first();
        $this->assertCount(2, $agente->cargos);
    }

    public function test_todos_podem_ver_equipe_humana_por_hierarquia(): void
    {
        $tenant = Tenant::factory()->create();
        $dono = User::factory()->create(['tenant_id' => $tenant->id, 'nome' => 'Carlos Dono', 'perfil' => 'dono', 'is_ia' => false]);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'nome' => 'Marcos Vendedor', 'perfil' => 'vendedor', 'is_ia' => false]);

        $response = $this->actingAs($vendedor)->get(route('equipe.humanos'));

        $response->assertOk();
        $response->assertSee('Carlos Dono');
        $response->assertSee('Marcos Vendedor');
        $response->assertSee('Donos &amp; Franqueadores', false);
        $response->assertSee('Vendedores');
    }
}
