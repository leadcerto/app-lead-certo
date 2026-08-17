<?php

namespace Tests\Feature;

use App\Mail\AlertaAtrasoAdminMail;
use App\Mail\AlertaAtrasoMail;
use App\Models\AgendamentoAvaliacao;
use App\Models\CategoriaTemplate;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\TemplateAvaliacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertarAtrasoCommandTest extends TestCase
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
            'avaliador_id' => $avaliador->id, 'data_agendada' => now()->subDays(3)->toDateString(),
            'status' => 'pendente',
        ], $atributos));
    }

    public function test_alerta_individual_e_relatorio_admin_para_agendamentos_atrasados(): void
    {
        Mail::fake();
        config(['mail.review_report_to' => 'admin@leadcerto.app.br']);

        $tenant    = Tenant::factory()->create();
        $avaliador = $this->usuarioAvaliador($tenant);
        $atrasado  = $this->criarAgendamento($tenant, $avaliador);

        $this->artisan('avaliadores:checar-atraso')->assertSuccessful();

        Mail::assertSent(AlertaAtrasoMail::class, fn ($mail) => $mail->hasTo($avaliador->email)
            && $mail->agendamentosAtrasados->contains('id', $atrasado->id));
        Mail::assertSent(AlertaAtrasoAdminMail::class, fn ($mail) => $mail->hasTo('admin@leadcerto.app.br')
            && $mail->hasCc($avaliador->email));
    }

    public function test_nao_alerta_agendamento_concluido_no_prazo(): void
    {
        Mail::fake();

        $tenant    = Tenant::factory()->create();
        $avaliador = $this->usuarioAvaliador($tenant);
        $this->criarAgendamento($tenant, $avaliador, [
            'status' => 'concluido', 'concluido_em' => now(),
            'data_agendada' => now()->toDateString(),
        ]);

        $this->artisan('avaliadores:checar-atraso')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_nao_envia_nada_quando_nao_ha_atraso(): void
    {
        Mail::fake();

        $this->artisan('avaliadores:checar-atraso')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
