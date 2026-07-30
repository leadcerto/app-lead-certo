<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Achado Crítico 1 da revisão final: SequenciaMensagemJob resolvia o token via
 * $ticket->canal?->tokenUazapi(), que é sempre null para um canal Covercut — a
 * sequência inteira de um lead novo no canal oficial nunca saía. O fix roteia o
 * envio de texto pela abstração $canal->servico()->enviarTexto(), preservando o
 * caminho Uazapi como estava (token + humanização/botões/imagem inalterados).
 */
class SequenciaMensagemJobCovercutTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketCovercut(array ...$overrides): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config'    => ['phone_number_id' => '123456', 'webhook_secret' => 'segredo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);

        return TicketAtendimento::create(array_merge([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ], ...$overrides));
    }

    public function test_mensagem_de_texto_via_covercut_e_enviada_pelo_servico_do_canal(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.seq'], 200)]);

        $ticket = $this->criarTicketCovercut();

        (new SequenciaMensagemJob($ticket->id, 'Oi! Vamos começar seu atendimento.'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && $request['to'] === '5511999999999'
            && $request['text']['body'] === 'Oi! Vamos começar seu atendimento.');

        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id, 'remetente' => 'bot', 'conteudo' => 'Oi! Vamos começar seu atendimento.',
        ]);
    }

    public function test_mensagem_com_botoes_via_covercut_e_pulada_sem_chamada_http(): void
    {
        Http::fake();
        Log::spy();

        $ticket = $this->criarTicketCovercut();
        $botoes = [['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'servico_agendado']];

        (new SequenciaMensagemJob($ticket->id, 'Confirma pra mim?', null, null, $botoes))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'mídia/botões não suportados no canal oficial'))
            ->once();
    }

    public function test_mensagem_com_imagem_via_covercut_e_pulada_sem_chamada_http(): void
    {
        Http::fake();
        Log::spy();

        $ticket = $this->criarTicketCovercut();

        (new SequenciaMensagemJob($ticket->id, 'Olha essa foto', 'https://exemplo.com/foto.jpg'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'mídia/botões não suportados no canal oficial'))
            ->once();
    }

    public function test_mensagem_via_covercut_bloqueada_por_janela_expirada_nao_persiste_mensagem(): void
    {
        Http::fake();
        Log::spy();

        $ticket = $this->criarTicketCovercut(['janela_expira_em' => now()->subHour()]);

        (new SequenciaMensagemJob($ticket->id, 'Ainda por aí?'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_envio_via_uazapi_continua_inalterado_com_canal_uazapi(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'tok-do-canal'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988888888']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Oi, tudo bem?'))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'tok-do-canal'));

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Oi, tudo bem?']);
    }
}
