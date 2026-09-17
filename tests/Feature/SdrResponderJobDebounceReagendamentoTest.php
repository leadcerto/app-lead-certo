<?php

namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Achado real 2026-09-17, ticket #4791 (Tiago): a IA tentou responder DUAS
 * vezes e as duas se cancelaram por debounce ("lead digitando") — mas como o
 * lead não mandou mais nenhuma mensagem depois, nada nunca reavaliava de
 * novo. A resposta simplesmente nunca saiu, até o Leonardo assumir na mão
 * ~3 minutos depois. Confirmado no log de produção
 * (LOG_LEVEL=info, achado da sessão anterior): duas linhas
 * "SdrResponderJob: debounce — lead digitando, job cancelado" seguidas de
 * "Ticket transferido para humano", sem nenhuma tentativa real de resposta
 * no meio.
 */
class SdrResponderJobDebounceReagendamentoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511977776666']);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
        ]);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    public function test_reagenda_quando_cancelado_por_debounce(): void
    {
        Bus::fake();

        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Tiago',
            'enviado_em' => now()->subSeconds(10), // dentro da janela padrão de 45s
        ]);

        (new SdrResponderJob($ticket->id, 'Tiago'))->handle(app(SdrResponderService::class));

        Bus::assertDispatched(SdrResponderJob::class, function (SdrResponderJob $job) use ($ticket) {
            return true; // qualquer redisparo já confirma o comportamento
        });
    }

    public function test_nao_reagenda_depois_do_teto_de_tentativas(): void
    {
        Bus::fake();

        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Tiago',
            'enviado_em' => now()->subSeconds(10),
        ]);

        (new SdrResponderJob($ticket->id, 'Tiago', false, false, 45, null, tentativaDebounce: 5))
            ->handle(app(SdrResponderService::class));

        Bus::assertNotDispatched(SdrResponderJob::class);
    }

    public function test_nao_reagenda_quando_nao_ha_debounce(): void
    {
        Bus::fake();

        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Tiago',
            'enviado_em' => now()->subSeconds(60), // já fora da janela de 45s
        ]);

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldReceive('responder')->once();

        (new SdrResponderJob($ticket->id, 'Tiago'))->handle($mock);

        Bus::assertNotDispatched(SdrResponderJob::class);
    }
}
