<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    /**
     * Achado real 29/09 (plano de extração de contatos de grupos, pedido do
     * Leonardo): reescrito pra cadastrar só como Contato frio (etiqueta 🚩
     * FRIOS), sem cruzar nome da agenda nem criar Ticket/Kanban automático —
     * mudança de comportamento intencional, não regressão. Ver
     * ImportarParticipantesGruposTest.php pra cobertura completa do novo
     * comportamento.
     */
    public function test_importar_participantes_grupos_cria_contato_frio_scoped_ao_tenant_correto(): void
    {
        Bus::fake([
            \App\Jobs\ProvisionarEtiquetasGoogleJob::class,
            \App\Jobs\EnriquecerContatoNovoViaGoogleJob::class,
            \App\Jobs\MarcarNovoLeadEtiquetaJob::class,
            \App\Jobs\PushContatoParaGoogleJob::class,
            \App\Jobs\MarcarContatoFrioEtiquetaJob::class,
        ]);

        Http::fake([
            '*/group/list' => Http::response([
                'groups' => [
                    [
                        'chatid' => '120363011111111111@g.us',
                        'Name' => 'Grupo Teste',
                        'Participants' => [
                            ['PhoneNumber' => '5511988887777@s.whatsapp.net'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $this->artisan('grupos:importar-participantes', ['--tenant' => $tenant->id])->assertSuccessful();

        $contato = \App\Models\Contato::where('telefone', '5511988887777')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Sem Nome', $contato->nome);
        $this->assertSame('whatsapp_grupo', $contato->origem);

        $vinculo = \App\Models\VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertSame($tenant->id, $vinculo->tenant_id);

        $this->assertSame(
            0,
            TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count()
        );
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
