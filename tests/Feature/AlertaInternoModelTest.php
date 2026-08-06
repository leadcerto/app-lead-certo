<?php
// tests/Feature/AlertaInternoModelTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_alerta_sem_ticket_com_casts_corretos(): void
    {
        $tenant = Tenant::factory()->create();

        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'monitoramento_coluna',
            'titulo'    => '3 leads travados na coluna Orçamento',
            'conteudo'  => 'Nenhuma movimentação há mais de 2 dias.',
        ]);

        $this->assertNull($alerta->fresh()->ticket_id);
        $this->assertNull($alerta->fresh()->lido_em);
    }

    public function test_cria_alerta_vinculado_a_ticket_e_marca_lido(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'duvida_ia', 'titulo' => 'Preço fora da tabela',
            'conteudo' => 'O lead perguntou sobre um item que não está na tabela de preços.',
        ]);

        $alerta->update(['lido_em' => now()]);

        $this->assertSame($ticket->id, $alerta->fresh()->ticket_id);
        $this->assertNotNull($alerta->fresh()->lido_em);
    }

    public function test_alerta_sobrevive_a_exclusao_do_ticket(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        $alerta = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'tipo' => 'migracao_coluna', 'titulo' => 'Migrou de coluna', 'conteudo' => 'x',
        ]);

        $ticket->delete();

        $this->assertNull($alerta->fresh()->ticket_id);
        $this->assertNotNull(AlertaInterno::find($alerta->id));
    }
}
