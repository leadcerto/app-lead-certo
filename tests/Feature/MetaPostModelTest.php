<?php

namespace Tests\Feature;

use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaPostConteudo;
use App\Models\MetaToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPostModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarPagina(Tenant $tenant): MetaPagina
    {
        $token = MetaToken::create([
            'tenant_id'    => $tenant->id,
            'access_token' => 'token-teste',
        ]);

        return MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1111111111',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'page-token-teste',
            'ativo'             => true,
        ]);
    }

    public function test_cria_post_agendado_apenas_para_o_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $pagina      = $this->criarPagina($tenant);

        session(['tenant_id' => $tenant->id]);

        MetaPost::create([
            'tenant_id'      => $tenant->id,
            'canal_alvo'     => 'facebook',
            'meta_pagina_id' => $pagina->id,
            'texto'          => 'Postagem de teste',
            'data_agendada'  => now()->addHour(),
            'status'         => 'agendado',
        ]);

        MetaPost::withoutGlobalScopes()->create([
            'tenant_id'      => $outroTenant->id,
            'canal_alvo'     => 'facebook',
            'texto'          => 'Postagem de outro tenant',
            'data_agendada'  => now()->addHour(),
            'status'         => 'agendado',
        ]);

        $this->assertSame(1, MetaPost::count());
    }

    public function test_scope_prontos_para_publicar_so_pega_agendados_no_passado(): void
    {
        $tenant = Tenant::factory()->create();
        $pagina = $this->criarPagina($tenant);
        session(['tenant_id' => $tenant->id]);

        $vencido = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Vencido', 'data_agendada' => now()->subMinute(), 'status' => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Futuro', 'data_agendada' => now()->addDay(), 'status' => 'agendado',
        ]);
        MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Ja publicado', 'data_agendada' => now()->subDay(), 'status' => 'publicado',
        ]);

        $prontos = MetaPost::prontosParaPublicar()->get();

        $this->assertCount(1, $prontos);
        $this->assertSame($vencido->id, $prontos->first()->id);
    }

    public function test_pode_cancelar_apenas_quando_agendado(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $agendado = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now()->addHour(), 'status' => 'agendado',
        ]);
        $publicado = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'x', 'data_agendada' => now()->subHour(), 'status' => 'publicado',
        ]);

        $this->assertTrue($agendado->podeCancelar());
        $this->assertFalse($publicado->podeCancelar());
    }

    /**
     * Passo 2 do Banco de Conteúdos (2026-09-17): meta_post_conteudo_id é
     * nullable — todo MetaPost criado sem essa referência (o fluxo de hoje,
     * 100% dos posts existentes) precisa continuar funcionando exatamente
     * igual.
     */
    public function test_post_sem_referencia_de_conteudo_continua_funcionando_normal(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'texto' => 'Post avulso, sem vir de um conteúdo salvo',
            'data_agendada' => now()->addHour(), 'status' => 'agendado',
        ]);

        $this->assertNull($post->fresh()->meta_post_conteudo_id);
        $this->assertNull($post->fresh()->conteudoOrigem);
    }

    public function test_post_criado_a_partir_de_um_conteudo_salvo_guarda_a_referencia(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $conteudo = MetaPostConteudo::create([
            'tenant_id' => $tenant->id, 'titulo' => 'Promo fim de semana', 'texto' => 'x',
        ]);

        $post = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'meta_post_conteudo_id' => $conteudo->id,
            'texto' => 'x', 'data_agendada' => now()->addHour(), 'status' => 'agendado',
        ]);

        $this->assertSame($conteudo->id, $post->fresh()->meta_post_conteudo_id);
        $this->assertSame($conteudo->id, $post->fresh()->conteudoOrigem->id);
    }

    /**
     * Guarda de regressão: a query que o comando meta:publicar-posts usa
     * (status agendado + data vencida) e o scope prontosParaPublicar não
     * podem se importar com a coluna nova — ela é só metadado de origem.
     */
    public function test_coluna_de_origem_do_conteudo_nao_afeta_prontos_para_publicar(): void
    {
        $tenant = Tenant::factory()->create();
        $pagina = $this->criarPagina($tenant);
        session(['tenant_id' => $tenant->id]);

        $conteudo = MetaPostConteudo::create([
            'tenant_id' => $tenant->id, 'titulo' => 'x', 'texto' => 'x',
        ]);

        $comOrigem = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'meta_post_conteudo_id' => $conteudo->id,
            'texto' => 'Com origem', 'data_agendada' => now()->subMinute(), 'status' => 'agendado',
        ]);
        $semOrigem = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook', 'meta_pagina_id' => $pagina->id,
            'texto' => 'Sem origem', 'data_agendada' => now()->subMinute(), 'status' => 'agendado',
        ]);

        $prontos = MetaPost::prontosParaPublicar()->pluck('id')->all();

        $this->assertContains($comOrigem->id, $prontos);
        $this->assertContains($semOrigem->id, $prontos);
    }
}
