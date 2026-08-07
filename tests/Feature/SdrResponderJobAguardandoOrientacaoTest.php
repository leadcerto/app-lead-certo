<?php
// tests/Feature/SdrResponderJobAguardandoOrientacaoTest.php
namespace Tests\Feature;

use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderJobAguardandoOrientacaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketAguardandoOrientacao(bool $mensagemJaEnviada = false): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
            'aguardando_orientacao_mensagem' => 'Estou verificando, já te retorno!',
        ]);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
            'aguardando_orientacao_em' => now(), 'mensagem_espera_enviada' => $mensagemJaEnviada,
        ]);
    }

    public function test_lead_escreve_durante_pausa_recebe_mensagem_de_espera_uma_vez(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        (new SdrResponderJob($ticket->id, 'oi, ainda esperando', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $this->assertTrue($ticket->fresh()->mensagem_espera_enviada);
        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id, 'remetente' => 'bot', 'conteudo' => 'Estou verificando, já te retorno!',
        ]);
    }

    public function test_lead_insiste_de_novo_nao_repete_a_mensagem_de_espera(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: true);

        $mock = $this->mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->never();

        (new SdrResponderJob($ticket->id, 'e aí?', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $this->assertSame(0, Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->count());
    }

    public function test_sem_mensagem_configurada_usa_fallback_generico(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->update(['aguardando_orientacao_mensagem' => null]);

        (new SdrResponderJob($ticket->id, 'oi', false, true))->handle(app(\App\Services\SdrResponderService::class));

        $mensagem = Mensagem::where('ticket_id', $ticket->id)->where('remetente', 'bot')->first();
        $this->assertNotNull($mensagem);
        $this->assertNotEmpty($mensagem->conteudo);
    }

    public function test_orientacao_humana_passa_direto_pro_service_sem_o_guard_de_espera(): void
    {
        // Simula o redisparo da Task 5: quem chama já limpou aguardando_orientacao_em
        // ANTES de despachar o job — o job não precisa saber sobre orientação
        // pra decidir se bloqueia; só repassa o parâmetro pro service.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $ticket = $this->criarTicketAguardandoOrientacao(mensagemJaEnviada: false);
        $ticket->update(['aguardando_orientacao_em' => null, 'mensagem_espera_enviada' => false]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Resposta com base na orientação.');
        });

        (new SdrResponderJob($ticket->id, '', false, true, 0, 'preço é R$ 250'))
            ->handle(app(\App\Services\SdrResponderService::class));

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Resposta com base na orientação.']);
    }
}
