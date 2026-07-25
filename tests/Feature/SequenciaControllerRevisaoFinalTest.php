<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes dos 3 achados (Important) da revisão final de branch inteira da Fase 1
 * de variações de mensagem:
 *
 * 1) GET mensagens() não devolvia delay_jitter_segundos (jitter zerado ao editar).
 * 2) updateVariacao() não bloqueava mudança de `conteudo` em variação protegida.
 * 3) updateMensagem() não sincronizava o novo `conteudo` na variação protegida.
 */
class SequenciaControllerRevisaoFinalTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    private function criarSequencia(Tenant $tenant): Sequencia
    {
        return Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
    }

    // ── Achado 1 ──────────────────────────────────────────────────────────────

    public function test_get_mensagens_inclui_delay_jitter_segundos(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 10, 'delay_jitter_segundos' => 45, 'ativo' => true,
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/sequencias/{$sequencia->id}/mensagens");

        $response->assertOk();
        $payload = collect($response->json())->firstWhere('id', $msg->id);
        $this->assertNotNull($payload);
        $this->assertArrayHasKey('delay_jitter_segundos', $payload);
        $this->assertSame(45, $payload['delay_jitter_segundos']);
    }

    // ── Achado 2 ──────────────────────────────────────────────────────────────

    public function test_bloqueia_edicao_de_conteudo_de_variacao_protegida(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $protegida = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}",
            ['conteudo' => 'texto alterado']
        );

        $response->assertStatus(422);
        $this->assertSame('Olá!', $protegida->fresh()->conteudo);
    }

    // ── Achado 3 ──────────────────────────────────────────────────────────────

    public function test_editar_conteudo_da_mensagem_sincroniza_variacao_protegida(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $protegida = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}",
            ['conteudo' => 'Olá, tudo bem?', 'delay_segundos' => 0]
        );

        $response->assertOk();
        $this->assertSame('Olá, tudo bem?', $msg->fresh()->conteudo);
        $this->assertSame('Olá, tudo bem?', $protegida->fresh()->conteudo);
    }
}
