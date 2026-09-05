<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MetaPostControllerTest extends TestCase
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

    private function criarPagina(Tenant $tenant): MetaPagina
    {
        $token = MetaToken::create(['tenant_id' => $tenant->id, 'access_token' => 'tok']);
        return MetaPagina::create([
            'tenant_id'          => $tenant->id,
            'meta_token_id'      => $token->id,
            'facebook_page_id'   => '1',
            'nome'               => 'Frete Rio',
            'page_access_token'  => 'p',
            'ativo'              => true,
        ]);
    }

    public function test_calendario_mostra_apenas_posts_da_semana_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);
        $pagina      = $this->criarPagina($tenant);

        MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Dentro da semana',
            'data_agendada'  => now(),
            'status'         => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Semana que vem',
            'data_agendada'  => now()->addWeeks(2),
            'status'         => 'agendado',
        ]);
        MetaPost::withoutGlobalScopes()->create([
            'tenant_id'      => $outroTenant->id,
            'canal_alvo'     => 'facebook',
            'texto'          => 'De outro tenant',
            'data_agendada'  => now(),
            'status'         => 'agendado',
        ]);

        $response = $this->actingAs($dono)->get(route('meta-posts.index'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['total_semana'] === 1);
    }

    public function test_cria_post_com_imagem_enviada_por_upload(): void
    {
        Storage::fake('public');

        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Frete rápido no Rio!',
            'imagem'         => UploadedFile::fake()->image('foto.jpg'),
            'cta_tipo'       => 'CALL',
            'cta_url'        => 'https://frete.rio.br',
            'modo_gatilho'   => 'nenhum',
            'data_agendada'  => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'tenant_id'  => $tenant->id,
            'canal_alvo' => 'facebook',
            'texto'      => 'Frete rápido no Rio!',
            'cta_tipo'   => 'CALL',
            'status'     => 'agendado',
        ]);
        $post = MetaPost::first();
        $this->assertNotNull($post->imagem_url);
        Storage::disk('public')->assertExists(str_replace(Storage::disk('public')->url(''), '', $post->imagem_url));
    }

    public function test_canal_instagram_nao_exige_pagina_do_facebook(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);
        $conta  = \App\Models\MetaContaInstagram::create([
            'tenant_id'             => $tenant->id,
            'meta_pagina_id'        => $pagina->id,
            'instagram_business_id' => '2',
            'username'              => 'frete.rio.br',
            'ativo'                 => true,
        ]);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'              => 'instagram',
            'meta_conta_instagram_id' => $conta->id,
            'texto'                   => 'Só Instagram',
            'imagem_url'              => 'https://cdn.exemplo.com/foto.jpg',
            'modo_gatilho'            => 'nenhum',
            'data_agendada'           => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'canal_alvo'              => 'instagram',
            'meta_conta_instagram_id' => $conta->id,
        ]);
    }
}
