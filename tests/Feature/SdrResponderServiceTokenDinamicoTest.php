<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\SdrPersona;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\OpenRouterService;
use App\Services\SdrResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SdrResponderServiceTokenDinamicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_de_coluna_renomeada_move_o_ticket_e_aplica_etapa_configurada(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        KanbanColuna::where('kanban_id', $kanban->id)->where('chave', 'aguardando_orcamento')
            ->update(['chave' => 'esperando_preco']);
        // O tenant de teste (via factory) só ganha as colunas do Kanban (kanban_colunas);
        // a config por coluna (kanban_coluna_configs) não é auto-seedada — precisa ser
        // criada explicitamente, como em outros testes deste projeto (ex: ListaItensImagemTest).
        KanbanColunaConfig::create([
            'tenant_id'         => $tenant->id,
            'coluna_kanban'     => 'esperando_preco',
            'etapa_ia_ao_mover' => 'handoff',
        ]);

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, já te retorno! [ESPERANDO_PRECO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('esperando_preco', $ticket->coluna_kanban);
        $this->assertSame('handoff', $ticket->etapa_ia);
    }

    public function test_token_de_coluna_padrao_nao_renomeada_le_etapa_ia_ao_mover_configurada_por_coluna(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // Tenant "de fábrica", sem nenhuma renomeação de coluna — reproduz o cenário
        // de produção coberto pelo TenantSetupService/backfill: cada coluna padrão tem
        // sua própria config com o etapa_ia_ao_mover que o mapeamento hardcoded antigo usava
        // (handoff para aguardando_orcamento/servico_agendado/encerrado, etapa_1 pro resto).
        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);

        $vincularConfig = function (string $chave, string $etapaIaAoMover) use ($tenant): void {
            $coluna = KanbanColuna::where('tenant_id', $tenant->id)->where('chave', $chave)->firstOrFail();
            KanbanColunaConfig::create([
                'tenant_id'         => $tenant->id,
                'coluna_kanban'     => $chave,
                'kanban_coluna_id'  => $coluna->id,
                'etapa_ia_ao_mover' => $etapaIaAoMover,
            ]);
        };

        $vincularConfig('aguardando_orcamento', 'handoff');
        $vincularConfig('servico_agendado', 'handoff');
        $vincularConfig('encerrado', 'handoff');
        $vincularConfig('em_atendimento', 'etapa_1');

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Perfeito, já te retorno! [AGUARDANDO_ORCAMENTO]');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        $this->assertSame('aguardando_orcamento', $ticket->coluna_kanban);
        $this->assertSame('handoff', $ticket->etapa_ia);
    }

    public function test_rede_de_seguranca_fecha_ticket_ainda_na_coluna_de_encerramento_renomeada_sem_token_de_movimento(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        // Franqueado renomeou a coluna de Encerramento de 'encerrado' para 'finalizado'
        KanbanColuna::where('kanban_id', $kanban->id)->where('papel', PapelColunaKanban::Encerramento)
            ->update(['chave' => 'finalizado']);

        $persona = SdrPersona::create([
            'tenant_id' => $tenant->id, 'nome_interno' => 'padrao', 'nome_display' => 'Joao',
            'system_prompt' => 'Você é um atendente.', 'ativo' => true, 'is_default' => true, 'tier' => 'simples',
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511988887777']);
        // Ticket já na coluna de Encerramento renomeada, mas com status ainda 'aberto'
        // (simula o ticket chegando aqui por algum caminho que não passou pelo webhook).
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'finalizado', 'agente_responsavel' => 'bot', 'status' => 'aberto',
            'aberto_em' => now(), 'sdr_persona_id' => $persona->id, 'etapa_ia' => 'etapa_1',
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('De nada, qualquer coisa é só chamar!');
        });

        app(SdrResponderService::class)->responder($ticket);

        $ticket->refresh();
        // Sem o fix, a rede de segurança comparava literalmente com 'encerrado' e nunca
        // disparava pra uma coluna de Encerramento renomeada, deixando o ticket 'aberto'.
        $this->assertSame('encerrado', $ticket->status);
    }
}
