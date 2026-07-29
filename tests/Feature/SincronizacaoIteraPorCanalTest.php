<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizacaoIteraPorCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincronizar_contatos_grava_canal_no_ticket_criado(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511977776666@s.whatsapp.net', 'contact_name' => 'Ciclano', 'contact_FirstName' => 'Ciclano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $this->artisan('contatos:sincronizar-whatsapp', ['--tenant' => $tenant->id])->assertSuccessful();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_sincronizar_contatos_processa_ambos_canais_de_um_tenant_com_dois_canais_conectados(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511977776666@s.whatsapp.net', 'contact_name' => 'Ciclano', 'contact_FirstName' => 'Ciclano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canalA = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_name' => 'canal-a', 'instance_token' => 'token-canal-a'],
        ]);
        $canalB = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_name' => 'canal-b', 'instance_token' => 'token-canal-b'],
        ]);

        $this->artisan('contatos:sincronizar-whatsapp', ['--tenant' => $tenant->id])->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('token')[0] === 'token-canal-a');
        Http::assertSent(fn ($request) => $request->header('token')[0] === 'token-canal-b');
    }

    public function test_importar_participantes_grupos_cria_contato_e_ticket_com_canal_correto(): void
    {
        Http::fake([
            '*/group/list' => Http::response([
                'groups' => [
                    [
                        'Name' => 'Grupo Teste',
                        'Participants' => [
                            ['PhoneNumber' => '5511988887777@s.whatsapp.net'],
                        ],
                    ],
                ],
            ], 200),
            '*/contacts' => Http::response([
                ['jid' => '5511988887777@s.whatsapp.net', 'contact_name' => 'Fulano', 'contact_FirstName' => 'Fulano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $this->artisan('grupos:importar-participantes', ['--tenant' => $tenant->id])->assertSuccessful();

        $contato = \App\Models\Contato::where('telefone', '5511988887777')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Fulano', $contato->nome);

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }

    public function test_sincronizar_contatos_pula_canal_sem_token_e_continua_processando_os_demais(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511977776666@s.whatsapp.net', 'contact_name' => 'Ciclano', 'contact_FirstName' => 'Ciclano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canalSemToken = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_name' => 'sem-token'],
        ]);
        $canalComToken = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'status'    => 'connected',
            'config'    => ['instance_name' => 'com-token', 'instance_token' => 'token-valido'],
        ]);

        $this->artisan('contatos:sincronizar-whatsapp', ['--tenant' => $tenant->id])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('token')[0] === 'token-valido');

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canalComToken->id, $ticket->whatsapp_canal_id);
    }
}
