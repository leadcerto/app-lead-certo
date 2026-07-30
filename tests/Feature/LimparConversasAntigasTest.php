<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Substitui o antigo prazo único global (tenants.retencao_conversas_dias,
 * contava por 'updated_at' de QUALQUER status) por um prazo configurável por
 * coluna, contando só a partir do fechamento real do ticket (encerrado_em) e
 * só para tickets já ENCERRADOS — nunca um atendimento em aberto, mesmo que
 * esteja quieto há muito tempo.
 */
class LimparConversasAntigasTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(Tenant $tenant, string $coluna, string $status, ?\Illuminate\Support\Carbon $encerradoEm = null): TicketAtendimento
    {
        $contato = Contato::factory()->create();

        return TicketAtendimento::create([
            'tenant_id'           => $tenant->id,
            'contato_id'          => $contato->id,
            'coluna_kanban'       => $coluna,
            'agente_responsavel'  => 'bot',
            'status'              => $status,
            'aberto_em'           => now()->subDays(200),
            'encerrado_em'        => $encerradoEm,
        ]);
    }

    public function test_deleta_ticket_encerrado_antes_do_prazo_configurado_na_coluna(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 60,
        ]);
        $ticket = $this->criarTicket($tenant, 'encerrado', 'encerrado', now()->subDays(90));
        Mensagem::create(['ticket_id' => $ticket->id, 'tenant_id' => $tenant->id, 'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi']);

        $this->artisan('conversas:limpar-antigas')->assertExitCode(0);

        $this->assertDatabaseMissing('tickets_atendimento', ['id' => $ticket->id]);
        $this->assertDatabaseMissing('mensagens', ['ticket_id' => $ticket->id]);
    }

    public function test_nao_deleta_ticket_encerrado_dentro_do_prazo(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 60,
        ]);
        $ticket = $this->criarTicket($tenant, 'encerrado', 'encerrado', now()->subDays(30));

        $this->artisan('conversas:limpar-antigas');

        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticket->id]);
    }

    public function test_nunca_deleta_ticket_em_aberto_mesmo_muito_antigo(): void
    {
        // Este é o bug real do comando antigo: deletava por 'updated_at' de
        // qualquer status — um ticket aberto quieto há meses seria apagado
        // junto, perdendo um lead ativo de verdade.
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 1,
        ]);
        $ticket = $this->criarTicket($tenant, 'aguardando_lead', 'aberto', null);
        $ticket->forceFill(['updated_at' => now()->subDays(400)])->saveQuietly();

        $this->artisan('conversas:limpar-antigas');

        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticket->id]);
    }

    public function test_nao_deleta_quando_exclusao_definitiva_esta_desativada(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => false, 'exclusao_definitiva_dias' => 1,
        ]);
        $ticket = $this->criarTicket($tenant, 'encerrado', 'encerrado', now()->subDays(400));

        $this->artisan('conversas:limpar-antigas');

        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticket->id]);
    }

    public function test_respeita_prazos_diferentes_por_coluna(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 30,
        ]);
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'outros',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 200,
        ]);
        $ticketCurto = $this->criarTicket($tenant, 'encerrado', 'encerrado', now()->subDays(45));
        $ticketLongo = $this->criarTicket($tenant, 'outros', 'encerrado', now()->subDays(45));

        $this->artisan('conversas:limpar-antigas');

        $this->assertDatabaseMissing('tickets_atendimento', ['id' => $ticketCurto->id]);
        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticketLongo->id]);
    }

    public function test_dry_run_nao_apaga_nada(): void
    {
        $tenant = Tenant::factory()->create();
        KanbanColunaConfig::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'encerrado',
            'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 1,
        ]);
        $ticket = $this->criarTicket($tenant, 'encerrado', 'encerrado', now()->subDays(90));

        $this->artisan('conversas:limpar-antigas --dry-run');

        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticket->id]);
    }

    public function test_filtro_por_tenant_nao_afeta_outros_tenants(): void
    {
        $tenantAlvo = Tenant::factory()->create();
        $tenantOutro = Tenant::factory()->create();

        foreach ([$tenantAlvo, $tenantOutro] as $t) {
            KanbanColunaConfig::create([
                'tenant_id' => $t->id, 'coluna_kanban' => 'encerrado',
                'exclusao_definitiva_ativo' => true, 'exclusao_definitiva_dias' => 1,
            ]);
        }
        $ticketAlvo  = $this->criarTicket($tenantAlvo, 'encerrado', 'encerrado', now()->subDays(90));
        $ticketOutro = $this->criarTicket($tenantOutro, 'encerrado', 'encerrado', now()->subDays(90));

        $this->artisan("conversas:limpar-antigas --tenant={$tenantAlvo->id}");

        $this->assertDatabaseMissing('tickets_atendimento', ['id' => $ticketAlvo->id]);
        $this->assertDatabaseHas('tickets_atendimento', ['id' => $ticketOutro->id]);
    }
}
