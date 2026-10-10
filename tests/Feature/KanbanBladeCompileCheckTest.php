<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_kanban_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
    }

    public function test_barra_lateral_mostra_um_item_por_kanban_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Imersão', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee('Atendimentos');
        $response->assertSee('Imersão');
        $response->assertDontSee('kanban-geral', false);
    }

    public function test_vendedor_ve_a_lista_de_kanbans_mas_nao_ve_criar_novo_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee('Atendimentos');
        $response->assertDontSee('Criar novo Kanban');
    }

    public function test_admin_ve_o_botao_de_criar_novo_kanban_mas_dono_nao_ve(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin', 'ativo' => true]);
        $dono   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($admin)->get('/kanban')->assertSee('Criar novo Kanban');
        $this->actingAs($dono)->get('/kanban')->assertDontSee('Criar novo Kanban');
    }

    /**
     * Achado na verificação manual (09/10/2026): o cálculo de $menuAtivoPadrao
     * (que define qual menu já abre expandido ao carregar a página) ainda
     * usava as chaves antigas ('kanban-{id}'/'kanban-geral') de ontem — com o
     * menu único 'kanban' de hoje, isso deixava o bloco "Kanban" sempre
     * fechado por padrão, mesmo estando na própria página do Kanban.
     */
    public function test_menu_kanban_abre_expandido_por_padrao_ao_visitar_pagina_do_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban');

        $response->assertOk();
        $response->assertSee("menuAberto: 'kanban'", false);
    }

    public function test_menu_kanban_abre_expandido_por_padrao_ao_visitar_variaveis(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban/variaveis');

        $response->assertOk();
        $response->assertSee("menuAberto: 'kanban'", false);
    }

    /**
     * Achado da revisão final (09/10/2026, Issue 5): admin tem tenant_id=null
     * na própria conta — $kanbansDoTenant calculado só a partir do tenant_id
     * do usuário escondia o submenu do Kanban por completo justamente pra
     * quem mais precisa editá-lo. EnsureTenant já resolve o tenant ATIVO via
     * ?tenant_id=/session — a barra lateral precisa reaproveitar isso.
     */
    public function test_admin_sem_tenant_id_proprio_ve_kanbans_do_tenant_ativo_na_sessao(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = User::factory()->create(['tenant_id' => null, 'perfil' => 'admin', 'ativo' => true]);
        Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'vendas', 'nome' => 'Vendas',
            'nome_curto' => 'Vendas', 'ordem' => 1,
        ]);

        $response = $this->actingAs($admin)->get('/kanban?tenant_id=' . $tenant->id);

        $response->assertOk();
        $response->assertSee('Vendas');
    }

    /**
     * Achado da revisão final (09/10/2026, Issue 4): os 5 sub-links que não
     * carregavam kanban_id faziam a barra lateral "perder" o Kanban ativo e
     * saltar silenciosamente de volta pro Kanban 'vendas' ao navegar.
     */
    public function test_sublinks_do_kanban_carregam_kanban_id_na_url(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanban = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil de Qualificação',
            'nome_curto' => 'Imersão', 'ordem' => 1,
        ]);

        $response = $this->actingAs($user)->get('/kanban?kanban_id=' . $kanban->id);

        $response->assertOk();
        $response->assertSee('kanban/variaveis?kanban_id=' . $kanban->id, false);
        $response->assertSee('kanban/motivos-desfecho?kanban_id=' . $kanban->id, false);
        $response->assertSee('kanban/relatorios?kanban_id=' . $kanban->id, false);
        $response->assertSee('kanban/documentacao/botoes?kanban_id=' . $kanban->id, false);
        $response->assertSee('admin/especificacoes?kanban_id=' . $kanban->id, false);
    }
}
