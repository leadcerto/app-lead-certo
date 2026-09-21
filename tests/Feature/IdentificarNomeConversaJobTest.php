<?php

namespace Tests\Feature;

use App\Jobs\IdentificarNomeConversaJob;
use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real (2026-08-14): quando o lead se identifica pelo próprio nome
 * dentro da conversa (texto ou áudio transcrito), sem o bot ter perguntado
 * diretamente, nada capturava isso — o contato ficava com o telefone como
 * nome (placeholder da criação) até alguém corrigir manualmente.
 */
class IdentificarNomeConversaJobTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicketComContato(string $nomeAtual): array
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['telefone' => '5521988887777', 'nome' => $nomeAtual]);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        return [$ticket, $contato];
    }

    public function test_extrai_e_salva_nome_quando_lead_se_identifica(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777'); // placeholder = telefone
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo'  => 'Oi, meu nome é Flávia Moura, gostaria de saber o valor do frete.',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Flávia Moura');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('Flávia Moura', $contato->fresh()->nome);
        $this->assertNotNull($contato->fresh()->nome_revisado_ia_em);
    }

    public function test_nao_atualiza_quando_ia_retorna_nenhum(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo'  => 'Quanto custa a mudança?',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('NENHUM');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('5521988887777', $contato->fresh()->nome);
    }

    public function test_nao_atualiza_quando_contato_ja_tem_nome_valido(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('João Já Salvo');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo'  => 'Meu nome é Outra Pessoa',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->never();
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('João Já Salvo', $contato->fresh()->nome);
    }

    public function test_rejeita_nome_com_emoji(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Maria 😊');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('5521988887777', $contato->fresh()->nome);
    }

    public function test_rejeita_texto_longo_tipo_frase(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Eu sou a pessoa que ligou mais cedo hoje de manhã pedindo orçamento');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('5521988887777', $contato->fresh()->nome);
    }

    public function test_rejeita_nome_de_empresa(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Extintores Companhia Ltda');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('5521988887777', $contato->fresh()->nome);
    }

    public function test_nao_atualiza_quando_ia_falha(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'oi',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(null);
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('5521988887777', $contato->fresh()->nome);
    }

    public function test_funciona_com_transcricao_de_audio(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('Sem Nome');
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'audio',
            'conteudo'  => '[Áudio transcrito: Olá, bom dia, meu nome é Carlos Eduardo, gostaria de um orçamento]',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Carlos Eduardo');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('Carlos Eduardo', $contato->fresh()->nome);
    }

    /**
     * Achado real 2026-09-21 (ticket #4816, Mateus): o avanço automático da
     * coluna "Novo" dependia inteiramente da própria IA lembrar de colar a
     * tag [ATENDIMENTO] no texto — sem nenhuma verificação de apoio no
     * código. Confirmado com evidência real de produção: a IA rodou 3 vezes
     * nesse ticket, capturou o nome e seguiu a conversa normalmente, mas
     * esqueceu de incluir a tag nas 3 vezes — o card ficou preso em "Novo"
     * indefinidamente, mesmo o objetivo "Nome do cliente confirmado" tendo
     * sido claramente cumprido. Este job já é o ÚNICO lugar do sistema que
     * sabe, com certeza determinística (sem depender de LLM nenhuma),
     * exatamente o momento em que um nome real foi capturado — por isso é o
     * ponto certo pra também marcar esse objetivo específico via
     * AvancoAutomaticoKanbanService, como rede de segurança que não depende
     * da IA lembrar de nada.
     */
    public function test_marca_objetivo_de_nome_confirmado_quando_coluna_atual_tem_esse_objetivo(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Nome do cliente confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Mateus',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Mateus');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_nao_avanca_coluna_se_ainda_houver_outro_objetivo_pendente(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Nome do cliente confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        // Achado real 2026-09-21 (ticket #4821, Lucas): "Início de conversa"
        // também virou um objetivo deterministicamente marcável (a própria
        // mensagem do lead já prova isso) — trocado aqui por um objetivo
        // genuinamente não-determinístico, pra continuar testando o caso
        // real de "ainda falta algo que só a IA/humano resolve".
        KanbanColunaObjetivo::create([
            'tenant_id' => $ticket->tenant_id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Endereço de origem confirmado', 'ordem' => 2, 'ativo' => true,
        ]);
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Mateus',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Mateus');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
        $objId = KanbanColunaObjetivo::where('tenant_id', $ticket->tenant_id)
            ->where('texto', 'Nome do cliente confirmado')->value('id');
        $this->assertContains($objId, $ticket->fresh()->objetivos_cumpridos ?? []);
    }

    public function test_nao_quebra_quando_coluna_atual_nao_tem_objetivo_de_nome(): void
    {
        [$ticket, $contato] = $this->criarTicketComContato('5521988887777');
        // Nenhum KanbanColunaObjetivo cadastrado pra "lead_novo" neste tenant.
        $mensagem = Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Mateus',
            'enviado_em' => now(),
        ]);

        $this->mock(OpenRouterService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Mateus');
        });

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class), app(AvancoAutomaticoKanbanService::class));

        $this->assertSame('Mateus', $contato->fresh()->nome);
        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
    }
}
