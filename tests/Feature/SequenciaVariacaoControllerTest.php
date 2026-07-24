<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertJsonCount(2);
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
}
