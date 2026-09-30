<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\GmbPostImagem;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado real 2026-09-29 (Leonardo): 92 posts do GMB da semana atual com
 * `imagem_url` fantasma (arquivo nunca existiu de verdade no disco), 37 já
 * marcados como 'falha' pelo Google. O fix em GmbImageSeoService protege
 * contra recorrência, mas não repara os posts já quebrados — este comando
 * reatribui uma foto válida da galeria a cada post afetado e reabre pra
 * publicação os que já tinham marcado 'falha' por causa da imagem.
 */
class GmbRecuperarImagensQuebradasCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private PerfilGmb $perfil;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->tenant = Tenant::create([
            'nome'  => 'Frete Teste',
            'slug'  => 'frete-teste',
            'nicho' => 'frete',
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

    private function criarPostQuebrado(string $status = 'falha'): GmbPost
    {
        return GmbPost::create([
            'tenant_id'     => $this->tenant->id,
            'perfil_gmb_id' => $this->perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now()->subDay(),
            'status'        => $status,
            'log_erro'      => 'Erro Google (500): Internal error encountered.',
            'imagem_url'    => Storage::disk('public')->url('gmb-posts/2026/09/foto-fantasma.png'),
        ]);
    }

    public function test_corrige_post_com_imagem_fantasma_usando_foto_valida_da_galeria_e_reabre_status_falha(): void
    {
        Storage::disk('public')->put('gmb-posts/galeria/1/foto-real.png', 'conteudo-fake');
        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/foto-real.png'),
        ]);

        $post = $this->criarPostQuebrado();

        $this->artisan('gmb:recuperar-imagens-quebradas')->assertSuccessful();

        $post->refresh();
        $this->assertStringNotContainsString('foto-fantasma', $post->imagem_url);

        $caminho = str_replace(Storage::disk('public')->url(''), '', $post->imagem_url);
        Storage::disk('public')->assertExists($caminho);

        $this->assertSame('agendado', $post->status);
        $this->assertNull($post->log_erro);
    }

    public function test_nao_mexe_em_post_cujo_status_falha_nao_e_por_imagem(): void
    {
        Storage::disk('public')->put('gmb-posts/galeria/1/foto-real.png', 'conteudo-fake');
        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/foto-real.png'),
        ]);

        $post = GmbPost::create([
            'tenant_id'     => $this->tenant->id,
            'perfil_gmb_id' => $this->perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now()->subDay(),
            'status'        => 'falha',
            'log_erro'      => 'Erro Google (403): token inválido',
            'imagem_url'    => Storage::disk('public')->url('gmb-posts/galeria/1/foto-real.png'),
        ]);

        $this->artisan('gmb:recuperar-imagens-quebradas')->assertSuccessful();

        $post->refresh();
        $this->assertSame('falha', $post->status);
        $this->assertSame('Erro Google (403): token inválido', $post->log_erro);
    }

    public function test_pula_post_quando_tenant_nao_tem_nenhuma_imagem_valida_na_galeria(): void
    {
        // Registro de galeria existe mas o arquivo dele também é fantasma.
        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/tambem-fantasma.png'),
        ]);

        $post = $this->criarPostQuebrado();
        $urlOriginal = $post->imagem_url;

        $this->artisan('gmb:recuperar-imagens-quebradas')->assertSuccessful();

        $post->refresh();
        $this->assertSame($urlOriginal, $post->imagem_url);
        $this->assertSame('falha', $post->status);
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        Storage::disk('public')->put('gmb-posts/galeria/1/foto-real.png', 'conteudo-fake');
        GmbPostImagem::create([
            'tenant_id'  => $this->tenant->id,
            'imagem_url' => Storage::disk('public')->url('gmb-posts/galeria/1/foto-real.png'),
        ]);

        $post = $this->criarPostQuebrado();
        $urlOriginal = $post->imagem_url;

        $this->artisan('gmb:recuperar-imagens-quebradas', ['--dry-run' => true])->assertSuccessful();

        $post->refresh();
        $this->assertSame($urlOriginal, $post->imagem_url);
        $this->assertSame('falha', $post->status);
    }

    public function test_nao_mexe_em_post_cuja_imagem_ja_e_valida(): void
    {
        Storage::disk('public')->put('gmb-posts/2026/09/foto-ja-valida.png', 'conteudo-fake');
        $urlValida = Storage::disk('public')->url('gmb-posts/2026/09/foto-ja-valida.png');

        $post = GmbPost::create([
            'tenant_id'     => $this->tenant->id,
            'perfil_gmb_id' => $this->perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now()->subDay(),
            'status'        => 'agendado',
            'imagem_url'    => $urlValida,
        ]);

        $this->artisan('gmb:recuperar-imagens-quebradas')->assertSuccessful();

        $post->refresh();
        $this->assertSame($urlValida, $post->imagem_url);
        $this->assertSame('agendado', $post->status);
    }
}
