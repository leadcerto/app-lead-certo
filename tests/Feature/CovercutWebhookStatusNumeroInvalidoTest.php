<?php

namespace Tests\Feature;

use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 02/10 (Leonardo): confirmado em produção via log (134
 * ocorrências reais em 5 dias) que a Meta manda um evento assíncrono de
 * status (`event: status`, `status.status: failed`, `errors[0].code:
 * 131026`) quando um número não tem WhatsApp — antes isso era só logado e
 * descartado ("evento não tratado"). Agora processa de verdade: marca o
 * contato (fato permanente sobre o número, não sobre o tenant), a chamada
 * perdida relacionada, e encerra qualquer ticket aberto desse contato neste
 * tenant.
 */
class CovercutWebhookStatusNumeroInvalidoTest extends TestCase
{
    use RefreshDatabase;

    private function postComAssinatura(array $payload, string $segredo)
    {
        $body       = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, $segredo);

        return $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);
    }

    private function payloadStatusFalhou(string $recipient, int $codigo, string $titulo = 'Message undeliverable'): array
    {
        return [
            'event'          => 'status',
            'from_number_id' => '950147584848138',
            'status'         => [
                'id'        => 'wamid.status1',
                'status'    => 'failed',
                'recipient' => $recipient,
                'errors'    => [
                    ['code' => $codigo, 'title' => $titulo, 'message' => $titulo],
                ],
            ],
        ];
    }

    public function test_status_failed_131026_marca_contato_chamada_e_encerra_ticket(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5541792586058']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(), 'origem' => 'ligacao',
        ]);
        $chamada = ChamadaPerdida::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'ticket_id' => $ticket->id,
            'numero_chamador' => '5541792586058', 'numero_receptor' => '5521999990000',
            'chamou_em' => now(), 'duracao_segundos' => 0, 'mensagem_enviada' => true,
        ]);

        $payload = $this->payloadStatusFalhou('5541792586058', 131026);

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertNotNull($contato->fresh()->whatsapp_invalido_em);
        $this->assertTrue($chamada->fresh()->numero_invalido);
        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->status);
        $this->assertSame('numero_invalido', $ticket->tag_desfecho);
    }

    public function test_status_failed_com_outro_codigo_nao_mexe_em_nada(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521985095257']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        // 131047 = janela de atendimento expirada, não é "número inválido".
        $payload = $this->payloadStatusFalhou('5521985095257', 131047, 'Re-engagement message');

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertNull($contato->fresh()->whatsapp_invalido_em);
        $this->assertSame('aberto', $ticket->fresh()->status);
    }

    public function test_status_delivered_nao_mexe_em_nada(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        Contato::factory()->create(['telefone' => '5521997714313']);

        $payload = [
            'event'          => 'status',
            'from_number_id' => '950147584848138',
            'status'         => ['id' => 'wamid.x', 'status' => 'delivered', 'recipient' => '5521997714313'],
        ];

        $response = $this->postComAssinatura($payload, 'segredo-abc');

        $response->assertOk();
        $this->assertNull(Contato::where('telefone', '5521997714313')->first()->whatsapp_invalido_em);
    }

    public function test_status_failed_131026_nao_afeta_ticket_de_outro_tenant(): void
    {
        $tenantDoCanal = Tenant::factory()->create();
        $outroTenant   = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenantDoCanal->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato        = Contato::factory()->create(['telefone' => '552123980024']);
        $ticketDoCanal  = TicketAtendimento::create([
            'tenant_id' => $tenantDoCanal->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $ticketOutro = TicketAtendimento::withoutGlobalScopes()->create([
            'tenant_id' => $outroTenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $payload = $this->payloadStatusFalhou('552123980024', 131026);

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertSame('encerrado', $ticketDoCanal->fresh()->status);
        $this->assertSame('aberto', $ticketOutro->fresh()->status);
    }

    public function test_status_failed_131026_e_idempotente_na_segunda_ocorrencia(): void
    {
        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '950147584848138', 'webhook_secret' => 'segredo-abc'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521960105938']);

        $payload = $this->payloadStatusFalhou('5521960105938', 131026);

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();
        $primeiraMarcacao = $contato->fresh()->whatsapp_invalido_em;

        $this->postComAssinatura($payload, 'segredo-abc')->assertOk();

        $this->assertEquals($primeiraMarcacao, $contato->fresh()->whatsapp_invalido_em);
    }
}
