<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SequenciaVariacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_variacoes_com_protegida_primeiro(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Oi!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);
        $humana = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes");

        $response->assertOk();
        $response->assertJsonPath('0.id', $humana->id);
        $response->assertJsonCount(7);
    }

    public function test_404_quando_mensagem_e_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user    = $this->criarUsuarioDono($tenantA);
        $sequenciaB = Sequencia::create(['tenant_id' => $tenantB->id, 'nome' => 'X', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msgB = SequenciaMensagem::create([
            'tenant_id' => $tenantB->id, 'sequencia_id' => $sequenciaB->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequenciaB->id}/mensagens/{$msgB->id}/variacoes");

        $response->assertNotFound();
    }

    /**
     * Pedido do Leonardo (2026-08-21): botão pra pedir uma nova versão de UMA
     * variação específica, sem regenerar todas as 6.
     */
    public function test_regenerar_uma_variacao_com_ia(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $variacao = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá {nome}!', 'origem' => 'humano', 'protegida' => false, 'ativa' => false,
        ]);
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['conteudo' => 'E aí {nome}, tudo certo?'])]]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $response = $this->actingAs($user)
            ->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$variacao->id}/regenerar");

        $response->assertOk();
        $this->assertSame('E aí {nome}, tudo certo?', $variacao->fresh()->conteudo);
        $this->assertSame('ia', $variacao->fresh()->origem);
    }

    public function test_nao_deixa_regenerar_a_variacao_protegida(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $protegida = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);
        Http::fake();

        $response = $this->actingAs($user)
            ->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}/regenerar");

        $response->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame('Olá!', $protegida->fresh()->conteudo);
    }

    public function test_regenerar_uma_com_ia_indisponivel_retorna_erro_sem_alterar_conteudo(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $variacao = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Texto original', 'origem' => 'humano', 'protegida' => false, 'ativa' => false,
        ]);
        Http::fake(['openrouter.ai/*' => Http::response('erro', 500)]);

        $response = $this->actingAs($user)
            ->postJson("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$variacao->id}/regenerar");

        $response->assertStatus(503);
        $this->assertSame('Texto original', $variacao->fresh()->conteudo);
    }
}
