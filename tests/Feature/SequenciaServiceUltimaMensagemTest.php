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

class SequenciaServiceUltimaMensagemTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_a_ultima_mensagem_da_sequencia_dispara_com_enviarbotoes(): void
    {
        Queue::fake();

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Follow-up', 'coluna_kanban' => 'aguardando_lead', 'ativo' => true,
        ]);
        SequenciaMensagem::create(['tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1, 'conteudo' => 'Msg 1', 'delay_segundos' => 60, 'ativo' => true]);
        SequenciaMensagem::create(['tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 2, 'conteudo' => 'Msg 2', 'delay_segundos' => 60, 'ativo' => true]);
        SequenciaMensagem::create(['tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 3, 'conteudo' => 'Msg 3', 'delay_segundos' => 60, 'ativo' => true]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, 3);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Msg 1' && $job->enviarBotoes === false);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Msg 2' && $job->enviarBotoes === false);
        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Msg 3' && $job->enviarBotoes === true);
    }
}
