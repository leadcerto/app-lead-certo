<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
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

    public function test_envia_botoes_depois_da_mensagem_quando_enviarbotoes_e_true(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'servico_agendado'],
            ],
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Última mensagem', null, 'aguardando_lead', true))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/menu'));
    }

    public function test_nao_envia_botoes_quando_enviarbotoes_e_false(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $contato = Contato::factory()->create(['telefone' => '5511999999999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'aguardando_lead', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'button_settings' => [
                ['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'servico_agendado'],
            ],
        ]);

        (new SequenciaMensagemJob($ticket->id, 'Mensagem do meio', null, 'aguardando_lead', false))
            ->handle(app(HumanizacaoService::class), app(UazapiService::class));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send/menu'));
    }
}
