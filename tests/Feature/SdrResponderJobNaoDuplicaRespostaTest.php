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
use Tests\TestCase;

/**
 * Achado real 24/09 (Leonardo, tickets #4907 "Rodrigo Sani" e #4908 "Jairo
 * Jr"): quando o lead manda várias mensagens seguidas rapidinho, cada uma
 * despacha seu próprio SdrResponderJob com seu próprio temporizador de
 * debounce. Cada um checa "existe mensagem do lead mais nova que a minha
 * janela de debounce" — mas como todos miram a MESMA mensagem final do lead,
 * mais de um pode concluir "não é obsoleto" quase ao mesmo tempo e cada um
 * chama a IA e manda sua própria resposta, gerando 2-4 respostas quase
 * simultâneas e parecidas (não idênticas, porque cada uma é uma chamada de
 * LLM independente). Ex. real: "Valeu, Rodrigo! ...✌️" e "...🙌😊" mandadas
 * com 1-3s de diferença. Fix: antes de chamar a IA, confere deterministicamente
 * se já existe uma mensagem do bot mais recente que a última mensagem do
 * lead — se sim, alguém já respondeu a essa leva, não responde de novo.
 */
class SdrResponderJobNaoDuplicaRespostaTest extends TestCase
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

    public function test_nao_chama_a_ia_de_novo_quando_outra_execucao_ja_respondeu_a_mesma_leva(): void
    {
        $ticket = $this->criarTicket();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Obg, bom domingo',
            'enviado_em' => now()->subSeconds(60), // fora da janela de debounce
        ]);

        // Simula uma primeira execução (job "irmão" da mesma leva) que já
        // respondeu antes desta rodar.
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Valeu! Bom domingo pra você também! ✌️',
            'enviado_em' => now()->subSeconds(5),
        ]);

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldNotReceive('responder');

        (new SdrResponderJob($ticket->id, 'Obg, bom domingo'))->handle($mock);
    }

    public function test_responde_normalmente_quando_e_mensagem_nova_de_verdade(): void
    {
        $ticket = $this->criarTicket();

        // Bot respondeu a uma mensagem ANTERIOR do lead...
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi! Como posso ajudar?',
            'enviado_em' => now()->subMinutes(5),
        ]);
        // ...e o lead mandou uma mensagem NOVA depois disso, ainda sem resposta.
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Quero fazer um orçamento',
            'enviado_em' => now()->subSeconds(60),
        ]);

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldReceive('responder')->once();

        (new SdrResponderJob($ticket->id, 'Quero fazer um orçamento'))->handle($mock);
    }
}
