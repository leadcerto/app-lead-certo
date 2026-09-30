<?php

namespace Tests\Feature;

use App\Models\GmbPostImagem;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado real 2026-09-29 (92 posts com "Erro Google (500)" e miniatura
 * quebrada, só descobertos semanas depois na publicação): o "Gerador em
 * Lote" não confirmava, na hora de criar os posts, que a imagem escolhida
 * realmente existia no disco. Agora, se a imagem de algum post não puder
 * ser confirmada logo após a criação, o resumo do lote avisa o usuário na
 * hora, em vez de deixar o problema só aparecer no dia da publicação.
 */
class GmbPostControllerStoreLoteImagemTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private PerfilGmb $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'nome'  => 'Frete Teste',
            'slug'  => 'frete-teste',
            'nicho' => 'frete',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'nome'      => 'Leonardo',
            'email'     => 'leo@teste.com',
            'password'  => bcrypt('senha123'),
            'perfil'    => 'admin',
            'ativo'     => true,
        ]);

        $this->perfil = PerfilGmb::create([
            'tenant_id' => $this->tenant->id,
            'nome'      => 'Frete Rio - Tijuca',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    public function test_resumo_do_lote_nao_avisa_quando_imagem_da_galeria_existe_de_verdade(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('gmb-posts/galeria/1/foto-original.png', 'conteudo-fake');

        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/foto-original.png'),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('admin.gmb-posts.lote.store'), [
                'matriz'            => [$this->perfil->id => ['segunda' => 1]],
                'semana_referencia' => now()->addWeek()->toDateString(),
                'modo_conteudo'     => 'template_rotativo',
                'modo_imagem'       => 'galeria_rotativa',
            ]);

        $response->assertRedirect();
        $mensagem = $response->getSession()->get('sucesso');
        $this->assertStringNotContainsString('Atenção', $mensagem);
    }

    public function test_resumo_do_lote_avisa_quando_imagem_escolhida_nao_existe_no_disco(): void
    {
        Storage::fake('public');
        // Registro na galeria aponta pra um arquivo que nunca foi criado de verdade.
        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/foto-fantasma.png'),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('admin.gmb-posts.lote.store'), [
                'matriz'            => [$this->perfil->id => ['segunda' => 1]],
                'semana_referencia' => now()->addWeek()->toDateString(),
                'modo_conteudo'     => 'template_rotativo',
                'modo_imagem'       => 'galeria_rotativa',
            ]);

        $response->assertRedirect();
        $mensagem = $response->getSession()->get('sucesso');
        $this->assertStringContainsString('Atenção: 1 postagem', $mensagem);
    }
}
