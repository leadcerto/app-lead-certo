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
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Achado real 02/10 (Leonardo, ticket #4999 "Mariana Pacará"): o fix de
 * duplicação de 24/09 (SdrResponderJobNaoDuplicaRespostaTest) ainda tinha uma
 * brecha de corrida — a checagem "já existe resposta do bot" roda ANTES de
 * chamar a IA (que demora alguns segundos), então dois workers podiam passar
 * pela mesma checagem quase ao mesmo tempo e os dois chamarem a IA, gerando
 * pares de mensagens no MESMO segundo (confirmado em produção: 3 pares
 * duplicados numa única conversa). Corrigido travando a seção crítica
 * inteira com `Cache::lock()` — se outra execução já está processando o
 * ticket, esta reagenda em vez de chamar a IA de novo.
 */
class SdrResponderJobCorridaTest extends TestCase
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

    public function test_quando_outra_execucao_ja_segura_a_trava_nao_chama_a_ia_e_reagenda(): void
    {
        Bus::fake();

        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi',
            'enviado_em' => now()->subSeconds(60),
        ]);

        // Simula a "execução irmã" já em andamento, segurando a trava.
        $lockConcorrente = Cache::lock("sdr-responder:{$ticket->id}", 90);
        $lockConcorrente->get();

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldNotReceive('responder');

        (new SdrResponderJob($ticket->id, 'Oi'))->handle($mock);

        Bus::assertDispatched(SdrResponderJob::class, function (SdrResponderJob $job) {
            return true; // qualquer redisparo já confirma que não tentou responder direto
        });

        $lockConcorrente->release();
    }

    public function test_nao_reagenda_depois_do_teto_de_tentativas_mesmo_com_trava_ocupada(): void
    {
        Bus::fake();

        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi',
            'enviado_em' => now()->subSeconds(60),
        ]);

        $lockConcorrente = Cache::lock("sdr-responder:{$ticket->id}", 90);
        $lockConcorrente->get();

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldNotReceive('responder');

        (new SdrResponderJob($ticket->id, 'Oi', false, false, 45, null, tentativaDebounce: 5))
            ->handle($mock);

        Bus::assertNotDispatched(SdrResponderJob::class);

        $lockConcorrente->release();
    }

    public function test_execucao_normal_libera_a_trava_no_final(): void
    {
        $ticket = $this->criarTicket();
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi',
            'enviado_em' => now()->subSeconds(60),
        ]);

        $mock = $this->mock(SdrResponderService::class);
        $mock->shouldReceive('responder')->once();

        (new SdrResponderJob($ticket->id, 'Oi'))->handle($mock);

        // Se a trava não foi liberada, este lock novo falharia ao adquirir.
        $lock = Cache::lock("sdr-responder:{$ticket->id}", 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
