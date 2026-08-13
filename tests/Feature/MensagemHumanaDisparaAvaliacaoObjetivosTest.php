<?php

namespace Tests\Feature;

use App\Jobs\AvaliarObjetivosPorMensagemHumanaJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MensagemHumanaDisparaAvaliacaoObjetivosTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComObjetivoPendente(): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true,
        ]);

        return $ticket;
    }

    public function test_mensagem_humana_com_objetivo_pendente_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_de_bot_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_mensagem_de_lead_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_checklist_ja_completa_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket  = $this->criarTicketComObjetivoPendente();
        $objId   = KanbanColunaObjetivo::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->value('id');
        $ticket->update(['objetivos_cumpridos' => [$objId]]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Beleza',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_ia_ativo_desligado_na_coluna_nao_despacha_o_job(): void
    {
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->update(['ia_ativo' => false]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    public function test_sem_config_de_coluna_nao_despacha_o_job(): void
    {
        // Achado real desta sessão: ausência de config equivale a IA
        // desativada em todos os outros automatismos do Kanban (mesmo
        // padrão de FollowupConversas) — este job segue a mesma regra.
        Queue::fake();
        $ticket = $this->criarTicketComObjetivoPendente();
        KanbanColunaConfig::where('tenant_id', $ticket->tenant_id)->where('coluna_kanban', 'em_atendimento')->delete();

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Endereço anotado',
            'enviado_em' => now(),
        ]);

        Queue::assertNotPushed(AvaliarObjetivosPorMensagemHumanaJob::class);
    }

    /**
     * Paridade entre canais (regra fundamental do CLAUDE.md): confirma que o
     * hook único cobre de fato os três pontos reais de criação de mensagem
     * humana, não só documenta a intenção. Testa disparando o webhook/
     * endpoint real de cada canal, não chamando Mensagem::create() direto.
     */
    public function test_mensagem_humana_via_uazapi_webhook_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create([
            'uazapi_webhook_token' => 'wh-objetivo-uazapi', 'uazapi_instance_token' => 'inst-objetivo-uazapi',
        ]);
        WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'webhook_token' => 'wh-objetivo-uazapi',
            'config' => ['instance_token' => 'inst-objetivo-uazapi'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511911112222']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);

        // Mensagem enviada pelo atendente direto no app do celular (fromMe,
        // sem viaApi) — mesmo formato usado em UazapiWebhookController pra
        // detectar mensagem humana (ver transferirParaHumano()).
        $this->postJson('/api/webhook/uazapi/wh-objetivo-uazapi', [
            'EventType' => 'messages',
            'message'   => [
                'fromMe'  => true,
                'isGroup' => false,
                'chatid'  => '5511911112222@s.whatsapp.net',
                'text'    => 'Endereço anotado, obrigado',
            ],
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_humana_via_covercut_echo_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '999888', 'webhook_secret' => 'segredo-objetivo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511933334444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);

        // event precisa ser 'echo' (não 'message') pra bater com o branch real do
        // controller: elseif ($event === 'echo' && $direction === 'outbound' &&
        // $echo_source === 'phone') — ver CovercutWebhookController::handle().
        $payload = [
            'event' => 'echo', 'direction' => 'outbound', 'from_number_id' => '999888',
            'echo_source' => 'phone',
            'contact' => ['wa_id' => '5511933334444'],
            'message' => ['id' => 'wamid.objetivo1', 'type' => 'text', 'text' => 'Endereço anotado'],
        ];
        $body = json_encode($payload);
        $assinatura = hash_hmac('sha256', $body, 'segredo-objetivo');

        $this->call('POST', '/api/webhook/covercut', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X-BSP-Signature' => $assinatura,
        ], $body);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }

    public function test_mensagem_humana_via_painel_despacha_o_job(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok-painel-objetivo']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok-painel-objetivo']]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento',
            'texto' => 'Endereço', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaConfig::create(['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento', 'ia_ativo' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $this->actingAs($user)->postJson("/api/painel/kanban/ticket/{$ticket->id}/mensagem", [
            'conteudo' => 'Endereço anotado, valeu!',
        ]);

        Queue::assertPushed(AvaliarObjetivosPorMensagemHumanaJob::class, fn ($job) => $job->ticketId === $ticket->id);
    }
}
