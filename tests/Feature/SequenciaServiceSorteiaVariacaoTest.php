<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\SequenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenciaServiceSorteiaVariacaoTest extends TestCase
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

    public function test_dispara_com_conteudo_de_variacao_ativa_sorteada(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Original nunca deve sair daqui', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Variação única ativa', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        SequenciaMensagemVariacao::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Variação desativada, nunca deve sair daqui', 'origem' => 'ia', 'protegida' => false, 'ativa' => false,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Variação única ativa');
    }

    public function test_cai_para_conteudo_da_mensagem_quando_nao_ha_variacao_ativa(): void
    {
        Queue::fake();
        $ticket    = $this->criarTicket();
        $sequencia = Sequencia::create(['tenant_id' => $ticket->tenant_id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        SequenciaMensagem::create([
            'tenant_id' => $ticket->tenant_id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Sem variação cadastrada ainda', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);

        Queue::assertPushed(SequenciaMensagemJob::class, fn ($job) => $job->conteudo === 'Sem variação cadastrada ainda');
    }
}
