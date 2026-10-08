<?php

namespace Tests\Feature;

use App\Enums\PapelColunaKanban;
use App\Models\Kanban;
use App\Models\KanbanColuna;
use App\Models\Contato;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_meta_ads_cria_ticket_na_coluna_de_entrada_real_com_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();

        // Muda a coluna de entrada do tenant pra uma chave diferente de 'novo_lead',
        // exatamente o cenário que hoje quebra silenciosamente.
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
            'facebook_page_id'  => 'pagina-fake-123',
            'nome'              => 'Página Teste',
            'page_access_token' => 'token-fake',
            'ativo'             => true,
        ]);

        MetaContaInstagram::create([
            'tenant_id'              => $tenant->id,
            'meta_pagina_id'         => $pagina->id,
            'instagram_business_id'  => '17841400000000000',
            'username'               => 'imoveiscaixa',
            'nome'                   => 'Imóveis Caixa',
            'ativo'                  => true,
            'principal'              => true,
        ]);

        // Pré-cria o Contato que o controller vai procurar por 'observacoes' — evita um
        // bug pré-existente e não relacionado a este plano (Contato::create() nesse
        // mesmo método não grava 'telefone', que é NOT NULL no schema; fora de escopo
        // aqui, reportado à parte). Isso mantém o teste focado só no que esta task muda.
        Contato::factory()->create([
            'telefone'    => '5511999999999',
            'observacoes' => 'meta_user_id:9988776655 | plataforma:instagram',
        ]);

        $payload = [
            'object' => 'instagram',
            'entry'  => [
                [
                    'id'        => '17841400000000000',
                    'messaging' => [
                        [
                            'sender'  => ['id' => '9988776655'],
                            'message' => ['text' => 'Oi, quero saber sobre imóveis'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/webhooks/meta', $payload)->assertOk();

        $ticket = TicketAtendimento::where('tenant_id', $tenant->id)->latest()->first();
        $this->assertNotNull($ticket);
        $this->assertSame('entrada_customizada', $ticket->coluna_kanban);
        $this->assertSame($kanban->id, $ticket->kanban_id);
    }
}
