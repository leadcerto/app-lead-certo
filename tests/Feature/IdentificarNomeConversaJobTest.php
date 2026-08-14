<?php

namespace Tests\Feature;

use App\Jobs\IdentificarNomeConversaJob;
use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

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

        (new IdentificarNomeConversaJob($mensagem->id))->handle(app(OpenRouterService::class));

        $this->assertSame('Carlos Eduardo', $contato->fresh()->nome);
    }
}
