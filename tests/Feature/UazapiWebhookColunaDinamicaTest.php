<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookColunaDinamicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_novo_criado_pelo_webhook_usa_a_coluna_de_entrada_do_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'token-teste',
            'uazapi_instance_token' => 'instance-token-1',
        ]);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        // Franqueado renomeou a coluna de Entrada de 'lead_novo' para 'novo_contato'
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $response = $this->postJson('/api/webhook/uazapi/token-teste', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511999999999@s.whatsapp.net',
                'text'    => 'Olá, quero um orçamento',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('novo_contato', $ticket->coluna_kanban);
    }

    public function test_lead_responde_pela_primeira_vez_avanca_para_a_proxima_coluna_por_ordem(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'token-teste-2',
            'uazapi_instance_token' => 'instance-token-2',
        ]);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $contato = \App\Models\Contato::factory()->create(['telefone' => '5511988884444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo_contato', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);
        \App\Models\Mensagem::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi! Me conta o que precisa.', 'enviado_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/token-teste-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511988884444@s.whatsapp.net',
                'text'    => 'Preciso de um orçamento de mudança',
            ],
        ]);

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_chamada_whatsapp_perdida_cria_ticket_na_coluna_de_entrada_do_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'token-teste-3',
            'uazapi_instance_token' => 'instance-token-3',
        ]);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        // Franqueado renomeou a coluna de Entrada de 'lead_novo' para 'novo_contato'
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);

        $response = $this->postJson('/api/webhook/uazapi/token-teste-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'      => false,
                'isGroup'     => false,
                'chatid'      => '5511977776666@s.whatsapp.net',
                'messageType' => 'call_log',
                'senderName'  => 'Fulano',
            ],
        ]);

        $response->assertOk();
        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('novo_contato', $ticket->coluna_kanban);
        $this->assertSame('ligacao', $ticket->origem);
    }
}
