<?php
// tests/Feature/AlertaInternoServiceTest.php
namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AlertaInternoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_persiste_alerta_sem_ticket(): void
    {
        $tenant = Tenant::factory()->create();

        $alerta = app(AlertaInternoService::class)->criar(
            $tenant->id, 'monitoramento_coluna', 'Título', 'Conteúdo do alerta'
        );

        $this->assertDatabaseHas('alertas_internos', [
            'id' => $alerta->id, 'tenant_id' => $tenant->id,
            'ticket_id' => null, 'tipo' => 'monitoramento_coluna',
        ]);
    }

    public function test_criar_persiste_alerta_vinculado_a_ticket(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $alerta = app(AlertaInternoService::class)->criar(
            $tenant->id, 'duvida_ia', 'Título', 'Conteúdo', $ticket->id
        );

        $this->assertSame($ticket->id, $alerta->fresh()->ticket_id);
    }

    public function test_criar_trunca_titulo_e_tipo_acima_do_limite_da_coluna(): void
    {
        $tenant = Tenant::factory()->create();

        $tituloLongo = str_repeat('a', 200);
        $tipoLongo   = str_repeat('b', 80);

        $alerta = app(AlertaInternoService::class)->criar(
            $tenant->id, $tipoLongo, $tituloLongo, 'Conteúdo'
        );

        $alerta = $alerta->fresh();
        $this->assertSame(150, strlen($alerta->titulo));
        $this->assertSame(50, strlen($alerta->tipo));
    }
}
