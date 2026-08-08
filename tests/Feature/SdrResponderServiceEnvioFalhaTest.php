<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Achado Importante 3 da revisão final: a Mensagem do bot era persistida mesmo
 * quando o envio falhava (ex: janela de conversa expirada no Covercut, bloqueio
 * determinístico). Isso registrava no histórico uma resposta que o lead nunca
 * recebeu, e o FollowupConversas avançava followup_estagio_enviado achando que a
 * mensagem tinha saído. Fix: se enviarTexto() retornar false, não persiste,
 * loga um warning e retorna null.
 */
class SdrResponderServiceEnvioFalhaTest extends TestCase
{
    use RefreshDatabase;

    public function test_quando_envio_falha_nao_persiste_mensagem_e_loga_warning(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Aqui está sua resposta.']]],
            ], 200),
            // Nenhuma rota de /messages/send fakeada com sucesso: qualquer chamada
            // real ao CovercutChannelService cairia aqui e falharia — mas o cenário
            // abaixo já bloqueia antes disso (janela expirada), então nem chega a
            // fazer a chamada HTTP.
        ]);
        Log::spy();

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'sdr_persona_id' => $persona->id,
            'janela_expira_em' => now()->subHour(), // já expirou → bloqueio determinístico
        ]);

        $resposta = app(SdrResponderService::class)->responder($ticket);

        $this->assertNull($resposta);
        $this->assertSame(0, Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->count());
        // Não usa Http::assertNothingSent(): a chamada ao OpenRouter (pra gerar a
        // resposta) acontece normalmente — o que importa é que o canal (/messages/send)
        // nunca é chamado, já que a janela expirou.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/send'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'resposta não persistida'))
            ->once();
    }

    public function test_incrementa_tentativas_envio_falhas_quando_canal_recusa(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Aqui está sua resposta.']]],
            ], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'sdr_persona_id' => $persona->id,
            'janela_expira_em' => now()->subHour(),
        ]);

        app(SdrResponderService::class)->responder($ticket);

        $this->assertSame(1, $ticket->fresh()->tentativas_envio_falhas);
    }

    public function test_zera_tentativas_envio_falhas_quando_envio_confirma(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Aqui está sua resposta.']]],
            ], 200),
            // CovercutChannelService::enviar() faz POST em "{base_url}/messages/send"
            // (app/Services/Canais/CovercutChannelService.php:108) — qualquer 2xx aqui
            // já satisfaz $response->successful().
            '*/messages/send' => Http::response(['id' => 'wamid.123'], 200),
        ]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'sdr_persona_id' => $persona->id,
            'tentativas_envio_falhas' => 2,
        ]);

        app(SdrResponderService::class)->responder($ticket);

        $this->assertSame(0, $ticket->fresh()->tentativas_envio_falhas);
    }
}
