<?php

namespace Tests\Feature;

use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaPost;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vistoria de 01/10 (Leonardo): a tela só podia ter 1 conta de Instagram "de
 * verdade" na prática porque o fallback de publicação pegava a primeira conta
 * que o banco devolvesse, sem ordem definida. Estes testes cobrem a marcação
 * de conta "principal" e os 2 bugs corrigidos (fallback não-determinístico e
 * troca de login silenciosa — este segundo em IntegracoesControllerMetaCallbackTest).
 */
class MetaContaInstagramPrincipalTest extends TestCase
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
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => '1',
            'nome'              => 'Frete Rio',
            'page_access_token' => 'p',
            'ativo'             => true,
        ]);
    }

    private function criarContaInstagram(Tenant $tenant, MetaPagina $pagina, string $username, array $extra = []): MetaContaInstagram
    {
        return MetaContaInstagram::create(array_merge([
            'tenant_id'             => $tenant->id,
            'meta_pagina_id'        => $pagina->id,
            'instagram_business_id' => $username,
            'username'              => $username,
            'ativo'                 => true,
        ], $extra));
    }

    public function test_marcar_como_principal_desmarca_as_outras_contas_do_mesmo_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $pagina = $this->criarPagina($tenant);
        $contaA = $this->criarContaInstagram($tenant, $pagina, 'conta_a', ['principal' => true]);
        $contaB = $this->criarContaInstagram($tenant, $pagina, 'conta_b');

        $contaB->marcarComoPrincipal();

        $this->assertFalse($contaA->fresh()->principal);
        $this->assertTrue($contaB->fresh()->principal);
    }

    public function test_marcar_como_principal_nao_afeta_conta_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $pagina      = $this->criarPagina($tenant);
        $outraPagina = $this->criarPagina($outroTenant);

        $conta      = $this->criarContaInstagram($tenant, $pagina, 'conta_tenant');
        $contaOutro = $this->criarContaInstagram($outroTenant, $outraPagina, 'conta_outro', ['principal' => true]);

        $conta->marcarComoPrincipal();

        $this->assertTrue($conta->fresh()->principal);
        $this->assertTrue($contaOutro->fresh()->principal);
    }

    public function test_fallback_de_publicacao_prefere_a_conta_marcada_como_principal(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $this->criarContaInstagram($tenant, $pagina, 'conta_antiga');
        $contaPrincipal = $this->criarContaInstagram($tenant, $pagina, 'conta_principal', ['principal' => true]);

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'    => 'instagram',
            'texto'         => 'Post sem escolher conta',
            'imagem_url'    => 'https://cdn.exemplo.com/foto.jpg',
            'modo_gatilho'  => 'nenhum',
            'data_agendada' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'canal_alvo'              => 'instagram',
            'meta_conta_instagram_id' => $contaPrincipal->id,
        ]);
    }

    public function test_fallback_de_publicacao_e_deterministico_sem_nenhuma_conta_principal(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $contaMaisAntiga = $this->criarContaInstagram($tenant, $pagina, 'conta_1');
        $this->criarContaInstagram($tenant, $pagina, 'conta_2');

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'    => 'instagram',
            'texto'         => 'Post sem escolher conta, sem principal definida',
            'imagem_url'    => 'https://cdn.exemplo.com/foto.jpg',
            'modo_gatilho'  => 'nenhum',
            'data_agendada' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'canal_alvo'              => 'instagram',
            'meta_conta_instagram_id' => $contaMaisAntiga->id,
        ]);
    }

    public function test_fallback_de_publicacao_ignora_conta_principal_desativada(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);

        $this->criarContaInstagram($tenant, $pagina, 'conta_principal_inativa', ['principal' => true, 'ativo' => false]);
        $contaAtiva = $this->criarContaInstagram($tenant, $pagina, 'conta_ativa');

        $response = $this->actingAs($dono)->post(route('meta-posts.store'), [
            'canal_alvo'    => 'instagram',
            'texto'         => 'Post com principal desativada',
            'imagem_url'    => 'https://cdn.exemplo.com/foto.jpg',
            'modo_gatilho'  => 'nenhum',
            'data_agendada' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);

        $response->assertRedirect(route('meta-posts.index', ['semana' => now()->addDay()->toDateString()]));
        $this->assertDatabaseHas('meta_posts', [
            'canal_alvo'              => 'instagram',
            'meta_conta_instagram_id' => $contaAtiva->id,
        ]);
    }

    public function test_rota_marcar_principal_marca_a_conta_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);
        $conta  = $this->criarContaInstagram($tenant, $pagina, 'conta_nova');

        $response = $this->actingAs($dono)->post(route('meta.contas-instagram.marcar-principal', $conta));

        $response->assertRedirect(route('integracoes'));
        $this->assertTrue($conta->fresh()->principal);
    }

    public function test_rota_marcar_principal_bloqueia_conta_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);
        $outraPagina = $this->criarPagina($outroTenant);
        $contaAlheia = $this->criarContaInstagram($outroTenant, $outraPagina, 'conta_alheia');

        $response = $this->actingAs($dono)->post(route('meta.contas-instagram.marcar-principal', $contaAlheia));

        $response->assertNotFound();
        $this->assertFalse($contaAlheia->fresh()->principal);
    }

    public function test_rota_alternar_ativa_desativa_e_remove_a_marcacao_de_principal(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);
        $conta  = $this->criarContaInstagram($tenant, $pagina, 'conta_x', ['principal' => true]);

        $response = $this->actingAs($dono)->post(route('meta.contas-instagram.alternar-ativa', $conta));

        $response->assertRedirect(route('integracoes'));
        $conta->refresh();
        $this->assertFalse($conta->ativo);
        $this->assertFalse($conta->principal);
    }

    public function test_rota_alternar_ativa_reativa_sem_marcar_principal_sozinha(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = $this->dono($tenant);
        $pagina = $this->criarPagina($tenant);
        $conta  = $this->criarContaInstagram($tenant, $pagina, 'conta_y', ['ativo' => false]);

        $response = $this->actingAs($dono)->post(route('meta.contas-instagram.alternar-ativa', $conta));

        $response->assertRedirect(route('integracoes'));
        $conta->refresh();
        $this->assertTrue($conta->ativo);
        $this->assertFalse($conta->principal);
    }

    public function test_rota_alternar_ativa_bloqueia_conta_de_outro_tenant(): void
    {
        $tenant      = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono        = $this->dono($tenant);
        $outraPagina = $this->criarPagina($outroTenant);
        $contaAlheia = $this->criarContaInstagram($outroTenant, $outraPagina, 'conta_alheia2');

        $response = $this->actingAs($dono)->post(route('meta.contas-instagram.alternar-ativa', $contaAlheia));

        $response->assertNotFound();
        $this->assertTrue($contaAlheia->fresh()->ativo);
    }
}
