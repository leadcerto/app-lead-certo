<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatoriosGestorVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_relatorios_do_gestor(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/relatorios')->assertOk();
        $this->actingAs($user)->getJson('/api/painel/kanban/relatorios')->assertOk();
    }

    /**
     * Achado da revisão final (09/10/2026, Issue 6): a aba "Auditoria" dentro
     * de Relatórios do Gestor aparecia pra todo mundo com acesso a Kanban, mas
     * a API que ela chama (/kanban/auditorias) continua restrita a admin/dono
     * — vendedor via a aba, clicava, e via um 403 sem nenhum dado.
     */
    public function test_vendedor_nao_ve_a_aba_de_auditoria_mas_dono_ve(): void
    {
        $tenant = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);
        $dono     = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($vendedor)->get('/kanban/relatorios')->assertDontSee('Tickets marcados pra revisão');
        $this->actingAs($dono)->get('/kanban/relatorios')->assertSee('Tickets marcados pra revisão');
    }
}
