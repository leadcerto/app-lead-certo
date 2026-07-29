<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnvioUsaCanalDoTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdr_responder_envia_pelo_token_do_canal_do_ticket_nao_do_tenant(): void
    {
        Http::fake(['*/send/text' => Http::response(['id' => 'msg1'], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-legado-do-tenant']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-do-canal-certo'],
        ]);
        $contato = Contato::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'joao_teste', 'nome_display' => 'João',
            'system_prompt' => 'Você é um SDR de teste.', 'is_default' => true, 'ativo' => true,
        ]);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'sdr_persona_id' => $persona->id,
            'status' => 'aberto', 'aberto_em' => now(), 'etapa_ia' => 'etapa_1',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Oi! Tudo certo por aqui.']]],
            ], 200),
            '*/send/text' => Http::response(['id' => 'msg1'], 200),
        ]);

        app(SdrResponderService::class)->responder($ticket);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-do-canal-certo'));
    }

    /**
     * Diferente do teste acima (que só prova "canal vence token legado do tenant"),
     * este prova a escolha ENTRE VÁRIOS canais reais: com dois números conectados no
     * mesmo tenant, o envio tem que usar o token do canal certo (o que está de fato
     * gravado no ticket), não o primeiro canal do tenant nem qualquer outro.
     */
    public function test_sdr_responder_usa_o_canal_certo_do_ticket_entre_varios_canais_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $canalA = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_token' => 'token-canal-A'],
        ]);
        $canalB = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_token' => 'token-canal-B'],
        ]);

        $contato = Contato::factory()->create();
        $persona = \App\Models\SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'joao_teste_2', 'nome_display' => 'João',
            'system_prompt' => 'Você é um SDR de teste.', 'is_default' => true, 'ativo' => true,
        ]);

        // Ticket está estampado com o canal B (o mais recente a tocar o lead) —
        // envio precisa usar o token de B, mesmo com A também conectado no tenant.
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canalB->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'sdr_persona_id' => $persona->id,
            'status' => 'aberto', 'aberto_em' => now(), 'etapa_ia' => 'etapa_1',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Oi! Tudo certo por aqui.']]],
            ], 200),
            '*/send/text' => Http::response(['id' => 'msg1'], 200),
        ]);

        app(SdrResponderService::class)->responder($ticket);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-canal-B'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-canal-A'));
    }
}
