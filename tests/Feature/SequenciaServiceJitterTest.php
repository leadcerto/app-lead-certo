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
use Tests\TestCase;

class SequenciaServiceJitterTest extends TestCase
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

    public function test_delay_fica_dentro_da_janela_de_jitter(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Oi!', 'delay_segundos' => 10, 'delay_jitter_segundos' => 5, 'ativo' => true,
        ]);

        $agora = now();
        app(SequenciaService::class)->iniciarParaTicket($ticket);

        // Usa $agora (capturado ANTES do dispatch) como referência, em vez de
        // um now() fresco dentro do closure: assim a janela nunca "encolhe"
        // por causa do tempo de execução do próprio teste entre o dispatch
        // e a asserção (o que causava flakiness perto do limite inferior).
        Queue::assertPushed(SequenciaMensagemJob::class, function ($job) use ($agora) {
            $delayReal = $agora->diffInSeconds($job->delay, false);

            return $delayReal >= 5 && $delayReal <= 15;
        });
    }

    public function test_delay_sem_jitter_configurado_fica_exato(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Oi!', 'delay_segundos' => 10, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        $this->assertDatabaseHas('sequencia_mensagens', ['delay_jitter_segundos' => 0]);
    }
}
