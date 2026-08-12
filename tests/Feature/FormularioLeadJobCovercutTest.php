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
 * Achado real (auditoria de paridade Uazapi/Covercut, 2026-08-04):
 * FormularioLeadJob resolvia $ticket->canal?->tokenUazapi() direto, que é
 * sempre null pra um canal Covercut — um lead de formulário cujo ticket
 * resolvesse pro canal oficial falhava silenciosamente (nem o bot_sdr
 * chegava a disparar). Corrigido pra rotear por $canal->servico()->enviarTexto(),
 * mesmo padrão de SequenciaMensagemJob/FollowupConversas/SdrResponderJob.
 */
class FormularioLeadJobCovercutTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketCovercut(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);
    }

    private function criarEnvio(TicketAtendimento $ticket, array $formularioOverrides): FormularioEnvio
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

    public function test_double_optin_via_covercut_e_enviado_pelo_servico_do_canal(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.form'], 200)]);

        $ticket = $this->criarTicketCovercut();
        $envio  = $this->criarEnvio($ticket, ['double_optin' => true]);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && $request['to'] === '5511999999999'
            && str_contains($request['text']['body'], 'Recebemos seu cadastro'));

        $this->assertTrue($envio->fresh()->processado);
    }

    public function test_mensagem_unica_via_covercut_e_enviada_pelo_servico_do_canal(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.form2'], 200)]);

        $ticket = $this->criarTicketCovercut();
        $envio  = $this->criarEnvio($ticket, [
            'acao_pos_envio'  => 'mensagem_unica',
            'mensagem_custom' => 'Recebemos seu cadastro, obrigado!',
        ]);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && $request['text']['body'] === 'Recebemos seu cadastro, obrigado!');

        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
        $this->assertTrue($envio->fresh()->processado);
    }

    public function test_bot_sdr_via_covercut_nao_e_mais_bloqueado_por_falta_de_token_uazapi(): void
    {
        Http::fake();

        $ticket = $this->criarTicketCovercut();
        $envio  = $this->criarEnvio($ticket, ['acao_pos_envio' => 'bot_sdr']);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle();

        // Antes do fix, o guard de "sem telefone ou token Uazapi" retornava cedo
        // e o envio nunca era marcado como processado nem o SdrResponderJob disparava.
        $this->assertTrue($envio->fresh()->processado);
    }
}
