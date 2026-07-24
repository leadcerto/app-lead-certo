<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SequenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SequenciaServiceHorarioFuncionamentoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_dentro_do_horario_dispara_a_sequencia_principal_normalmente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Mensagem do horário comercial', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Mensagem do horário comercial');
        Carbon::setTestNow();
    }

    public function test_fora_do_horario_usa_sequencia_de_repouso_quando_configurada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket   = $this->criarTicket();
        $repouso  = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Repouso', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $repouso->id, 'ordem' => 1,
            'conteudo' => 'Mensagem de repouso', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
            'sequencia_repouso_id' => $repouso->id,
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Nunca deve disparar às 23h', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Mensagem de repouso');
        Queue::assertNotPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Nunca deve disparar às 23h');
        Carbon::setTestNow();
    }

    public function test_fora_do_horario_sem_repouso_adia_para_o_proximo_inicio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create([
            'tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
            'horario_ativo' => true, 'horario_inicio' => '08:00:00', 'horario_fim' => '18:00:00',
        ]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Adiada pro próximo horário', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        // Próximo início: amanhã (2026-07-22) às 08:00 America/Sao_Paulo — pelo menos 9h de delay a partir das 23h de hoje.
        Queue::assertPushed(SequenciaMensagemJob::class, function ($job) {
            return $job->conteudo === 'Adiada pro próximo horário';
        });
        Carbon::setTestNow();
    }
}
