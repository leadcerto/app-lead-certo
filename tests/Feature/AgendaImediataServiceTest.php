<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Services\AgendaImediataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaImediataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignora_tickets_em_coluna_de_papel_encerramento_mesmo_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'humano', 'status' => 'encerrado', 'aberto_em' => now(),
        ]);
        // Mensagem do lead há mais de 15min sem resposta — sem a correção de
        // papel, este ticket apareceria em "urgentes" mesmo estando encerrado.
        \App\Models\Mensagem::create([
            'tenant_id' => $tenant->id, 'ticket_id' => $ticket->id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Última msg do lead',
            'enviado_em' => now()->subMinutes(30),
        ]);

        $agenda = app(AgendaImediataService::class)->getAgenda($user);

        $this->assertSame([], $agenda['urgentes']);
    }

    public function test_conta_novos_leads_na_coluna_de_papel_entrada_mesmo_renomeada(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'novo_contato']);
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();
        TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo_contato', 'agente_responsavel' => 'bot', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $agenda = app(AgendaImediataService::class)->getAgenda($user);

        $this->assertCount(1, $agenda['hoje']);
        $this->assertSame('1 lead novo', $agenda['hoje'][0]['titulo']);
    }
}
