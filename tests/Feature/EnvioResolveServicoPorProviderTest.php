<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvioResolveServicoPorProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_controller_envia_por_covercut_quando_canal_e_oficial(): void
    {
        // CovercutChannelService chama POST {base_url}/messages/send (não /messages).
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.1'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(5),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Olá, tudo bem?',
        ]);

        $response->assertCreated();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['to'] === '5511999999999');
    }

    public function test_sdr_responder_envia_por_covercut_quando_canal_e_oficial(): void
    {
        // CovercutChannelService chama POST {base_url}/messages/send (não /messages).
        // OpenRouter também precisa de fake, senão o SdrResponderService nunca chega
        // no passo de envio (fica sem resposta e retorna cedo).
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Claro, já te ajudo!']]],
            ], 200),
            '*/messages/send' => Http::response(['id' => 'wamid.2'], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123'],
        ]);

        // SdrResponderJob só age se a coluna tiver ia_ativo=true, e SdrResponderService
        // precisa de ao menos uma persona ativa no tenant para o LeadRouterService rotear.
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
        ]);
        SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'sdr_teste', 'nome_display' => 'SDR Teste',
            'system_prompt' => 'Você é um SDR de teste.', 'is_default' => true, 'ativo' => true,
        ]);

        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(5),
        ]);

        SdrResponderJob::dispatchSync($ticket->id, 'Preciso de ajuda', false, false, 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['to'] === '5511988888888');
    }
}
