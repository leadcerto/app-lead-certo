<?php

namespace Tests\Feature;

use App\Mail\AlertaAvaliadorMail;
use App\Models\AgendamentoAvaliacao;
use App\Models\CategoriaTemplate;
use App\Models\PerfilGmb;
use App\Models\TemplateAvaliacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AgendamentoAvaliacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function usuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
    }

    private function usuarioAvaliador(Tenant $tenant, array $atributos = []): User
    {
        return User::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'perfil' => 'avaliador',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
        ], $atributos));
    }

    private function criarPerfil(Tenant $tenant, array $atributos = []): PerfilGmb
    {
        return PerfilGmb::create(array_merge([
            'tenant_id' => $tenant->id,
            'nome' => 'Frete Rio — Copacabana',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
            'link_gmb' => 'https://maps.google.com/?cid=123',
            'ativo' => true,
        ], $atributos));
    }

    private function criarTemplate(Tenant $tenant, array $atributos = []): TemplateAvaliacao
    {
        $categoria = $atributos['categoria_id']
            ?? CategoriaTemplate::create(['tenant_id' => $tenant->id, 'nome' => 'Elogio geral'])->id;

        return TemplateAvaliacao::create(array_merge([
            'tenant_id' => $tenant->id,
            'codigo' => 'T-'.uniqid(),
            'texto' => 'Atendimento excelente!',
            'categoria_id' => $categoria,
            'ativo' => true,
        ], $atributos));
    }

    public function test_agenda_individual_com_atribuicao_automatica(): void
    {
        $tenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $this->criarTemplate($tenant);
        $avaliador = $this->usuarioAvaliador($tenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos', [
            'perfil_id' => $perfil->id,
            'data_agendada' => now()->addDay()->toDateString(),
        ]);

        $response->assertRedirect(route('admin.agendamentos-avaliacao.index'));
        $this->assertDatabaseHas('agendamentos_avaliacao', [
            'tenant_id' => $tenant->id,
            'perfil_id' => $perfil->id,
            'avaliador_id' => $avaliador->id,
        ]);
    }

    public function test_nao_agenda_em_perfil_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos', [
            'perfil_id' => $perfilAlheio->id,
            'data_agendada' => now()->addDay()->toDateString(),
        ]);

        $response->assertSessionHasErrors('perfil_id');
        $this->assertDatabaseMissing('agendamentos_avaliacao', ['perfil_id' => $perfilAlheio->id]);
    }

    public function test_nao_agenda_com_avaliador_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $this->criarTemplate($tenant);
        $avaliadorAlheio = $this->usuarioAvaliador($outroTenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos', [
            'perfil_id' => $perfil->id,
            'data_agendada' => now()->addDay()->toDateString(),
            'avaliador_id' => $avaliadorAlheio->id,
        ]);

        $response->assertSessionHasErrors('avaliador_id');
    }

    public function test_nao_agenda_com_usuario_que_nao_e_avaliador(): void
    {
        $tenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $this->criarTemplate($tenant);
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor']);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos', [
            'perfil_id' => $perfil->id,
            'data_agendada' => now()->addDay()->toDateString(),
            'avaliador_id' => $vendedor->id,
        ]);

        $response->assertSessionHasErrors('avaliador_id');
    }

    public function test_lote_ignora_perfil_de_outro_tenant_na_matriz(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos/lote', [
            'matriz' => [
                $perfilAlheio->id => ['segunda' => 1],
            ],
            'semana_referencia' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('lote');
        $this->assertDatabaseMissing('agendamentos_avaliacao', ['perfil_id' => $perfilAlheio->id]);
    }

    public function test_altera_status_do_proprio_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $template = $this->criarTemplate($tenant);
        $avaliador = $this->usuarioAvaliador($tenant);
        $agendamento = AgendamentoAvaliacao::create([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $avaliador->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ]);

        $response = $this->actingAs($dono)->patch("/admin/gmb/agendamentos/{$agendamento->id}/status", [
            'status' => 'concluido',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('agendamentos_avaliacao', ['id' => $agendamento->id, 'status' => 'concluido']);
    }

    public function test_nao_altera_status_de_agendamento_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);
        $templateAlheio = $this->criarTemplate($outroTenant);
        $avaliadorAlheio = $this->usuarioAvaliador($outroTenant);
        $agendamentoAlheio = AgendamentoAvaliacao::create([
            'tenant_id' => $outroTenant->id, 'perfil_id' => $perfilAlheio->id, 'template_id' => $templateAlheio->id,
            'avaliador_id' => $avaliadorAlheio->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ]);

        $response = $this->actingAs($dono)->patch("/admin/gmb/agendamentos/{$agendamentoAlheio->id}/status", [
            'status' => 'concluido',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('agendamentos_avaliacao', ['id' => $agendamentoAlheio->id, 'status' => 'pendente']);
    }

    public function test_troca_avaliador_exige_avaliador_do_mesmo_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $template = $this->criarTemplate($tenant);
        $avaliador = $this->usuarioAvaliador($tenant);
        $avaliadorAlheio = $this->usuarioAvaliador($outroTenant);
        $agendamento = AgendamentoAvaliacao::create([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $avaliador->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ]);

        $response = $this->actingAs($dono)->patch("/admin/gmb/agendamentos/{$agendamento->id}/avaliador", [
            'avaliador_id' => $avaliadorAlheio->id,
        ]);

        $response->assertSessionHasErrors('avaliador_id');
        $this->assertDatabaseHas('agendamentos_avaliacao', ['id' => $agendamento->id, 'avaliador_id' => $avaliador->id]);
    }

    public function test_nao_refaz_template_de_agendamento_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $outroTenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfilAlheio = $this->criarPerfil($outroTenant);
        $templateAlheio = $this->criarTemplate($outroTenant);
        $avaliadorAlheio = $this->usuarioAvaliador($outroTenant);
        $agendamentoAlheio = AgendamentoAvaliacao::create([
            'tenant_id' => $outroTenant->id, 'perfil_id' => $perfilAlheio->id, 'template_id' => $templateAlheio->id,
            'avaliador_id' => $avaliadorAlheio->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ]);

        $response = $this->actingAs($dono)->patch("/admin/gmb/agendamentos/{$agendamentoAlheio->id}/template");

        $response->assertForbidden();
    }

    public function test_alertar_avaliadores_envia_email_para_pendentes(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $dono = $this->usuarioDono($tenant);
        $perfil = $this->criarPerfil($tenant);
        $template = $this->criarTemplate($tenant);
        $avaliador = $this->usuarioAvaliador($tenant);
        AgendamentoAvaliacao::create([
            'tenant_id' => $tenant->id, 'perfil_id' => $perfil->id, 'template_id' => $template->id,
            'avaliador_id' => $avaliador->id, 'data_agendada' => now()->toDateString(), 'status' => 'pendente',
        ]);

        $response = $this->actingAs($dono)->post('/admin/gmb/agendamentos/alertar');

        $response->assertRedirect();
        Mail::assertSent(AlertaAvaliadorMail::class, fn ($mail) => $mail->hasTo($avaliador->email));
    }
}
