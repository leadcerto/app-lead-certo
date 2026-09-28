<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 24/09 (Leonardo, ticket #4907 "Rodrigo Sani"): o lead recusou o
 * orçamento e se despediu, mas o card continuou recebendo mensagens
 * automáticas de cobrança por mais 18 horas. SequenciaMensagemJob já checava
 * status/coluna/agente_responsavel antes de enviar, mas NUNCA checava
 * aguardando_orientacao_em — o campo que pausa o atendimento quando a IA (via
 * token [ENCERRADO]/[DUVIDA]/handoff prematuro/etc.) ou outra rede de
 * segurança decide que um humano precisa revisar a situação antes de
 * qualquer coisa continuar. As mensagens já enfileiradas da sequência
 * ignoravam essa pausa completamente e continuavam disparando por cima dela.
 */
class SequenciaMensagemJobAguardandoOrientacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(?\Illuminate\Support\Carbon $aguardandoOrientacaoEm): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'aguardando_orientacao_em' => $aguardandoOrientacaoEm,
        ]);
    }

    public function test_mensagem_de_sequencia_nao_e_enviada_enquanto_aguarda_orientacao_humana(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket(now());

        (new SequenciaMensagemJob($ticket->id, 'Oi! Tudo bem? Já deu uma olhada no orçamento?', null, 'aguardando_lead', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_mensagem_obrigatoria_tambem_nao_sai_enquanto_aguarda_orientacao_humana(): void
    {
        // Diferente do guard de agente_responsavel (onde "obrigatório" é
        // exceção): aguardando_orientacao_em sinaliza que um humano precisa
        // decidir antes de QUALQUER coisa automática continuar — não tem
        // exceção por "obrigatório" aqui.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket(now());

        (new SequenciaMensagemJob($ticket->id, 'Mensagem obrigatória', null, 'aguardando_lead', null, true))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_mensagem_de_sequencia_sai_normalmente_quando_nao_esta_aguardando_orientacao(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ticket = $this->criarTicket(null);

        (new SequenciaMensagemJob($ticket->id, 'Oi! Tudo bem? Já deu uma olhada no orçamento?', null, 'aguardando_lead', null, false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => true);
        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id]);
    }
}
