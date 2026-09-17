<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Espelha CovercutWebhookNumeroRecicladoTest — regra de paridade entre canais.
 * Achado real (2026-09-16/17, ticket #4788, contato #93802 "Daniela"/Rodrigo):
 * mensagem nova pra um telefone que já tem contato cadastrado com nome real
 * diferente não tinha nenhuma proteção contra número reciclado.
 */
class UazapiWebhookNumeroRecicladoTest extends TestCase
{
    use RefreshDatabase;

    private function enviarMensagem(string $webhookToken, string $telefone, string $texto, ?string $senderName = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/webhook/uazapi/{$webhookToken}", [
            'EventType' => 'messages',
            'message'   => array_filter([
                'fromMe'     => false,
                'isGroup'    => false,
                'chatid'     => "{$telefone}@s.whatsapp.net",
                'text'       => $texto,
                'senderName' => $senderName,
            ], fn ($v) => $v !== null),
        ]);
    }

    private function criarCanal(Tenant $tenant, string $webhookToken, string $instanceToken): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id'     => $tenant->id,
            'webhook_token' => $webhookToken,
            'config'        => ['instance_token' => $instanceToken],
        ]);
    }

    public function test_numero_reciclado_e_flagrado_sem_bloquear_a_conversa(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_webhook_token' => 'wh-reciclado-1', 'uazapi_instance_token' => 'inst-reciclado-1']);
        $this->criarCanal($tenant, 'wh-reciclado-1', 'inst-reciclado-1');

        $contato = Contato::factory()->create(['telefone' => '5511944442222', 'nome' => 'Daniela']);

        $this->enviarMensagem('wh-reciclado-1', '5511944442222', 'Oi, bom dia!', senderName: 'Rodrigo');

        $contato->refresh();
        $this->assertSame('Daniela', $contato->nome, 'não deve sobrescrever o nome existente silenciosamente');

        $this->assertDatabaseHas('contatos_pendentes', [
            'telefone'             => '5511944442222',
            'contato_existente_id' => $contato->id,
            'tipo_conflito'        => 'numero_possivelmente_reciclado',
            'nome_existente'       => 'Daniela',
        ]);

        // a conversa/ticket segue normal — não é bloqueada pelo flag de auditoria
        $this->assertDatabaseCount('mensagens', 1);
        $this->assertDatabaseHas('tickets_atendimento', ['contato_id' => $contato->id]);
    }
}
