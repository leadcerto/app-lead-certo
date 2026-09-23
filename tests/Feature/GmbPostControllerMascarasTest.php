<?php

namespace Tests\Feature;

use App\Models\GmbPostImagem;
use App\Models\ImagemMascara;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado real 2026-09-22 (Leonardo, projeto Banco de Imagens com Máscara,
 * Fase 2): upload de máscara PNG + aplicar em lote nas fotos da galeria.
 */
class GmbPostControllerMascarasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['nome' => 'Frete Teste', 'slug' => 'frete-teste', 'nicho' => 'frete']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Leonardo', 'email' => 'leo@teste.com',
            'password' => bcrypt('senha123'), 'perfil' => 'admin', 'ativo' => true,
        ]);
    }

    private function gerarPngComJanela(): string
    {
        $imagem = imagecreatetruecolor(200, 150);
        imagesavealpha($imagem, true);
        imagealphablending($imagem, false);
        $vermelho = imagecolorallocatealpha($imagem, 200, 0, 0, 0);
        $transparente = imagecolorallocatealpha($imagem, 0, 0, 0, 127);
        imagefill($imagem, 0, 0, $vermelho);
        imagefilledrectangle($imagem, 20, 30, 119, 109, $transparente);

        $caminho = tempnam(sys_get_temp_dir(), 'mascara_upload_') . '.png';
        imagepng($imagem, $caminho);
        imagedestroy($imagem);

        return $caminho;
    }

    public function test_upload_de_mascara_detecta_janela_automaticamente(): void
    {
        Storage::fake('public');
        $caminho = $this->gerarPngComJanela();

        $response = $this->actingAs($this->user)->post(route('admin.gmb-posts.mascaras.store'), [
            'nome'    => 'Padrão Frete Rio',
            'mascara' => new UploadedFile($caminho, 'mascara.png', 'image/png', null, true),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('imagem_mascaras', [
            'tenant_id'      => $this->tenant->id,
            'nome'           => 'Padrão Frete Rio',
            'janela_x'       => 20,
            'janela_y'       => 30,
            'janela_largura' => 100,
            'janela_altura'  => 80,
            'largura_total'  => 200,
            'altura_total'   => 150,
        ]);

        unlink($caminho);
    }

    public function test_remover_mascara_marca_como_inativa_sem_apagar(): void
    {
        $mascara = ImagemMascara::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Teste', 'arquivo_url' => 'http://x/mascara.png',
            'janela_x' => 0, 'janela_y' => 0, 'janela_largura' => 10, 'janela_altura' => 10,
            'largura_total' => 10, 'altura_total' => 10,
        ]);

        $this->actingAs($this->user)->delete(route('admin.gmb-posts.mascaras.destroy', $mascara))->assertRedirect();

        $this->assertDatabaseHas('imagem_mascaras', ['id' => $mascara->id, 'ativo' => false]);
    }

    /**
     * Achado de revisão de segurança (22/09): destroyMascara() usava
     * route-model-binding direto sem checar se a máscara pertence ao tenant
     * do usuário logado — qualquer usuário autenticado de QUALQUER tenant
     * conseguia desativar a máscara de outro tenant só trocando o ID na URL.
     */
    public function test_nao_permite_remover_mascara_de_outro_tenant(): void
    {
        $outroTenant = Tenant::create(['nome' => 'Outra Empresa', 'slug' => 'outra-empresa', 'nicho' => 'outro']);
        $mascaraDeOutroTenant = ImagemMascara::create([
            'tenant_id' => $outroTenant->id, 'nome' => 'Máscara Alheia', 'arquivo_url' => 'http://x/mascara.png',
            'janela_x' => 0, 'janela_y' => 0, 'janela_largura' => 10, 'janela_altura' => 10,
            'largura_total' => 10, 'altura_total' => 10,
        ]);

        $this->actingAs($this->user)
            ->delete(route('admin.gmb-posts.mascaras.destroy', $mascaraDeOutroTenant))
            ->assertNotFound();

        $this->assertDatabaseHas('imagem_mascaras', ['id' => $mascaraDeOutroTenant->id, 'ativo' => true]);
    }

    public function test_aplicar_mascara_em_fotos_selecionadas_gera_imagens_prontas_sem_apagar_as_originais(): void
    {
        Storage::fake('public');

        $mascaraArquivo = $this->gerarPngComJanela();
        Storage::disk('public')->put('mascaras/teste.png', file_get_contents($mascaraArquivo));

        $mascara = ImagemMascara::create([
            'tenant_id' => $this->tenant->id, 'nome' => 'Teste',
            'arquivo_url' => Storage::disk('public')->url('mascaras/teste.png'),
            'janela_x' => 20, 'janela_y' => 30, 'janela_largura' => 100, 'janela_altura' => 80,
            'largura_total' => 200, 'altura_total' => 150,
        ]);

        $fotoFundoImagem = imagecreatetruecolor(400, 400);
        imagefill($fotoFundoImagem, 0, 0, imagecolorallocate($fotoFundoImagem, 0, 150, 0));
        $caminhoFundo = tempnam(sys_get_temp_dir(), 'fundo_') . '.png';
        imagepng($fotoFundoImagem, $caminhoFundo);
        imagedestroy($fotoFundoImagem);
        Storage::disk('public')->put('gmb-posts/fundo-teste.png', file_get_contents($caminhoFundo));
        unlink($caminhoFundo);

        $fotoFundo = GmbPostImagem::create([
            'tenant_id' => $this->tenant->id, 'tipo' => 'fundo', 'titulo' => 'Foto Teste',
            'imagem_url' => Storage::disk('public')->url('gmb-posts/fundo-teste.png'),
            'nome_arquivo_seo' => 'fundo-teste.png',
        ]);

        $response = $this->actingAs($this->user)->post(route('admin.gmb-posts.imagens.aplicar-mascara'), [
            'imagem_mascara_id'    => $mascara->id,
            'imagens_selecionadas' => [$fotoFundo->id],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('gmb_post_imagens', ['id' => $fotoFundo->id, 'tipo' => 'fundo']); // original intacta
        $this->assertDatabaseHas('gmb_post_imagens', [
            'tenant_id' => $this->tenant->id, 'tipo' => 'pronta', 'imagem_mascara_id' => $mascara->id,
        ]);
        $this->assertSame(2, GmbPostImagem::where('tenant_id', $this->tenant->id)->count());
    }
}
