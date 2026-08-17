<?php

namespace Tests\Feature;

use App\Models\AgendamentoAvaliacao;
use App\Models\CategoriaTemplate;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvaliadorDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioAvaliador(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id, 'perfil' => 'avaliador',
            'city' => 'Rio de Janeiro', 'state' => 'RJ',
        ]);
    }

    private function criarAgendamento(Tenant $tenant, User $avaliador, array $atributos = []): AgendamentoAvaliacao
    {
        $perfil = PerfilGmb::create([
            'tenant_id' => $tenant->id, 'nome' => 'Frete Rio', 'city' => 'Rio de Janeiro',
            'state' => 'RJ', 'link_gmb' => 'https://maps.google.com/?cid=1', 'ativo' => true,
        ]);
        $categoria = CategoriaTemplate::create(['tenant_id' => $tenant->id, 'nome' => 'Elogio ' . uniqid()]);
        $template  = TemplateAvaliacao::create([
            'tenant_id' => $tenant->id, 'codigo' => 'T-' . uniqid(), 'texto' => 'Excelente atendimento!',
            'categoria_id' => $categoria->id, 'ativo' => true,
        ]);

        return AgendamentoAvaliacao::create(array_merge([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $avaliador->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ], $atributos));
    }

    public function test_avaliador_ve_apenas_suas_proprias_tarefas(): void
    {
        $tenant     = Tenant::factory()->create();
        $avaliador  = $this->usuarioAvaliador($tenant);
        $outroAvaliador = $this->usuarioAvaliador($tenant);

        $this->criarAgendamento($tenant, $avaliador);
        $this->criarAgendamento($tenant, $outroAvaliador);

        $response = $this->actingAs($avaliador)->get('/avaliador/dashboard');

        $response->assertOk();
        $response->assertViewHas('totalSemana', 1);
    }

    public function test_avaliador_conclui_sua_propria_tarefa(): void
    {
        $tenant      = Tenant::factory()->create();
        $avaliador   = $this->usuarioAvaliador($tenant);
        $agendamento = $this->criarAgendamento($tenant, $avaliador);

        $response = $this->actingAs($avaliador)->post("/avaliador/agendamentos/{$agendamento->id}/concluir");

        $response->assertRedirect();
        $this->assertDatabaseHas('agendamentos_avaliacao', ['id' => $agendamento->id, 'status' => 'concluido']);
    }

    public function test_avaliador_nao_conclui_tarefa_de_outro_avaliador(): void
    {
        $tenant          = Tenant::factory()->create();
        $avaliador       = $this->usuarioAvaliador($tenant);
        $outroAvaliador  = $this->usuarioAvaliador($tenant);
        $agendamentoAlheio = $this->criarAgendamento($tenant, $outroAvaliador);

        $response = $this->actingAs($avaliador)->post("/avaliador/agendamentos/{$agendamentoAlheio->id}/concluir");

        $response->assertForbidden();
        $this->assertDatabaseHas('agendamentos_avaliacao', ['id' => $agendamentoAlheio->id, 'status' => 'pendente']);
    }

    public function test_nao_conclui_tarefa_ja_concluida_de_novo(): void
    {
        $tenant      = Tenant::factory()->create();
        $avaliador   = $this->usuarioAvaliador($tenant);
        $agendamento = $this->criarAgendamento($tenant, $avaliador, [
            'status' => 'concluido', 'concluido_em' => now(),
        ]);

        $response = $this->actingAs($avaliador)->post("/avaliador/agendamentos/{$agendamento->id}/concluir");

        $response->assertRedirect();
        $response->assertSessionHas('aviso');
    }

    public function test_marcador_de_empresa_no_texto_e_resolvido_pro_nome_real_do_tenant(): void
    {
        $tenant    = Tenant::factory()->create(['nome' => 'Frete Rio']);
        $avaliador = $this->usuarioAvaliador($tenant);
        $this->criarAgendamento($tenant, $avaliador, []);

        // Sobrescreve o texto padrão do helper pra conter o marcador.
        $template = TemplateAvaliacao::where('tenant_id', $tenant->id)->first();
        $template->update(['texto' => 'A [nome da empresa] foi impecável do início ao fim.']);

        $response = $this->actingAs($avaliador)->get('/avaliador/dashboard');

        $response->assertOk();
        $response->assertSee('A Frete Rio foi impecável do início ao fim.');
        $response->assertDontSee('[nome da empresa]');
    }

    public function test_vendedor_nao_acessa_dashboard_de_avaliador(): void
    {
        $tenant   = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $response = $this->actingAs($vendedor)->get('/avaliador/dashboard');

        $response->assertForbidden();
    }
}
