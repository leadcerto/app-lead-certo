<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaVariacaoProtecaoTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    private function criarMensagemComVariacaoProtegida(Tenant $tenant): array
    {
        $sequencia = Sequencia::create(['tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true]);
        $msg       = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);
        $protegida = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Olá!', 'origem' => 'humano', 'protegida' => true, 'ativa' => true,
        ]);

        return [$sequencia, $msg, $protegida];
    }

    public function test_cria_variacao_manual_como_origem_humano(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->postJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes",
            ['conteudo' => 'Fala comigo!']
        );

        $response->assertCreated();
        $this->assertDatabaseHas('sequencia_mensagem_variacoes', [
            'sequencia_mensagem_id' => $msg->id,
            'conteudo'              => 'Fala comigo!',
            'origem'                => 'humano',
            'protegida'             => false,
        ]);
    }

    public function test_edita_conteudo_de_variacao_ia(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Bom te ver!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$ia->id}",
            ['conteudo' => 'Editado pelo humano']
        );

        $response->assertOk();
        $this->assertSame('Editado pelo humano', $ia->fresh()->conteudo);
    }

    public function test_bloqueia_desativar_variacao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg, $protegida] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->putJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}",
            ['ativa' => false]
        );

        $response->assertStatus(422);
        $this->assertTrue($protegida->fresh()->ativa);
    }

    public function test_bloqueia_exclusao_de_variacao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg, $protegida] = $this->criarMensagemComVariacaoProtegida($tenant);

        $response = $this->actingAs($user)->deleteJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$protegida->id}"
        );

        $response->assertStatus(422);
        $this->assertDatabaseHas('sequencia_mensagem_variacoes', ['id' => $protegida->id]);
    }

    public function test_exclui_variacao_nao_protegida(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuarioDono($tenant);
        [$sequencia, $msg] = $this->criarMensagemComVariacaoProtegida($tenant);
        $ia = SequenciaMensagemVariacao::create([
            'tenant_id' => $tenant->id, 'sequencia_mensagem_id' => $msg->id,
            'conteudo' => 'Bom te ver!', 'origem' => 'ia', 'protegida' => false, 'ativa' => true,
        ]);

        $response = $this->actingAs($user)->deleteJson(
            "/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}/variacoes/{$ia->id}"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('sequencia_mensagem_variacoes', ['id' => $ia->id]);
    }
}
