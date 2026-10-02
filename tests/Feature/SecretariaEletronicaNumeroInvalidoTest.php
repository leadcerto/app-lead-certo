<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real 02/10 (Leonardo): a maioria das chamadas perdidas que nunca
 * recebem resposta é de números de telemarketing/propaganda sem WhatsApp —
 * 82,8% dos tickets de origem "ligação" em setembro nunca tiveram resposta
 * do lead, contra só 5,5% dos de origem "whatsapp" direto. Quando o número
 * já foi confirmado sem WhatsApp (ver CovercutWebhookController::
 * processarStatusEntrega()), a Secretária não deve repetir o ciclo inteiro
 * (criar ticket, mandar mensagem, esperar a Meta confirmar de novo).
 */
class SecretariaEletronicaNumeroInvalidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_numero_ja_confirmado_sem_whatsapp_nao_cria_ticket_nem_dispara_mensagem(): void
    {
        Queue::fake();

        Tenant::factory()->create(['secretaria_token' => 'token-invalido', 'secretaria_envio_ativo' => true]);
        Contato::factory()->create(['telefone' => '5511988887777', 'whatsapp_invalido_em' => now()->subHour()]);

        $response = $this->postJson('/api/secretaria/token-invalido', [
            'numero_chamador'  => '11988887777',
            'duracao_segundos' => 0,
        ]);

        $response->assertOk();
        $response->assertJson(['acao' => 'numero_sem_whatsapp_confirmado']);
        Queue::assertNotPushed(SequenciaMensagemJob::class);
        $this->assertSame(0, TicketAtendimento::count());

        $chamada = ChamadaPerdida::where('numero_chamador', '5511988887777')->firstOrFail();
        $this->assertTrue($chamada->numero_invalido);
    }

    public function test_numero_sem_confirmacao_continua_criando_ticket_normalmente(): void
    {
        Queue::fake();

        Tenant::factory()->create(['secretaria_token' => 'token-normal', 'secretaria_envio_ativo' => true]);

        $this->postJson('/api/secretaria/token-normal', [
            'numero_chamador'  => '11977776666',
            'duracao_segundos' => 0,
        ])->assertOk();

        Queue::assertPushed(SequenciaMensagemJob::class);
        $this->assertSame(1, TicketAtendimento::count());
    }

    public function test_dados_painel_converte_horario_da_chamada_para_fuso_de_sao_paulo(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        // 18:00 UTC = 15:00 em São Paulo (UTC-3, sem horário de verão no Brasil).
        $instanteUtc = \Illuminate\Support\Carbon::parse('2026-10-01 18:00:00', 'UTC');

        ChamadaPerdida::create([
            'tenant_id'        => $tenant->id,
            'numero_chamador'  => '5511999990000',
            'numero_receptor'  => '5521999990000',
            'chamou_em'        => $instanteUtc,
            'duracao_segundos' => 0,
            'mensagem_enviada' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/secretaria-eletronica/dados');

        $response->assertOk();
        $this->assertSame('01/10/2026 15:00', $response->json('chamadas.0.chamou_em'));
    }
}
