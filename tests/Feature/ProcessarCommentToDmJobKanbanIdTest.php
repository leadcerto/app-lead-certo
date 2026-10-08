<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Jobs\ProcessarCommentToDmJob;
use App\Models\Contato;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPagina;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessarCommentToDmJobKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_comentario_vira_dm_cria_ticket_na_coluna_de_entrada_real_com_kanban_id(): void
    {
        Http::fake(['*' => Http::response(['id' => 'fake-response-id'], 200)]);

        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        KanbanColuna::where('kanban_id', $kanban->id)
            ->where('papel', PapelColunaKanban::Entrada)
            ->update(['chave' => 'entrada_customizada']);

        $token = MetaToken::create([
            'tenant_id'    => $tenant->id,
            'meta_user_id' => 'user-fake-1',
            'access_token' => 'token-usuario-fake',
        ]);

        $pagina = MetaPagina::create([
            'tenant_id'         => $tenant->id,
            'meta_token_id'     => $token->id,
            'facebook_page_id'  => 'pagina-fake-comment-dm',
            'nome'              => 'Página Teste',
            'page_access_token' => 'token-fake',
            'ativo'             => true,
        ]);

        MetaCampanhaGatilho::create([
            'tenant_id'    => $tenant->id,
            'nome'         => 'Gatilho Teste',
            'canal_alvo'   => 'facebook',
            'modo_gatilho' => 'qualquer_comentario',
            'mensagem_direct' => 'Oi {primeiro_nome}, chegou sua mensagem!',
            'ativo'        => true,
        ]);

        // Pré-cria o Contato que o job vai procurar por 'observacoes' — evita o mesmo bug
        // pré-existente e não relacionado a este plano já encontrado na Task 9
        // (Contato::create() nesse job também não grava 'telefone', NOT NULL no schema).
        Contato::factory()->create([
            'telefone'    => '5511888887777',
            'observacoes' => 'meta_user_id:fromid-123 | plataforma:facebook | @leadteste',
        ]);

        // O job tenta, DEPOIS de criar o ticket, registrar a mensagem enviada com
        // remetente='sistema' — valor não aceito pelo enum da tabela `mensagens`
        // (só aceita lead/bot/humano). Bug pré-existente e não relacionado a este
        // plano (mesma classe de achado da Task 9 — este job nunca teve teste
        // antes). Capturado aqui pra isolar e testar só o que esta task muda
        // (coluna_kanban/kanban_id, já gravados nesse ponto).
        try {
            (new ProcessarCommentToDmJob(
                commentId: 'comment-1',
                postId: 'post-1',
                textoComentario: 'Quero saber mais',
                fromId: 'fromid-123',
                fromName: 'Lead Teste',
                fromUsername: 'leadteste',
                plataforma: 'facebook',
                targetId: 'pagina-fake-comment-dm',
            ))->handle(app(\App\Services\MetaService::class));
        } catch (\Illuminate\Database\QueryException $e) {
            // esperado — ver comentário acima
        }

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('entrada_customizada', $ticket->coluna_kanban);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
