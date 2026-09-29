<?php

namespace Tests\Feature;

use App\Jobs\MarcarContatoFrioEtiquetaJob;
use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Plano de extração de contatos de grupos/comunidades (29/09): reescrita de
 * grupos:importar-participantes pra funcionar em qualquer canal não-oficial
 * (Uazapi ou Messenger próprio, via CanalComGruposInterface), cadastrar só
 * como Contato (sem Ticket/Kanban automático) e marcar etiqueta 🚩 FRIOS.
 * Ver [[whatsapp-extracao-contatos-grupos]].
 */
class ImportarParticipantesGruposTest extends TestCase
{
    use RefreshDatabase;

    private function fakeJobsPadrao(): void
    {
        Bus::fake([
            \App\Jobs\ProvisionarEtiquetasGoogleJob::class,
            \App\Jobs\EnriquecerContatoNovoViaGoogleJob::class,
            \App\Jobs\MarcarNovoLeadEtiquetaJob::class,
            PushContatoParaGoogleJob::class,
            MarcarContatoFrioEtiquetaJob::class,
        ]);
    }

    private function canalMessengerProprio(Tenant $tenant, string $phone = '5521900000000'): WhatsappCanal
    {
        return WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'nao_oficial', 'provider' => 'messenger_proprio',
            'status' => 'connected', 'phone' => $phone,
            'config' => ['session_id' => 'sessao-teste'],
        ]);
    }

    public function test_processa_canal_messenger_proprio_tambem(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response(['grupos' => []], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessoes/sessao-teste/grupos'));
    }

    public function test_cadastra_participante_novo_como_contato_frio_sem_criar_ticket(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $this->assertNotNull($contato);
        $this->assertSame('Sem Nome', $contato->nome);
        $this->assertSame('whatsapp_grupo', $contato->origem);

        $this->assertSame(
            0,
            TicketAtendimento::withoutGlobalScopes()->where('contato_id', $contato->id)->count(),
            'não pode criar ticket/kanban automático pra contato extraído de grupo'
        );

        Bus::assertDispatched(PushContatoParaGoogleJob::class);
        Bus::assertDispatched(MarcarContatoFrioEtiquetaJob::class);
    }

    public function test_registra_jid_e_nome_do_grupo_em_comum_no_vinculo(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->where('tenant_id', $tenant->id)->first();

        $this->assertSame(
            [['jid' => '120363012345678901@g.us', 'nome_visto' => 'Vip Membros']],
            $vinculo->grupos_whatsapp_em_comum
        );
    }

    public function test_participante_ja_existente_nao_e_duplicado_so_ganha_vinculo_e_grupo_em_comum(): void
    {
        $this->fakeJobsPadrao();
        $tenant  = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);
        $contato = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Cliente Real', 'tipo_contato' => 'cliente']);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $this->assertSame(1, Contato::where('telefone', '5521999998888')->count());
        $contato->refresh();
        $this->assertSame('Cliente Real', $contato->nome); // não sobrescreve

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($vinculo);
        $this->assertSame('120363012345678901@g.us', $vinculo->grupos_whatsapp_em_comum[0]['jid']);

        // Contato já existente não deve ser tratado como novo lead frio.
        Bus::assertNotDispatched(MarcarContatoFrioEtiquetaJob::class);
    }

    public function test_ignora_participante_com_numero_invalido(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => 'x@g.us',
                'nome' => 'Grupo Teste',
                'participantes' => [['telefone' => '123']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $this->assertSame(0, Contato::where('telefone', '123')->count());
    }

    public function test_ignora_o_proprio_numero_do_canal(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant, '5521900000000');

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => 'x@g.us',
                'nome' => 'Grupo Teste',
                'participantes' => [['telefone' => '5521900000000']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $this->assertSame(0, Contato::where('telefone', '5521900000000')->count());
    }

    public function test_rodar_duas_vezes_nao_duplica_grupo_em_comum(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);
        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->where('tenant_id', $tenant->id)->first();

        $this->assertCount(1, $vinculo->grupos_whatsapp_em_comum);
    }
}
