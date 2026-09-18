<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaPostConteudo;
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

    /**
     * Passo 4 do Banco de Conteúdos (2026-09-17): criar uma postagem a
     * partir de um conteúdo salvo grava a referência.
     */
    public function test_cria_post_a_partir_de_conteudo_salvo_guarda_a_referencia(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->dono($tenant);
        $pagina   = $this->criarPagina($tenant);
        $conteudo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'Promo', 'texto' => 'x']);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'             => 'facebook',
            'meta_pagina_id'         => $pagina->id,
            'meta_post_conteudo_id'  => $conteudo->id,
            'texto'                  => 'Frete rápido no Rio!',
            'modo_gatilho'           => 'nenhum',
            'data_agendada'          => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'texto'                 => 'Frete rápido no Rio!',
            'meta_post_conteudo_id' => $conteudo->id,
        ]);
    }

    /**
     * Regressão: criar post sem vir de um conteúdo salvo (o fluxo de hoje,
     * 100% das postagens existentes) continua funcionando idêntico.
     */
    public function test_cria_post_avulso_sem_conteudo_salvo_continua_funcionando(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Post avulso',
            'modo_gatilho'   => 'nenhum',
            'data_agendada'  => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'texto'                 => 'Post avulso',
            'meta_post_conteudo_id' => null,
        ]);
    }

    public function test_tela_nova_publicacao_recebe_conteudos_salvos_ativos(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);

        $ativo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'Ativo', 'texto' => 'x', 'ativo' => true]);
        MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'Inativo', 'texto' => 'x', 'ativo' => false]);

        $response = $this->actingAs($dono)->get(route('meta-posts.create'));

        $response->assertOk();
        $response->assertViewHas('conteudosSalvos', function ($conteudos) use ($ativo) {
            return $conteudos->count() === 1 && $conteudos->first()->id === $ativo->id;
        });
    }

    /**
     * Regressão: os modais de imagem/texto do GMB continuam presentes e
     * funcionando na tela de Nova Publicação depois da mudança deste passo.
     */
    public function test_tela_nova_publicacao_continua_mostrando_bancos_do_gmb(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);

        $response = $this->actingAs($dono)->get(route('meta-posts.create'));

        $response->assertOk();
        $response->assertSee('Banco de Imagens da Empresa');
        $response->assertSee('Banco de Textos');
    }

    /**
     * Passo 5 do Banco de Conteúdos (2026-09-17): "Duplicar" abre a tela de
     * Nova Publicação já pré-preenchida a partir de um post existente.
     */
    public function test_duplicar_de_pre_preenche_formulario_a_partir_de_post_existente(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->dono($tenant);
        $pagina   = $this->criarPagina($tenant);
        $conteudo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'x', 'texto' => 'x']);

        $original = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'meta_post_conteudo_id' => $conteudo->id,
            'texto' => 'Texto original pra duplicar', 'imagem_url' => 'https://cdn.exemplo.com/x.jpg',
            'cta_tipo' => 'CALL', 'cta_url' => 'https://wa.me/5521999999999',
            'data_agendada' => now()->subDay(), 'status' => 'publicado',
        ]);

        $response = $this->actingAs($dono)->get(route('meta-posts.create', ['duplicar_de' => $original->id]));

        $response->assertOk();
        $response->assertViewHas('postOrigem', function ($post) use ($original) {
            return $post !== null && $post->id === $original->id;
        });
        $response->assertSee('Texto original pra duplicar');
    }

    public function test_duplicar_de_ignora_post_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);

        $postAlheio = MetaPost::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'Não deveria vazar', 'data_agendada' => now(), 'status' => 'agendado',
        ]);

        $response = $this->actingAs($dono)->get(route('meta-posts.create', ['duplicar_de' => $postAlheio->id]));

        $response->assertOk();
        $response->assertViewHas('postOrigem', fn ($post) => $post === null);
        $response->assertDontSee('Não deveria vazar');
    }
}
