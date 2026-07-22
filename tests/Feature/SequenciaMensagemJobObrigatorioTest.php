<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaMensagemJobObrigatorioTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $coluna): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
    }

    public function test_mensagem_normal_e_cancelada_quando_lead_saiu_da_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Sequência foi disparada para lead_novo, mas o ticket já avançou pra em_atendimento
        $ticket = $this->criarTicket('em_atendimento');

        (new SequenciaMensagemJob($ticket->id, 'Mensagem da sequência', null, 'lead_novo', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_mensagem_obrigatoria_e_enviada_mesmo_com_lead_fora_da_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket('em_atendimento');

        (new SequenciaMensagemJob($ticket->id, 'Mensagem obrigatória', null, 'lead_novo', null, true))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Mensagem obrigatória']);
    }

    public function test_nao_envia_mensagem_se_ticket_ja_esta_em_coluna_de_papel_encerramento(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $contato = Contato::factory()->create(['telefone' => '5511999999998']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Mensagem de teste que não deveria ser enviada'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }
}
