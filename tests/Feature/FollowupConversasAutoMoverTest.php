<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
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
        $contato = Contato::factory()->create(['telefone' => '5511955556666']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
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
