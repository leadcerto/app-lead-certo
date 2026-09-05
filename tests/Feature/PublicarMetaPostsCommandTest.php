<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicarMetaPostsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_publica_apenas_posts_agendados_vencidos(): void
    {
        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'FB_POST_1'], 200)]);

        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        $pagina = MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'p',
            'ativo'             => true,
        ]);

        $vencido = MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'x',
            'imagem_url'     => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'  => now()->subMinute(),
            'status'         => 'agendado',
        ]);
        $futuro = MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'x',
            'data_agendada'  => now()->addDay(),
            'status'         => 'agendado',
        ]);

        $this->artisan('meta:publicar-posts')->assertExitCode(0);

        $this->assertSame('publicado', $vencido->fresh()->status);
        $this->assertSame('agendado', $futuro->fresh()->status);
    }

    public function test_sem_posts_pendentes_nao_falha(): void
    {
        $this->artisan('meta:publicar-posts')->assertExitCode(0);
    }
}
