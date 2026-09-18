<?php

namespace Tests\Feature;

use App\Models\MetaPost;
use App\Models\MetaPostConteudo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaPostConteudoControllerTest extends TestCase
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

    public function test_index_lista_apenas_conteudos_do_proprio_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);

        MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'Meu conteúdo', 'texto' => 'x']);
        MetaPostConteudo::withoutGlobalScopes()->create(['tenant_id' => $outroTenant->id, 'titulo' => 'De outra empresa', 'texto' => 'x']);

        $response = $this->actingAs($dono)->get(route('meta-posts.conteudos.index'));

        $response->assertOk();
        $response->assertSee('Meu conteúdo');
        $response->assertDontSee('De outra empresa');
    }

    public function test_store_cria_conteudo_com_campos_completos(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);

        $response = $this->actingAs($dono)->post(route('meta-posts.conteudos.store'), [
            'titulo'                      => 'Promo fim de semana',
            'categoria'                   => 'promocoes',
            'texto'                       => 'Vem economizar no frete!',
            'cta_tipo'                    => 'LEARN_MORE',
            'cta_url'                     => 'https://wa.me/5521999999999',
            'modo_gatilho'                => 'palavra_chave',
            'palavras_chave_texto'        => 'QUERO, FRETE',
            'resposta_publica_comentario' => 'Já te mandei no direct!',
            'mensagem_direct'             => 'Segue o link do WhatsApp...',
        ]);

        $response->assertRedirect();
        $conteudo = MetaPostConteudo::where('tenant_id', $tenant->id)->first();
        $this->assertSame('Promo fim de semana', $conteudo->titulo);
        $this->assertSame('promocoes', $conteudo->categoria);
        $this->assertSame(['QUERO', 'FRETE'], $conteudo->palavras_chave);
        $this->assertTrue($conteudo->ativo);
    }

    public function test_store_exige_titulo_e_texto(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);

        $response = $this->actingAs($dono)->post(route('meta-posts.conteudos.store'), [
            'modo_gatilho' => 'nenhum',
        ]);

        $response->assertSessionHasErrors(['titulo', 'texto']);
    }

    public function test_update_altera_conteudo_existente(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->dono($tenant);
        $conteudo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'Original', 'texto' => 'x']);

        $response = $this->actingAs($dono)->put(route('meta-posts.conteudos.update', $conteudo), [
            'titulo' => 'Atualizado', 'categoria' => 'geral', 'texto' => 'Novo texto', 'modo_gatilho' => 'nenhum',
        ]);

        $response->assertRedirect();
        $this->assertSame('Atualizado', $conteudo->fresh()->titulo);
        $this->assertSame('Novo texto', $conteudo->fresh()->texto);
    }

    public function test_nao_permite_editar_conteudo_de_outro_tenant(): void
    {
        $tenant       = Tenant::factory()->create();
        $outroTenant  = Tenant::factory()->create();
        $dono         = $this->dono($tenant);
        $conteudoAlheio = MetaPostConteudo::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'titulo' => 'Não é meu', 'texto' => 'x',
        ]);

        $response = $this->actingAs($dono)->put(route('meta-posts.conteudos.update', $conteudoAlheio), [
            'titulo' => 'Hackeado', 'categoria' => 'geral', 'texto' => 'x', 'modo_gatilho' => 'nenhum',
        ]);

        $response->assertNotFound();
        $this->assertSame('Não é meu', $conteudoAlheio->fresh()->titulo);
    }

    public function test_alternar_status_troca_ativo_para_inativo_e_vice_versa(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->dono($tenant);
        $conteudo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'x', 'texto' => 'x', 'ativo' => true]);

        $this->actingAs($dono)->patch(route('meta-posts.conteudos.alternar-status', $conteudo));
        $this->assertFalse($conteudo->fresh()->ativo);

        $this->actingAs($dono)->patch(route('meta-posts.conteudos.alternar-status', $conteudo));
        $this->assertTrue($conteudo->fresh()->ativo);
    }

    public function test_destroy_apaga_conteudo_sem_quebrar_post_que_ja_referenciava(): void
    {
        $tenant   = Tenant::factory()->create();
        $dono     = $this->dono($tenant);
        $conteudo = MetaPostConteudo::create(['tenant_id' => $tenant->id, 'titulo' => 'x', 'texto' => 'x']);
        $post     = MetaPost::create([
            'tenant_id' => $tenant->id, 'canal_alvo' => 'facebook',
            'meta_post_conteudo_id' => $conteudo->id,
            'texto' => 'x', 'data_agendada' => now()->addHour(), 'status' => 'agendado',
        ]);

        $response = $this->actingAs($dono)->delete(route('meta-posts.conteudos.destroy', $conteudo));

        $response->assertRedirect();
        $this->assertSame(0, MetaPostConteudo::count());
        $this->assertNull($post->fresh()->meta_post_conteudo_id);
        $this->assertSame('agendado', $post->fresh()->status);
    }
}
