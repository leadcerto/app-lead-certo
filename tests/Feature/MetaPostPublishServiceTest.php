<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Services\MetaPostPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaPostPublishServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPaginaEConta(Tenant $tenant): array
    {
        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);

        $pagina = MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1111111111',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'page-tok',
            'ativo'             => true,
        ]);

        $conta = MetaContaInstagram::create([
            'tenant_id'             => $tenant->id,
            'meta_pagina_id'        => $pagina->id,
            'instagram_business_id' => '2222222222',
            'username'              => 'frete.rio.br',
            'ativo'                 => true,
        ]);

        return [$pagina, $conta];
    }

    public function test_publica_em_ambos_canais_e_cria_dois_gatilhos(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos'        => Http::response(['id' => 'FB_POST_123'], 200),
            'graph.facebook.com/*/media_publish' => Http::response(['id' => 'IG_MEDIA_789'], 200),
            'graph.facebook.com/*/media'         => Http::response(['id' => 'IG_CONTAINER_456'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id'               => $tenant->id,
            'canal_alvo'              => 'ambos',
            'meta_pagina_id'          => $pagina->id,
            'meta_conta_instagram_id' => $conta->id,
            'texto'                   => 'Frete rápido no Rio!',
            'imagem_url'              => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'           => now(),
            'status'                  => 'agendado',
            'modo_gatilho'            => 'palavra_chave',
            'palavras_chave'          => ['orçamento'],
            'mensagem_direct'         => 'Oi! Aqui está nosso WhatsApp: 21999999999',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertTrue($sucesso);
        $post->refresh();
        $this->assertSame('publicado', $post->status);
        $this->assertSame('FB_POST_123', $post->facebook_post_id);
        $this->assertSame('IG_MEDIA_789', $post->instagram_media_id);
        $this->assertNotNull($post->publicado_em);

        $this->assertSame(2, MetaCampanhaGatilho::where('meta_post_id', $post->id)->count());
        $this->assertDatabaseHas('meta_campanhas_gatilho', [
            'meta_post_id'       => $post->id,
            'canal_alvo'         => 'facebook',
            'post_id_especifico' => 'FB_POST_123',
            'ativo'              => true,
        ]);
        $this->assertDatabaseHas('meta_campanhas_gatilho', [
            'meta_post_id'       => $post->id,
            'canal_alvo'         => 'instagram',
            'post_id_especifico' => 'IG_MEDIA_789',
            'ativo'              => true,
        ]);
    }

    public function test_falha_parcial_nao_cria_gatilho_e_preserva_canal_que_deu_certo(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_123'], 200),
            'graph.facebook.com/*/media'  => Http::response(['error' => ['message' => 'boom']], 400),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id'               => $tenant->id,
            'canal_alvo'              => 'ambos',
            'meta_pagina_id'          => $pagina->id,
            'meta_conta_instagram_id' => $conta->id,
            'texto'                   => 'x',
            'imagem_url'              => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'           => now(),
            'status'                  => 'agendado',
            'modo_gatilho'            => 'nenhum',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertFalse($sucesso);
        $post->refresh();
        $this->assertSame('falha', $post->status);
        $this->assertSame('FB_POST_123', $post->facebook_post_id, 'Facebook publicou e o id deve ficar gravado');
        $this->assertNull($post->instagram_media_id);
        $this->assertSame(0, MetaCampanhaGatilho::count());
    }

    public function test_retry_nao_republica_no_canal_que_ja_tinha_dado_certo(): void
    {
        Http::fake([
            'graph.facebook.com/*/media_publish' => Http::response(['id' => 'IG_MEDIA_789'], 200),
            'graph.facebook.com/*/media'         => Http::response(['id' => 'IG_CONTAINER_456'], 200),
        ]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina, $conta] = $this->criarPaginaEConta($tenant);

        // Simula estado deixado por uma tentativa anterior: Facebook já publicou.
        $post = MetaPost::create([
            'tenant_id'               => $tenant->id,
            'canal_alvo'              => 'ambos',
            'meta_pagina_id'          => $pagina->id,
            'meta_conta_instagram_id' => $conta->id,
            'texto'                   => 'x',
            'imagem_url'              => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'           => now(),
            'status'                  => 'falha',
            'facebook_post_id'        => 'FB_POST_JA_PUBLICADO',
            'modo_gatilho'            => 'nenhum',
        ]);

        $sucesso = app(MetaPostPublishService::class)->publicar($post);

        $this->assertTrue($sucesso);
        $post->refresh();
        $this->assertSame('publicado', $post->status);
        $this->assertSame('FB_POST_JA_PUBLICADO', $post->facebook_post_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/photos'));
    }

    public function test_modo_gatilho_nenhum_nao_cria_regra_comment_to_dm(): void
    {
        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_123'], 200)]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);
        [$pagina] = $this->criarPaginaEConta($tenant);

        $post = MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'x',
            'imagem_url'     => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'  => now(),
            'status'         => 'agendado',
            'modo_gatilho'   => 'nenhum',
        ]);

        app(MetaPostPublishService::class)->publicar($post);

        $this->assertSame(0, MetaCampanhaGatilho::count());
    }
}
