<?php
// tests/Feature/KanbanInfoControllerTest.php
namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanInfoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_show_retorna_vazio_quando_nao_configurado(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info');

        $response->assertOk();
        $response->assertJson(['conhecimento_geral' => '']);
    }

    public function test_update_persiste_conhecimento_geral(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'conhecimento_geral' => 'Atendemos só Zona Sul do Rio de Janeiro.',
        ]);

        $response->assertOk();

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertSame('Atendemos só Zona Sul do Rio de Janeiro.', $kanban->conhecimento_geral);
    }

    // ─── Nome do Kanban (achado real 2026-08-20 — Leonardo queria renomear "Vendas" pra "Suporte") ───

    public function test_show_retorna_o_nome_atual_do_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->update(['nome' => 'Vendas']);

        $response = $this->actingAs($user)->getJson('/api/painel/kanban/info');

        $response->assertOk();
        $response->assertJson(['nome' => 'Vendas']);
    }

    public function test_update_renomeia_o_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'nome' => 'Suporte',
        ]);

        $response->assertOk();

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertSame('Suporte', $kanban->nome);
    }

    public function test_update_so_com_conhecimento_geral_nao_apaga_o_nome(): void
    {
        // Cada campo salva independente (mesmo padrão já usado no resto da tela
        // de config) — mandar só um dos dois nunca pode zerar o outro.
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->update(['nome' => 'Suporte']);

        $this->actingAs($user)->putJson('/api/painel/kanban/info', [
            'conhecimento_geral' => 'Texto novo.',
        ])->assertOk();

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->first();
        $this->assertSame('Suporte', $kanban->nome);
    }

    public function test_nao_aceita_nome_vazio(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/info', ['nome' => '']);

        $response->assertStatus(422);
    }
}
