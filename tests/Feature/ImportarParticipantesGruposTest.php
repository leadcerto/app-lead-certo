<?php

namespace Tests\Feature;

use App\Jobs\AtualizarNomeGoogleComDadoLocalJob;
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
            AtualizarNomeGoogleComDadoLocalJob::class,
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

    /**
     * Achado real 01/10 (Leonardo): a lista de participantes de grupo nunca
     * vem com nome, só quando a pessoa manda mensagem o microserviço captura
     * (ver nomesParticipantes.js, lado Node) — o comando usa o nome quando
     * vem preenchido, em vez de sempre "Sem Nome".
     */
    public function test_usa_o_nome_do_participante_quando_vem_preenchido(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => 'João Silva']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $this->assertSame('João Silva', $contato->nome);
    }

    public function test_mantem_sem_nome_quando_participante_nao_tem_nome_capturado(): void
    {
        $this->fakeJobsPadrao();
        $tenant = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => null]],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato = Contato::where('telefone', '5521999998888')->first();
        $this->assertSame('Sem Nome', $contato->nome);
    }

    /**
     * Achado real 01/10 (Leonardo, pedido explícito): contato criado antes do
     * nome ter sido capturado fica "Sem Nome" pra sempre, a menos que a
     * mesma rotina diária recupere retroativamente quando o nome resolver
     * numa rodada futura.
     */
    public function test_recupera_nome_retroativamente_quando_contato_existente_estava_sem_nome(): void
    {
        $this->fakeJobsPadrao();
        $tenant  = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);
        $contato = Contato::factory()->create([
            'telefone' => '5521999998888', 'nome' => 'Sem Nome', 'origem' => 'whatsapp_grupo',
        ]);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => 'João Silva']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato->refresh();
        $this->assertSame('João Silva', $contato->nome);
    }

    public function test_recuperacao_de_nome_dispara_job_de_atualizar_google_quando_ja_tem_etag(): void
    {
        $this->fakeJobsPadrao();
        $tenant  = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);
        $contato = Contato::factory()->create([
            'telefone' => '5521999998888', 'nome' => 'Sem Nome', 'origem' => 'whatsapp_grupo',
        ]);
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-abc',
        ]);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => 'João Silva']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        Bus::assertDispatched(AtualizarNomeGoogleComDadoLocalJob::class, fn ($job) => $this->jobTemVinculoId($job, $vinculo->id));
    }

    public function test_recuperacao_de_nome_nao_dispara_job_de_google_sem_etag_ainda(): void
    {
        $this->fakeJobsPadrao();
        $tenant  = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);
        $contato = Contato::factory()->create([
            'telefone' => '5521999998888', 'nome' => 'Sem Nome', 'origem' => 'whatsapp_grupo',
        ]);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => 'João Silva']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato->refresh();
        $this->assertSame('João Silva', $contato->nome);
        Bus::assertNotDispatched(AtualizarNomeGoogleComDadoLocalJob::class);
    }

    public function test_nao_recupera_nome_quando_contato_existente_nao_e_de_origem_whatsapp_grupo(): void
    {
        $this->fakeJobsPadrao();
        $tenant  = Tenant::factory()->create();
        $this->canalMessengerProprio($tenant);
        $contato = Contato::factory()->create([
            'telefone' => '5521999998888', 'nome' => 'Sem Nome', 'origem' => 'manual',
        ]);

        Http::fake(['*/sessoes/sessao-teste/grupos' => Http::response([
            'grupos' => [[
                'jid'  => '120363012345678901@g.us',
                'nome' => 'Vip Membros',
                'participantes' => [['telefone' => '5521999998888', 'nome' => 'João Silva']],
            ]],
        ], 200)]);

        $this->artisan('grupos:importar-participantes')->assertExitCode(0);

        $contato->refresh();
        $this->assertSame('Sem Nome', $contato->nome);
        Bus::assertNotDispatched(AtualizarNomeGoogleComDadoLocalJob::class);
    }

    private function jobTemVinculoId(object $job, int $vinculoId): bool
    {
        $reflexao = new \ReflectionClass($job);
        $prop = $reflexao->getProperty('vinculoId');
        $prop->setAccessible(true);
        return $prop->getValue($job) === $vinculoId;
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
