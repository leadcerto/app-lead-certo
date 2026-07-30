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

    /**
     * Decisão de produto: resposta manual do atendente no card do Kanban continua
     * INSTANTÂNEA — uma única mensagem, sem divisão em balões nem delay simulado de
     * digitação (isso é exclusivo do bot/sequências/follow-up). Este teste usa um
     * texto com parágrafos duplos — que o HumanizacaoService dividiria em vários
     * balões — para provar que o caminho manual (enviarTextoDireto) não passa por
     * essa divisão: uma única chamada HTTP, com o texto completo.
     */
    public function test_kanban_controller_envia_direto_sem_humanizacao_quando_canal_e_uazapi(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg-direto'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, // provider padrão da factory = 'uazapi'
            'config'    => ['instance_token' => 'token-do-canal'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511977777777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $textoLongo = "Primeiro parágrafo da resposta.\n\nSegundo parágrafo, que separado ativaria a divisão em balões do HumanizacaoService.";

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => $textoLongo,
        ]);

        $response->assertCreated();

        // Uma única chamada, com o texto inteiro — nada de balões separados.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-do-canal')
            && $request['text'] === $textoLongo);
    }

    /**
     * Achado da revisão: canal vinculado ao ticket mas sem instance_token configurado.
     * Comportamento aceito: 502 genérico ("Falha ao enviar pelo WhatsApp"), sem
     * nenhuma chamada HTTP de saída (o guard de token do UazapiChannelService barra
     * antes de qualquer tentativa de envio).
     */
    public function test_kanban_controller_retorna_502_quando_canal_sem_instance_token(): void
    {
        Http::fake();

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => [], // sem instance_token
        ]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Oi, tudo bem?',
        ]);

        $response->assertStatus(502);
        Http::assertNothingSent();
    }

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
