<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Services\GmbImageSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado real 2026-09-29 (Leonardo, 92 posts da semana com "Erro Google (500):
 * Internal error encountered" e miniatura quebrada): todos os posts tinham
 * `imagem_url` apontando pra um arquivo que não existe mais no disco.
 * prepararImagemParaPost() copia/move a imagem pro nome final SEO mas nunca
 * verificava se a operação realmente funcionou — mesmo que copy()/move()
 * falhasse, o post era atualizado como se a imagem estivesse lá, e o problema
 * só aparecia semanas depois, na hora de publicar (o Google tenta buscar a
 * imagem na nossa URL e recebe 404).
 */
class GmbImageSeoServicePrepararImagemTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfil(Tenant $tenant): PerfilGmb
    {
        return PerfilGmb::create([
            'tenant_id' => $tenant->id,
            'nome'      => 'Frete Rio — Tijuca',
            'city'      => 'Rio de Janeiro',
            'state'     => 'RJ',
            'link_gmb'  => 'https://maps.google.com/?cid=123',
            'ativo'     => true,
        ]);
    }

    private function criarPostComImagem(Tenant $tenant, string $caminhoOrigem): GmbPost
    {
        Storage::disk('public')->put($caminhoOrigem, 'conteudo-fake-de-imagem');

        return GmbPost::create([
            'tenant_id'     => $tenant->id,
            'perfil_gmb_id' => $this->criarPerfil($tenant)->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now(),
            'status'        => 'agendado',
            'imagem_url'    => Storage::disk('public')->url($caminhoOrigem),
        ]);
    }

    public function test_atualiza_imagem_url_normalmente_quando_copy_funciona(): void
    {
        Storage::fake('public');
        $tenant = Tenant::factory()->create(['nome' => 'Frete Rio', 'nicho' => 'frete']);
        $post   = $this->criarPostComImagem($tenant, 'gmb-posts/galeria/1/foto-original.png');

        app(GmbImageSeoService::class)->prepararImagemParaPost($post);

        $post->refresh();
        $this->assertStringNotContainsString('foto-original.png', $post->imagem_url);

        $novoCaminho = str_replace(Storage::disk('public')->url(''), '', $post->imagem_url);
        Storage::disk('public')->assertExists($novoCaminho);
    }

    public function test_nao_atualiza_imagem_url_quando_copy_falha_e_registra_log_de_erro(): void
    {
        $tenant = Tenant::factory()->create(['nome' => 'Frete Rio', 'nicho' => 'frete']);

        $caminhoOrigem = 'gmb-posts/galeria/1/foto-original.png';
        $urlOriginal   = 'https://app.leadcerto.app.br/storage/' . $caminhoOrigem;

        $post = GmbPost::create([
            'tenant_id'     => $tenant->id,
            'perfil_gmb_id' => $this->criarPerfil($tenant)->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now(),
            'status'        => 'agendado',
            'imagem_url'    => $urlOriginal,
        ]);

        // Simula copy() falhando (disco cheio, permissão, etc.) sem lançar
        // exceção — mesmo comportamento real do adapter local do Laravel.
        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('url')->andReturnUsing(fn (string $path = '') => 'https://app.leadcerto.app.br/storage/' . $path);
        Storage::shouldReceive('exists')->with($caminhoOrigem)->andReturn(true);
        Storage::shouldReceive('copy')->andReturn(false);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($mensagem) => str_contains($mensagem, 'falha ao copiar/mover imagem'));

        app(GmbImageSeoService::class)->prepararImagemParaPost($post);

        $post->refresh();
        $this->assertSame(
            $urlOriginal,
            $post->imagem_url,
            'imagem_url não pode ser atualizada pra um caminho cujo arquivo não foi criado de verdade'
        );
    }

    public function test_nao_atualiza_imagem_url_quando_move_falha_e_registra_log_de_erro(): void
    {
        $tenant = Tenant::factory()->create(['nome' => 'Frete Rio', 'nicho' => 'frete']);

        // Caminho SEM "galeria/" -> cai no ramo move() em vez de copy().
        $caminhoOrigem = 'gmb-posts/temp/foto-recem-gerada.png';
        $urlOriginal   = 'https://app.leadcerto.app.br/storage/' . $caminhoOrigem;

        $post = GmbPost::create([
            'tenant_id'     => $tenant->id,
            'perfil_gmb_id' => $this->criarPerfil($tenant)->id,
            'tipo'          => 'novidade',
            'texto'         => 'Post de teste',
            'titulo'        => 'Titulo Teste',
            'data_agendada' => now(),
            'status'        => 'agendado',
            'imagem_url'    => $urlOriginal,
        ]);

        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('url')->andReturnUsing(fn (string $path = '') => 'https://app.leadcerto.app.br/storage/' . $path);
        Storage::shouldReceive('exists')->with($caminhoOrigem)->andReturn(true);
        Storage::shouldReceive('move')->andReturn(false);

        Log::shouldReceive('error')->once();

        app(GmbImageSeoService::class)->prepararImagemParaPost($post);

        $post->refresh();
        $this->assertSame($urlOriginal, $post->imagem_url);
    }

    /**
     * Achado real 2026-09-29: mesmo com prepararImagemParaPost() agora
     * blindado, o "Gerador em Lote" ainda não tinha nenhuma checagem própria
     * — se o arquivo de origem nunca existiu de verdade (ou sumiu entre a
     * escolha da imagem e a criação do post), o post era criado do mesmo jeito,
     * com `imagem_url` apontando pra um caminho fantasma, e só ia dar erro
     * semanas depois na publicação. imagemUrlValidaNoDisco() dá pro controller
     * um jeito de confirmar a existência real do arquivo logo após a criação.
     */
    public function test_imagem_url_valida_no_disco_confirma_arquivo_existente(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('gmb-posts/2026/09/foto-existente.png', 'conteudo-fake');

        $url = Storage::disk('public')->url('gmb-posts/2026/09/foto-existente.png');

        $this->assertTrue(app(GmbImageSeoService::class)->imagemUrlValidaNoDisco($url));
    }

    public function test_imagem_url_valida_no_disco_detecta_arquivo_fantasma(): void
    {
        Storage::fake('public');

        $url = Storage::disk('public')->url('gmb-posts/2026/09/foto-que-nao-existe.png');

        $this->assertFalse(app(GmbImageSeoService::class)->imagemUrlValidaNoDisco($url));
    }

    public function test_imagem_url_valida_no_disco_retorna_false_pra_url_vazia_ou_externa(): void
    {
        $service = app(GmbImageSeoService::class);

        $this->assertFalse($service->imagemUrlValidaNoDisco(null));
        $this->assertFalse($service->imagemUrlValidaNoDisco(''));
        $this->assertFalse($service->imagemUrlValidaNoDisco('https://exemplo-externo.com/foto.png'));
    }
}
