<?php

namespace Tests\Feature;

use App\Models\GmbPostCategoria;
use App\Models\GmbPostImagem;
use App\Models\GmbPostTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado de revisão de segurança 22/09: o mesmo padrão de IDOR já corrigido
 * em destroyMascara() (route-model-binding sem checar tenant) também existe
 * em destroyImagem() e destroyCategoria() do GmbPostController.
 */
class GmbPostControllerIdorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Tenant $outroTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['nome' => 'Frete Teste', 'slug' => 'frete-teste', 'nicho' => 'frete']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Leonardo', 'email' => 'leo@teste.com',
            'password' => bcrypt('senha123'), 'perfil' => 'admin', 'ativo' => true,
        ]);
        $this->outroTenant = Tenant::create(['nome' => 'Outra Empresa', 'slug' => 'outra-empresa', 'nicho' => 'outro']);
    }

    public function test_nao_permite_remover_imagem_de_outro_tenant(): void
    {
        $imagemDeOutroTenant = GmbPostImagem::create([
            'tenant_id' => $this->outroTenant->id, 'tipo' => 'fundo',
            'imagem_url' => 'http://x/imagem.png', 'nome_arquivo_seo' => 'imagem.png',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.imagens.destroy', $imagemDeOutroTenant))
            ->assertNotFound();

        $this->assertDatabaseHas('gmb_post_imagens', ['id' => $imagemDeOutroTenant->id]);
    }

    public function test_permite_remover_propria_imagem(): void
    {
        $imagem = GmbPostImagem::create([
            'tenant_id' => $this->tenant->id, 'tipo' => 'fundo',
            'imagem_url' => 'http://x/imagem.png', 'nome_arquivo_seo' => 'imagem.png',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.imagens.destroy', $imagem))
            ->assertRedirect();

        $this->assertDatabaseMissing('gmb_post_imagens', ['id' => $imagem->id]);
    }

    public function test_nao_permite_remover_categoria_de_outro_tenant(): void
    {
        $categoriaDeOutroTenant = GmbPostCategoria::create([
            'tenant_id' => $this->outroTenant->id, 'nome' => 'Categoria Alheia', 'slug' => 'categoria-alheia',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.categorias.destroy', $categoriaDeOutroTenant))
            ->assertNotFound();

        $this->assertDatabaseHas('gmb_post_categorias', ['id' => $categoriaDeOutroTenant->id]);
    }

    public function test_nao_permite_remover_categoria_global_compartilhada(): void
    {
        $categoriaGlobal = GmbPostCategoria::create([
            'tenant_id' => null, 'nome' => 'Categoria Global', 'slug' => 'categoria-global',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.categorias.destroy', $categoriaGlobal))
            ->assertNotFound();

        $this->assertDatabaseHas('gmb_post_categorias', ['id' => $categoriaGlobal->id]);
    }

    public function test_permite_remover_propria_categoria(): void
    {
        $categoria = GmbPostCategoria::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Categoria Minha', 'slug' => 'categoria-minha',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.categorias.destroy', $categoria))
            ->assertRedirect();

        $this->assertDatabaseMissing('gmb_post_categorias', ['id' => $categoria->id]);
    }

    /**
     * GmbPostTemplate já tem TenantScope (global scope) no model — o
     * route-model-binding sozinho já bloqueia acesso cross-tenant antes de
     * chegar no controller. Teste de confirmação, não é um fix novo.
     */
    public function test_nao_permite_remover_template_de_outro_tenant(): void
    {
        $templateDeOutroTenant = GmbPostTemplate::create([
            'tenant_id' => $this->outroTenant->id, 'categoria' => 'promocoes',
            'titulo_template' => 'Template Alheio', 'texto_template' => 'texto',
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.templates.destroy', $templateDeOutroTenant))
            ->assertNotFound();

        $this->assertDatabaseHas('gmb_post_templates', ['id' => $templateDeOutroTenant->id]);
    }
}
