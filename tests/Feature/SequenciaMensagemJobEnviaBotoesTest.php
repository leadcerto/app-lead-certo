<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaMensagemJobEnviaBotoesTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketETenant(): array
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        return [$tenant, $ticket];
    }

    public function test_envia_botoes_quando_a_mensagem_tem_button_settings(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        [, $ticket] = $this->criarTicketETenant();

        $botoes = [
            ['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'servico_agendado'],
        ];

        (new SequenciaMensagemJob($ticket->id, 'Última mensagem', null, 'aguardando_lead', $botoes))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/menu'));
    }

    public function test_nao_envia_botoes_quando_a_mensagem_nao_tem_button_settings(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        [, $ticket] = $this->criarTicketETenant();

        (new SequenciaMensagemJob($ticket->id, 'Mensagem do meio', null, 'aguardando_lead', null))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send/menu'));
    }

    public function test_o_texto_da_mensagem_e_o_conteudo_da_propria_mensagem_no_menu_de_botoes(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        [, $ticket] = $this->criarTicketETenant();

        $botoes = [
            ['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'servico_agendado'],
        ];

        (new SequenciaMensagemJob($ticket->id, 'Seu orçamento foi aprovado!', null, 'aguardando_lead', $botoes))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/send/menu')
                && $request['text'] === 'Seu orçamento foi aprovado!';
        });
    }
}
