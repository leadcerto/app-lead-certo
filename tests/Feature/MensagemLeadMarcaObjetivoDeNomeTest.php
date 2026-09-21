<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-21 (ticket #4821, Lucas): o fix anterior
 * (IdentificarNomeConversaJob marca o objetivo de nome determinística)
 * só cobre o caso de o contato TRANSICIONAR de "sem nome real" pra "com
 * nome real" DENTRO da conversa — mas quando o contato já é criado com um
 * nome que parece real desde o início (ex: pushName do WhatsApp já bate
 * com o nome verdadeiro, por coincidência ou não), semNomeReal() já
 * retorna false desde a primeira mensagem, o job nunca é despachado
 * (Mensagem::identificarNomeSeAindaInvalido() nem entra), e o objetivo
 * nunca é marcado — o ticket fica preso em "Novo" mesmo o nome estando
 * certo o tempo todo. Confirmado com evidência real: contato do Lucas
 * tinha nome='Lucas' desde a criação, nome_revisado_ia_em nunca foi
 * preenchido (prova que IdentificarNomeConversaJob nunca rodou), e o
 * ticket ficou em lead_novo por mais de 1h com a conversa inteira já
 * avançada (endereços, itens, fotos, data).
 */
class MensagemLeadMarcaObjetivoDeNomeTest extends TestCase
{
    use RefreshDatabase;

    private function criarTicket(string $nomeContato): array
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => $nomeContato]);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Nome do cliente confirmado', 'ordem' => 1, 'ativo' => true,
        ]);
        KanbanColunaObjetivo::create([
            'tenant_id' => $tenant->id, 'coluna_kanban' => 'lead_novo',
            'texto' => 'Início de conversa', 'ordem' => 2, 'ativo' => true,
        ]);

        return [$ticket, $contato];
    }

    public function test_marca_objetivo_de_nome_mesmo_quando_contato_ja_tinha_nome_real_desde_a_criacao(): void
    {
        // Simula o pushName do WhatsApp já vindo com um nome que parece real
        // (não é um placeholder tipo telefone) — semNomeReal() já é false
        // desde antes de qualquer mensagem, então IdentificarNomeConversaJob
        // nunca seria despachado.
        [$ticket, $contato] = $this->criarTicket('Lucas');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo'  => 'Irei fazer uma mudança pra outra cidade',
            'enviado_em' => now(),
        ]);

        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }

    public function test_nao_avanca_se_contato_ainda_nao_tem_nome_real(): void
    {
        [$ticket, $contato] = $this->criarTicket('5521999999999'); // placeholder = telefone

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto',
            'conteudo'  => 'Oi, bom dia',
            'enviado_em' => now(),
        ]);

        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
    }

    /**
     * Achado 2 da mesma investigação: "Início de conversa" também nunca era
     * marcado por nada no sistema — depende do mesmo token frágil da IA.
     * Mas é trivialmente verdadeiro no momento em que uma mensagem do lead
     * acabou de ser criada. Testa isoladamente com um contato SEM nome real
     * ainda, pra confirmar que os dois objetivos são avaliados de forma
     * independente (nome pendente não impede marcar início de conversa).
     */
    public function test_marca_objetivo_de_inicio_de_conversa_mesmo_sem_nome_confirmado(): void
    {
        [$ticket, $contato] = $this->criarTicket('5521999999999'); // placeholder = telefone, sem nome real

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi, bom dia',
            'enviado_em' => now(),
        ]);

        $idObjetivoInicio = KanbanColunaObjetivo::where('tenant_id', $ticket->tenant_id)
            ->where('texto', 'Início de conversa')->value('id');

        $this->assertContains($idObjetivoInicio, $ticket->fresh()->objetivos_cumpridos ?? []);
        // Coluna não avança ainda — falta o objetivo de nome.
        $this->assertSame('lead_novo', $ticket->fresh()->coluna_kanban);
    }

    public function test_nao_duplica_marcacao_em_mensagens_seguintes(): void
    {
        [$ticket, $contato] = $this->criarTicket('Lucas');

        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Oi',
            'enviado_em' => now(),
        ]);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);

        // Segunda mensagem do lead, já na nova coluna — não deve tentar marcar
        // de novo o objetivo de "nome" de lead_novo (nem quebrar).
        Mensagem::create([
            'ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'remetente' => 'lead', 'tipo' => 'texto', 'conteudo' => 'Rua Tal, 123',
            'enviado_em' => now(),
        ]);
        $this->assertSame('em_atendimento', $ticket->fresh()->coluna_kanban);
    }
}
