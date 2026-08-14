<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achado real (2026-08-13): SecretariaEletronicaController marcava
 * `ChamadaPerdida.mensagem_enviada = true` de forma otimista, na hora do
 * webhook — antes mesmo do SequenciaMensagemJob (assíncrono, com delay de 5s)
 * tentar enviar de verdade. Se o envio falhasse depois (janela fechada,
 * contato bloqueado, humano assumiu o ticket nesses 5s), a tela da Secretária
 * continuava mostrando "mensagem enviada", escondendo a falha real. O job
 * agora reporta o desfecho de volta via `chamadaPerdidaId` — cobrindo os dois
 * canais (regra de paridade Uazapi/Covercut).
 */
class SequenciaMensagemJobChamadaPerdidaTest extends TestCase
{
    use RefreshDatabase;

    private function criarChamadaETicketUazapi(): array
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'tok-do-canal'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $chamada = ChamadaPerdida::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'ticket_id' => $ticket->id,
            'numero_chamador' => '5511988887777', 'numero_receptor' => '5521999990000',
            'chamou_em' => now(), 'duracao_segundos' => 0, 'mensagem_enviada' => false,
        ]);

        return [$ticket, $chamada];
    }

    private function criarChamadaETicketCovercut(array $ticketOverrides = []): array
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511977776666']);
        $ticket  = TicketAtendimento::create(array_merge([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ], $ticketOverrides));
        $chamada = ChamadaPerdida::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'ticket_id' => $ticket->id,
            'numero_chamador' => '5511977776666', 'numero_receptor' => '5521999990000',
            'chamou_em' => now(), 'duracao_segundos' => 0, 'mensagem_enviada' => false,
        ]);

        return [$ticket, $chamada];
    }

    public function test_uazapi_sucesso_marca_mensagem_enviada(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        [$ticket, $chamada] = $this->criarChamadaETicketUazapi();

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        $chamada->refresh();
        $this->assertTrue($chamada->mensagem_enviada);
        $this->assertNotNull($chamada->mensagem_enviada_em);
    }

    public function test_uazapi_falha_de_envio_mantem_mensagem_enviada_falso(): void
    {
        Http::fake([
            '*/instance/presence' => Http::response([], 200),
            '*/send/text'         => Http::response(['erro' => 'instância desconectada'], 500),
        ]);
        [$ticket, $chamada] = $this->criarChamadaETicketUazapi();

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        $chamada->refresh();
        $this->assertFalse($chamada->mensagem_enviada);
        $this->assertNull($chamada->mensagem_enviada_em);
    }

    public function test_covercut_sucesso_marca_mensagem_enviada(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.abc'], 200)]);
        [$ticket, $chamada] = $this->criarChamadaETicketCovercut();

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        $chamada->refresh();
        $this->assertTrue($chamada->mensagem_enviada);
        $this->assertNotNull($chamada->mensagem_enviada_em);
    }

    public function test_covercut_janela_expirada_mantem_mensagem_enviada_falso(): void
    {
        Http::fake();
        [$ticket, $chamada] = $this->criarChamadaETicketCovercut(['janela_expira_em' => now()->subHour()]);

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $chamada->refresh();
        $this->assertFalse($chamada->mensagem_enviada);
        $this->assertNull($chamada->mensagem_enviada_em);
    }

    public function test_contato_bloqueado_mantem_mensagem_enviada_falso_sem_chamar_http(): void
    {
        Http::fake();
        [$ticket, $chamada] = $this->criarChamadaETicketUazapi();
        VinculoContatoTenant::create([
            'contato_id' => $ticket->contato_id, 'tenant_id' => $ticket->tenant_id,
            'bloqueado_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $chamada->refresh();
        $this->assertFalse($chamada->mensagem_enviada);
    }

    public function test_humano_assumiu_ticket_antes_do_delay_mantem_mensagem_enviada_falso(): void
    {
        Http::fake();
        [$ticket, $chamada] = $this->criarChamadaETicketUazapi();
        $ticket->update(['agente_responsavel' => 'humano']);

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vi que você ligou.', chamadaPerdidaId: $chamada->id))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $chamada->refresh();
        $this->assertFalse($chamada->mensagem_enviada);
    }

    /**
     * Chamador padrão (SequenciaService, sequências normais fora da Secretária)
     * nunca passa chamadaPerdidaId — precisa continuar funcionando sem tocar
     * (nem quebrar por falta de) nenhuma linha de chamadas_perdidas.
     */
    public function test_sem_chamada_perdida_id_nao_toca_tabela_de_chamadas(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        [$ticket, $chamada] = $this->criarChamadaETicketUazapi();

        (new SequenciaMensagemJob($ticket->id, 'Mensagem de sequência normal, sem ligação.'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        $chamada->refresh();
        $this->assertFalse($chamada->mensagem_enviada);
    }
}
