<?php
// tests/Feature/SdrResponderServicePromptGuardrailsTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServicePromptGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComPersona(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);

        return TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(), 'sdr_persona_id' => $persona->id,
        ]);
    }

    private function capturarPrompt(TicketAtendimento $ticket): string
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $mensagensCapturadas = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$mensagensCapturadas) {
            $mock->shouldReceive('chat')->once()
                ->withArgs(function ($messages) use (&$mensagensCapturadas) {
                    $mensagensCapturadas = $messages;
                    return true;
                })
                ->andReturn('Perfeito!');
        });

        app(SdrResponderService::class)->responder($ticket);

        return $mensagensCapturadas[0]['content'];
    }

    public function test_prompt_contem_instrucao_anti_eco(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('Nunca repita literalmente', $prompt);
    }

    public function test_prompt_contem_instrucao_de_nao_re_perguntar(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('já foi dito', $prompt);
    }

    public function test_prompt_contem_instrucao_de_autovalidacao_com_token_duvida(): void
    {
        $prompt = $this->capturarPrompt($this->criarTicketComPersona());

        $this->assertStringContainsString('[DUVIDA:', $prompt);
    }

    public function test_objetivo_cumprido_aparece_marcado_junto_com_a_instrucao_de_nao_repetir(): void
    {
        // Regra 5 na prática: o bloco de objetivos (já existente) e a instrução
        // nova de "não repita perguntas" precisam aparecer juntos no mesmo
        // prompt, pra IA conseguir ligar um ao outro.
        $ticket = $this->criarTicketComPersona();
        $objetivo = \App\Models\KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => $ticket->coluna_kanban,
            'texto' => 'Endereço de origem confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        $ticket->update(['objetivos_cumpridos' => [$objetivo->id]]);

        $prompt = $this->capturarPrompt($ticket);

        $this->assertStringContainsString('✅ [id:' . $objetivo->id . '] Endereço de origem confirmado', $prompt);
        $this->assertStringContainsString('já foi dito', $prompt);
    }
}
