<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-17 (Leonardo, Frete Rio): o submenu "Postagens Meta" >
 * "Banco de Imagens"/"Banco de Textos" linkava direto pras rotas
 * admin.gmb-posts.imagens/templates — mesma URL usada pelo menu do GMB. O
 * destaque do menu ativo em layouts/app.blade.php decide pelo NOME da rota
 * (routeIs('admin.gmb-posts.*') checado ANTES de routeIs('meta-posts.*')),
 * então entrar nessas páginas por "Postagens Meta" acendia o menu do GMB
 * (errado) em vez de manter "Postagens Meta" destacado.
 */
class MenuPostagensMetaNaoAcendeGmbTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsDono(): User
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_banco_de_imagens_via_postagens_meta_mantem_menu_meta_aceso(): void
    {
        $this->actingAsDono();

        $response = $this->get(route('meta-posts.imagens'));

        $response->assertOk();
        $response->assertSee("menuAberto: 'meta'", false);
        $response->assertDontSee("menuAberto: 'gmb'", false);
    }

    public function test_banco_de_textos_via_postagens_meta_mantem_menu_meta_aceso(): void
    {
        $this->actingAsDono();

        $response = $this->get(route('meta-posts.templates'));

        $response->assertOk();
        $response->assertSee("menuAberto: 'meta'", false);
        $response->assertDontSee("menuAberto: 'gmb'", false);
    }

    public function test_banco_de_imagens_via_gmb_continua_acendendo_menu_gmb(): void
    {
        // Regressão: o mesmo link dentro do menu do GMB precisa continuar
        // acendendo a seção do GMB normalmente (comportamento inalterado).
        $this->actingAsDono();

        $response = $this->get(route('admin.gmb-posts.imagens'));

        $response->assertOk();
        $response->assertSee("menuAberto: 'gmb'", false);
    }
}
