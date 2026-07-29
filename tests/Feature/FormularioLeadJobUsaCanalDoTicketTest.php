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

class FormularioLeadJobUsaCanalDoTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmacao_de_double_optin_usa_o_token_do_canal_do_ticket_nao_o_legado_do_tenant(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-legado-do-tenant']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-do-canal-certo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511977778888']);

        $formulario = Formulario::create([
            'tenant_id'       => $tenant->id,
            'nome'            => 'Formulário de teste',
            'acao_pos_envio'  => 'bot_sdr',
            'double_optin'    => true,
            'ativo'           => true,
        ]);

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $envio = FormularioEnvio::create([
            'formulario_id' => $formulario->id,
            'contato_id'    => $contato->id,
            'ticket_id'     => $ticket->id,
            'dados_envio'   => [],
            'confirmado'    => false,
            'processado'    => false,
        ]);

        (new FormularioLeadJob($envio->id, $ticket->id))->handle(app(\App\Services\HumanizacaoService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-do-canal-certo'));
    }
}
