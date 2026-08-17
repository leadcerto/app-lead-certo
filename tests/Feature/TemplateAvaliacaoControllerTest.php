<?php

namespace Tests\Feature;

use App\Models\CategoriaTemplate;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateAvaliacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function criarCategoria(Tenant $tenant, string $nome = 'Elogio geral'): CategoriaTemplate
    {
        return CategoriaTemplate::create(['tenant_id' => $tenant->id, 'nome' => $nome]);
    }

    private function criarTemplate(Tenant $tenant, array $atributos = []): TemplateAvaliacao
    {
        $categoria = $atributos['categoria_id'] ?? $this->criarCategoria($tenant)->id;

        return TemplateAvaliacao::create(array_merge([
            'tenant_id'    => $tenant->id,
            'codigo'       => 'T-' . uniqid(),
            'texto'        => 'Atendimento excelente, super recomendo!',
            'categoria_id' => $categoria,
            'ativo'        => true,
        ], $atributos));
    }

    public function test_lista_apenas_templates_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);

        $this->criarTemplate($tenant);
        $this->criarTemplate($outroTenant);

        $response = $this->actingAs($dono)->get('/admin/gmb/templates-avaliacao');

        $response->assertOk();
        $response->assertViewHas('templates', fn ($templates) => $templates->total() === 1);
    }

    public function test_cria_template(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $categoria = $this->criarCategoria($tenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/templates-avaliacao', [
            'codigo'       => 'ELG-01',
            'texto'        => 'Mudança rápida e cuidadosa, recomendo!',
            'categoria_id' => $categoria->id,
        ]);

        $response->assertRedirect(route('admin.templates-avaliacao.index'));
        $this->assertDatabaseHas('templates_avaliacao', [
            'tenant_id' => $tenant->id,
            'codigo'    => 'ELG-01',
            'ativo'     => true,
        ]);
    }

    public function test_nao_cria_template_com_codigo_duplicado_no_mesmo_tenant(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $categoria = $this->criarCategoria($tenant);
        $this->criarTemplate($tenant, ['codigo' => 'DUP-01', 'categoria_id' => $categoria->id]);

        $response = $this->actingAs($dono)->post('/admin/gmb/templates-avaliacao', [
            'codigo'       => 'DUP-01',
            'texto'        => 'Outro texto qualquer.',
            'categoria_id' => $categoria->id,
        ]);

        $response->assertSessionHasErrors('codigo');
        $this->assertSame(1, TemplateAvaliacao::where('tenant_id', $tenant->id)->where('codigo', 'DUP-01')->count());
    }

    public function test_tela_de_edicao_carrega_o_template_correto(): void
    {
        // Regressão: mesmo bug de binding do PerfilGmbController.
        $tenant   = Tenant::factory()->create();
        $dono     = $this->usuarioDono($tenant);
        $template = $this->criarTemplate($tenant, ['texto' => 'Texto original único de teste']);

        $response = $this->actingAs($dono)->get("/admin/gmb/templates-avaliacao/{$template->id}/edit");

        $response->assertOk();
        $response->assertSee('Texto original único de teste');
    }

    public function test_atualiza_template_existente_sem_criar_registro_novo(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $categoria = $this->criarCategoria($tenant);
        $template  = $this->criarTemplate($tenant, ['categoria_id' => $categoria->id]);

        $response = $this->actingAs($dono)->put("/admin/gmb/templates-avaliacao/{$template->id}", [
            'codigo'       => $template->codigo,
            'texto'        => 'Texto atualizado.',
            'categoria_id' => $categoria->id,
        ]);

        $response->assertRedirect(route('admin.templates-avaliacao.index'));
        $this->assertSame(1, TemplateAvaliacao::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('templates_avaliacao', ['id' => $template->id, 'texto' => 'Texto atualizado.']);
    }

    public function test_desativa_template(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->usuarioDono($tenant);
        $template = $this->criarTemplate($tenant);

        $response = $this->actingAs($dono)->delete("/admin/gmb/templates-avaliacao/{$template->id}");

        $response->assertRedirect(route('admin.templates-avaliacao.index'));
        $this->assertDatabaseHas('templates_avaliacao', ['id' => $template->id, 'ativo' => false]);
    }

    public function test_nao_edita_template_de_outro_tenant(): void
    {
        $tenant        = Tenant::factory()->create();
        $outroTenant   = Tenant::factory()->create();
        $dono          = $this->usuarioDono($tenant);
        $templateAlheio = $this->criarTemplate($outroTenant);

        $response = $this->actingAs($dono)->get("/admin/gmb/templates-avaliacao/{$templateAlheio->id}/edit");

        $response->assertForbidden();
    }

    public function test_nao_atualiza_template_de_outro_tenant(): void
    {
        $tenant         = Tenant::factory()->create();
        $outroTenant    = Tenant::factory()->create();
        $dono           = $this->usuarioDono($tenant);
        $templateAlheio = $this->criarTemplate($outroTenant, ['texto' => 'Original']);

        $response = $this->actingAs($dono)->put("/admin/gmb/templates-avaliacao/{$templateAlheio->id}", [
            'codigo'       => $templateAlheio->codigo,
            'texto'        => 'Sequestrado',
            'categoria_id' => $templateAlheio->categoria_id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('templates_avaliacao', ['id' => $templateAlheio->id, 'texto' => 'Original']);
    }

    public function test_nao_desativa_template_de_outro_tenant(): void
    {
        $tenant         = Tenant::factory()->create();
        $outroTenant    = Tenant::factory()->create();
        $dono           = $this->usuarioDono($tenant);
        $templateAlheio = $this->criarTemplate($outroTenant);

        $response = $this->actingAs($dono)->delete("/admin/gmb/templates-avaliacao/{$templateAlheio->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('templates_avaliacao', ['id' => $templateAlheio->id, 'ativo' => true]);
    }

    public function test_lista_categorias_apenas_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->usuarioDono($tenant);

        $this->criarCategoria($tenant, 'Minha categoria');
        $this->criarCategoria($outroTenant, 'Categoria alheia');

        $response = $this->actingAs($dono)->get('/admin/gmb/categorias');

        $response->assertOk();
        $response->assertSee('Minha categoria');
        $response->assertDontSee('Categoria alheia');
    }

    public function test_cria_categoria(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->usuarioDono($tenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/categorias', ['nome' => 'Elogio ao motorista']);

        $response->assertRedirect(route('admin.templates-avaliacao.categorias'));
        $this->assertDatabaseHas('categorias_template', ['tenant_id' => $tenant->id, 'nome' => 'Elogio ao motorista']);
    }

    public function test_nao_remove_categoria_com_templates_vinculados(): void
    {
        $tenant    = Tenant::factory()->create();
        $dono      = $this->usuarioDono($tenant);
        $categoria = $this->criarCategoria($tenant);
        $this->criarTemplate($tenant, ['categoria_id' => $categoria->id]);

        $response = $this->actingAs($dono)->delete("/admin/gmb/categorias/{$categoria->id}");

        $response->assertSessionHasErrors('categoria');
        $this->assertDatabaseHas('categorias_template', ['id' => $categoria->id]);
    }

    public function test_nao_remove_categoria_de_outro_tenant(): void
    {
        $tenant          = Tenant::factory()->create();
        $outroTenant     = Tenant::factory()->create();
        $dono            = $this->usuarioDono($tenant);
        $categoriaAlheia = $this->criarCategoria($outroTenant);

        $response = $this->actingAs($dono)->delete("/admin/gmb/categorias/{$categoriaAlheia->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('categorias_template', ['id' => $categoriaAlheia->id]);
    }
}
