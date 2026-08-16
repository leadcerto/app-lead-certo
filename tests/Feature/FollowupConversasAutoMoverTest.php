<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FollowupConversasAutoMoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-11 14:00:00'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarTicketSilencioso(int $diasAtras, string $coluna = 'aguardando_orcamento'): TicketAtendimento
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => $coluna, 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 3, // já passou de todos os estágios de mensagem
        ]);

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now()->subDays($diasAtras),
        ]);

        return $ticket;
    }

    public function test_move_automaticamente_para_encerrado_e_marca_status_e_relatorios(): void
    {
        $ticket = $this->criarTicketSilencioso(4);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400, // 3 dias
        ]);

        $this->mock(SdrResponderService::class, function ($mock) {
            $mock->shouldReceive('responder')->never();
        });

        $this->artisan('conversas:followup')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertSame('encerrado', $ticket->status);
        $this->assertSame('sem_resposta_automatico', $ticket->tag_desfecho);
    }

    public function test_envia_mensagem_configurada_antes_de_mover(): void
    {
        $ticket = $this->criarTicketSilencioso(4);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
            'auto_mover_mensagem' => 'Oi {nome}, por falta de resposta estamos encerrando.',
        ]);

        $contato = $ticket->contato;
        $contato->update(['nome' => 'Marcos']);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id,
            'conteudo'  => 'Oi Marcos, por falta de resposta estamos encerrando.',
        ]);
    }

    public function test_envio_da_mensagem_de_auto_mover_usa_o_token_do_canal_do_ticket_nao_o_legado_do_tenant(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'token-legado-do-tenant']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id,
            'config'    => ['instance_token' => 'token-do-canal-certo'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 3,
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now()->subDays(4),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
            'auto_mover_mensagem' => 'Encerrando por falta de resposta.',
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/send/text')
            && $request->hasHeader('token', 'token-do-canal-certo'));
    }

    public function test_nao_move_quando_ainda_nao_atingiu_o_tempo_configurado(): void
    {
        $ticket = $this->criarTicketSilencioso(1); // só 1 dia, limite é 3

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_nao_move_quando_auto_mover_esta_desativado(): void
    {
        $ticket = $this->criarTicketSilencioso(10);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => false, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_move_para_outros_aplica_transferencia_para_humano(): void
    {
        $ticket = $this->criarTicketSilencioso(4);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'outros',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('outros', $ticket->coluna_kanban);
        $this->assertSame('humano', $ticket->agente_responsavel);
    }

    public function test_dry_run_nao_move_nada(): void
    {
        $ticket = $this->criarTicketSilencioso(10);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup --dry-run')->assertExitCode(0);

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_mover_para_coluna_de_papel_transferencia_humana_seta_agente_humano_mesmo_renomeada(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::TransferenciaHumana)
            ->update(['chave' => 'time_humano']);
        $contato = \App\Models\Contato::factory()->create();
        $ticket = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $metodo = new \ReflectionMethod(\App\Console\Commands\FollowupConversas::class, 'aplicarMovimentoAutomatico');
        $metodo->setAccessible(true);
        $metodo->invoke(
            app(\App\Console\Commands\FollowupConversas::class),
            $ticket,
            'time_humano',
            null,
            app(\App\Services\HumanizacaoService::class)
        );

        $this->assertSame('time_humano', $ticket->fresh()->coluna_kanban);
        $this->assertSame('humano', $ticket->fresh()->agente_responsavel);
    }

    /**
     * Achado Importante 5 da revisão final: aplicarMovimentoAutomatico() resolvia o
     * token via tokenUazapi() direto — sempre null pra um canal Covercut — e a
     * despedida configurada era descartada silenciosamente (ticket movia mesmo
     * assim, sem log nenhum). Roteando por $canal->servico()->enviarTexto(), o
     * Covercut passa a mandar a mensagem de verdade via POST /messages/send.
     */
    public function test_auto_mover_com_canal_covercut_envia_mensagem_via_servico_do_canal(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.despedida'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 3,
            'janela_expira_em' => now()->addHours(5),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now()->subDays(4),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
            'auto_mover_mensagem' => 'Encerrando por falta de resposta.',
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/send')
            && $request['to'] === '5511955556666'
            && $request['text']['body'] === 'Encerrando por falta de resposta.');

        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertDatabaseHas('mensagens', [
            'ticket_id' => $ticket->id,
            'conteudo'  => 'Encerrando por falta de resposta.',
        ]);
    }

    /**
     * Janela expirada no Covercut: CovercutChannelService bloqueia o envio
     * (retorna false) — o ticket ainda tem que mover, mas nenhuma chamada HTTP
     * pode acontecer e a mensagem não pode ser gravada como enviada.
     */
    public function test_auto_mover_com_canal_covercut_e_janela_expirada_nao_envia_mas_move_o_ticket(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.nao-deveria'], 200)]);

        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 3,
            'janela_expira_em' => now()->subHour(), // expirada
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'bot', 'tipo' => 'texto', 'conteudo' => 'Oi!',
            'enviado_em' => now()->subDays(4),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
            'auto_mover_mensagem' => 'Encerrando por falta de resposta.',
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        // Não usa Http::assertNothingSent(): o movimento pra 'encerrado' dispara
        // ConversationQAJob/GerarResumoTicketJob em fila sync, que chamam a IA via
        // HTTP normalmente — o que importa aqui é que NENHUMA chamada ao canal
        // (/messages/send) aconteceu, já que a janela expirou.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages/send'));

        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertDatabaseMissing('mensagens', [
            'ticket_id' => $ticket->id,
            'conteudo'  => 'Encerrando por falta de resposta.',
        ]);
    }

    /**
     * Achado real (Leonardo, 2026-08-13): o auto-mover configurado (ex: 24h/48h/
     * 72h → encerrado) nunca disparava pra ticket assumido por um humano — a
     * consulta de candidatos filtrava `agente_responsavel = 'bot'` antes mesmo de
     * checar o tempo de silêncio, deixando qualquer ticket "Humano" parado pra
     * sempre, mesmo com a config ativa. Corrigido: auto-mover agora vale pra
     * qualquer dono do ticket (bot ou humano) — só os Estágios de mensagem
     * (nudge ao lead) continuam exclusivos do bot, feito no teste seguinte.
     */
    public function test_move_automaticamente_ticket_assumido_por_humano(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'humano', 'tipo' => 'texto', 'conteudo' => 'Vou verificar e já te retorno',
            'enviado_em' => now()->subDays(4),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertSame('encerrado', $ticket->status);
    }

    /**
     * Achado real (Leonardo, 2026-08-16): tickets sem NENHUMA mensagem (ex:
     * origem='ligacao' — chamada perdida que abre um ticket mas nunca gera
     * texto) ficavam de fora da lista de candidatos pra sempre, porque o
     * INNER JOIN com a subquery de "última mensagem" não encontrava nenhuma
     * linha pra eles — 23 tickets reais da coluna "Novo" (alguns com mais de
     * 3 semanas parados) nunca eram avaliados. Corrigido pra LEFT JOIN +
     * COALESCE(última mensagem, aberto_em): sem mensagem nenhuma, o silêncio
     * conta a partir da abertura do ticket.
     */
    public function test_move_automaticamente_ticket_sem_nenhuma_mensagem_usando_aberto_em(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955558888']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'origem' => 'ligacao',
            'aberto_em' => now()->subDays(4), // ticket de chamada perdida, sem nenhuma mensagem
            'followup_estagio_enviado' => 3,
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('encerrado', $ticket->coluna_kanban);
        $this->assertSame('encerrado', $ticket->status);
    }

    public function test_nao_move_ticket_sem_mensagem_antes_do_tempo_configurado_contando_do_aberto_em(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955559999']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'bot', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'origem' => 'ligacao',
            'aberto_em' => now()->subDay(), // só 1 dia, limite é 3
            'followup_estagio_enviado' => 3,
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'auto_mover_ativo' => true, 'auto_mover_coluna_destino' => 'encerrado',
            'auto_mover_segundos' => 3 * 86400,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        $this->assertSame('aguardando_orcamento', $ticket->fresh()->coluna_kanban);
    }

    public function test_estagios_de_mensagem_continuam_exclusivos_do_bot_mesmo_apos_o_fix_do_auto_mover(): void
    {
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511955557777']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'aguardando_orcamento', 'agente_responsavel' => 'humano', 'etapa_ia' => 'etapa_1',
            'status' => 'aberto', 'aberto_em' => now(),
            'followup_estagio_enviado' => 0,
        ]);
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $tenant->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now()->subMinutes(90),
        ]);

        KanbanColunaConfig::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'aguardando_orcamento',
            'ia_ativo' => true,
        ]);

        $this->artisan('conversas:followup')->assertExitCode(0);

        // Nenhum estágio de mensagem deve ter sido marcado — o bot não "cutuca"
        // o lead num ticket que um humano já assumiu.
        $this->assertSame(0, $ticket->fresh()->followup_estagio_enviado);
    }

    public function test_mover_para_coluna_de_papel_encerramento_renomeada_encerra_o_ticket(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $kanban = \App\Models\Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        \App\Models\KanbanColuna::where('kanban_id', $kanban->id)->where('papel', \App\Enums\PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $contato = \App\Models\Contato::factory()->create();
        $ticket = \App\Models\TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $metodo = new \ReflectionMethod(\App\Console\Commands\FollowupConversas::class, 'aplicarMovimentoAutomatico');
        $metodo->setAccessible(true);
        $metodo->invoke(
            app(\App\Console\Commands\FollowupConversas::class),
            $ticket,
            'finalizado',
            null,
            app(\App\Services\HumanizacaoService::class)
        );

        $this->assertSame('finalizado', $ticket->fresh()->coluna_kanban);
        $this->assertSame('encerrado', $ticket->fresh()->status);
    }
}
