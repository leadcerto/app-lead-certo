<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaPostControllerPublicarCancelarTest extends TestCase
{
    use RefreshDatabase;

    private function dono(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);
    }

    public function test_publicar_agora_dispara_o_servico_de_publicacao(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'FB_POST_1'], 200)]);

        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $token  = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        $pagina = MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'p',
            'ativo'             => true,
        ]);
        $post = MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'x',
            'imagem_url'     => 'https://cdn.exemplo.com/foto.jpg',
            'data_agendada'  => now()->addDay(),
            'status'         => 'agendado',
        ]);

        $response = $this->actingAs($dono)->post(route('meta-posts.publicar-agora', $post));

        $response->assertRedirect();
        $this->assertSame('publicado', $post->fresh()->status);
    }

    public function test_cancelar_marca_status_e_desativa_gatilhos_vinculados(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $post   = MetaPost::create([
            'tenant_id'     => $tenant->id,
            'canal_alvo'    => 'facebook',
            'texto'         => 'x',
            'data_agendada' => now()->addDay(),
            'status'        => 'agendado',
        ]);
        $gatilho = MetaCampanhaGatilho::create([
            'tenant_id'       => $tenant->id,
            'nome'            => 'Auto',
            'canal_alvo'      => 'facebook',
            'modo_gatilho'    => 'qualquer_comentario',
            'mensagem_direct' => 'oi',
            'meta_post_id'    => $post->id,
            'ativo'           => true,
        ]);

        $response = $this->actingAs($dono)->delete(route('meta-posts.destroy', $post));

        $response->assertRedirect();
        $this->assertSame('cancelado', $post->fresh()->status);
        $this->assertFalse($gatilho->fresh()->ativo);
    }
}
