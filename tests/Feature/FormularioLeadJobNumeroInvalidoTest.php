<?php

namespace Tests\Feature;

use App\Jobs\FormularioLeadJob;
use App\Models\Contato;
use App\Models\Formulario;
use App\Models\FormularioEnvio;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real 02/10 (Leonardo): número já confirmado sem WhatsApp (marcado
 * via CovercutWebhookController::processarStatusEntrega(), quando a Meta
 * confirma o erro 131026 de forma assíncrona) é um fato permanente sobre o
 * número — não faz sentido o formulário tentar mandar mensagem de novo nem
 * deixar o ticket aberto.
 */
class FormularioLeadJobNumeroInvalidoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComContato(?\Illuminate\Support\Carbon $whatsappInvalidoEm = null): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
        $contato = Contato::factory()->create([
            'telefone'              => '5511999999999',
            'whatsapp_invalido_em'  => $whatsappInvalidoEm,
        ]);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);
    }

    private function criarEnvio(TicketAtendimento $ticket, array $formularioOverrides = []): FormularioEnvio
    {
        $formulario = Formulario::create(array_merge([
            'tenant_id'      => $ticket->tenant_id,
            'nome'           => 'Formulário de teste',
            'acao_pos_envio' => 'bot_sdr',
            'double_optin'   => false,
            'ativo'          => true,
        ], $formularioOverrides));

        return FormularioEnvio::create([
            'formulario_id' => $formulario->id,
            'contato_id'    => $ticket->contato_id,
            'ticket_id'     => $ticket->id,
            'dados_envio'   => [],
            'confirmado'    => false,
            'processado'    => false,
        ]);
    }

    public function test_numero_confirmado_invalido_nao_envia_mensagem_e_encerra_o_ticket(): void
    {
        Http::fake();

        $ticket = $this->criarTicketComContato(now()->subDay());
        $envio  = $this->criarEnvio($ticket);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle();

        Http::assertNothingSent();
        $this->assertTrue($envio->fresh()->processado);
        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->status);
        $this->assertSame('numero_invalido', $ticket->tag_desfecho);
    }

    public function test_numero_sem_confirmacao_de_invalido_continua_funcionando_normal(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.ok'], 200)]);

        $ticket = $this->criarTicketComContato(null);
        $envio  = $this->criarEnvio($ticket, ['acao_pos_envio' => 'mensagem_unica', 'mensagem_custom' => 'Oi!']);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send'));
        $this->assertNotSame('encerrado', $ticket->fresh()->status);
    }
}
