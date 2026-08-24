<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use App\Services\TraducaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaidaIdiomaOrigemDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_atendente_com_idioma_espanhol_traduz_a_partir_do_espanhol_nao_do_portugues(): void
    {
        $tenant   = Tenant::factory()->create();
        $vendedor = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true, 'idioma' => 'es-ES']);
        $canal    = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $contato  = Contato::factory()->create(['telefone' => '5511900002222']);
        $ticket   = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano', 'vendedor_id' => $vendedor->id,
            'status' => 'aberto', 'aberto_em' => now(), 'idioma_lead' => 'en',
        ]);

        // Nota: o parâmetro $idiomaOrigem passado pra traduzir() é normalizado
        // pros 2 primeiros chars (substr($idioma, 0, 2)) antes da chamada — ver
        // comentário no KanbanController::enviarMensagem(). O usuário tem
        // idioma 'es-ES' (5 chars), então o argumento esperado aqui é 'es',
        // não 'es-ES': se fosse passado por inteiro, o guard interno de
        // traduzir() ($idiomaAlvo === $idiomaOrigem) nunca curto-circuitaria
        // quando o atendente já fala o mesmo idioma do lead ('es' !== 'es-ES'
        // mesmo sendo o mesmo idioma), desperdiçando uma chamada de IA — o
        // mesmo achado da revisão da Task 5, aplicado aqui à origem.
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')->once()
                ->with('Hola, ¿cómo estás?', 'en', 'es')
                ->andReturn('Hi, how are you?');
        });
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->actingAs($vendedor)
            ->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", ['conteudo' => 'Hola, ¿cómo estás?']);

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Hi, how are you?']);
    }

    /**
     * Equivalente pro lado do bot: SdrResponderService não tem um "usuário"
     * atendente — quem escreve em nome da operação é a IA, então a origem é
     * o locale do tenant (ticket->tenant->locale), não um usuário autenticado.
     * Cobertura adicionada porque o teste dado no brief só cobre o lado
     * humano (KanbanController); sem este teste, a mudança em
     * SdrResponderService.php ficaria sem nenhuma asserção positiva de que
     * o locale do tenant realmente flui como origem dinâmica.
     */
    public function test_bot_traduz_a_partir_do_locale_do_tenant_nao_do_portugues(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant  = Tenant::factory()->create(['locale' => 'es-ES']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
            'idioma_lead' => 'en',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, vou verificar isso pra você.');
        });
        // Mesma normalização do lado humano: locale de 5 chars ('es-ES') vira
        // 'es' antes de chamar traduzir().
        $this->mock(TraducaoService::class, function ($mock) {
            $mock->shouldReceive('traduzir')->once()
                ->with('Perfeito, vou verificar isso pra você.', 'en', 'es')
                ->andReturn('Perfect, I will check that for you.');
        });

        app(SdrResponderService::class)->responder($ticket);

        $this->assertDatabaseHas('mensagens', ['ticket_id' => $ticket->id, 'conteudo' => 'Perfect, I will check that for you.']);
    }
}
