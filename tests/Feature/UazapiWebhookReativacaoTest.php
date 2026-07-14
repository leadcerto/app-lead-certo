<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UazapiWebhookReativacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_encerrado_reabre_na_coluna_de_antes_de_encerrar(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-reativa-1',
            'uazapi_instance_token' => 'instance-reativa-1',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511911112222']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta', 'encerrado_em' => now()]));

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_antes_encerrar);

        $response = $this->postJson('/api/webhook/uazapi/wh-reativa-1', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Oi, ainda quero fazer a mudança',
            ],
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('aguardando_orcamento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
        $this->assertNull($ticket->coluna_antes_encerrar);
    }

    public function test_ticket_encerrado_sem_coluna_salva_reabre_em_atendimento(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-reativa-2',
            'uazapi_instance_token' => 'instance-reativa-2',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511933334444']);
        // Ticket encerrado antes de coluna_antes_encerrar existir (dado legado): nulo.
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'agente_responsavel' => 'bot',
            'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $this->postJson('/api/webhook/uazapi/wh-reativa-2', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511933334444@s.whatsapp.net',
                'text'    => 'Oi, tudo bem?',
            ],
        ]);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->first();
        $this->assertSame('em_atendimento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
    }

    private function fakeOpenRouterClassificacao(string $resposta): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $resposta]]],
                'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 2],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_mensagem_de_despedida_mantem_ticket_encerrado(): void
    {
        $this->fakeOpenRouterClassificacao('MANTER');

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-reativa-3',
            'uazapi_instance_token' => 'instance-reativa-3',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'vendido', 'encerrado_em' => now()]));

        $response = $this->postJson('/api/webhook/uazapi/wh-reativa-3', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511955556666@s.whatsapp.net',
                'text'    => 'Boa noite, já consegui, obrigado!',
            ],
        ]);

        $response->assertOk();
        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertSame('encerrado', $ticket->status);
    }

    public function test_mensagem_util_reabre_ticket_encerrado(): void
    {
        $this->fakeOpenRouterClassificacao('REABRIR');

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token'  => 'wh-reativa-4',
            'uazapi_instance_token' => 'instance-reativa-4',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511966667777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticket->update($ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta', 'encerrado_em' => now()]));

        $this->postJson('/api/webhook/uazapi/wh-reativa-4', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => false,
                'isGroup' => false,
                'chatid'  => '5511966667777@s.whatsapp.net',
                'text'    => '1 geladeira, 1 sofá e 1 sapateira',
            ],
        ]);

        $ticket->refresh();
        $this->assertSame('em_atendimento', $ticket->coluna_kanban);
        $this->assertSame('aberto', $ticket->status);
    }

    public function test_dados_para_encerrar_nao_sobrescreve_coluna_ja_salva(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'encerrado', 'coluna_antes_encerrar' => 'pagamento',
            'agente_responsavel' => 'bot', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);

        $updates = $ticket->dadosParaEncerrar(['tag_desfecho' => 'sem_resposta_automatico']);

        $this->assertArrayNotHasKey('coluna_antes_encerrar', $updates);
    }
}
