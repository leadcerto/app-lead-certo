<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real (2026-09-28, ticket #4913 + contato "Vanete"): salvar um contato
 * com telefone alterado dava "Server Error" em produção —
 * ContatosController::limparTelefone() chamava
 * \App\Console\Commands\NormalizarTelefones::normalizar(), uma classe que não
 * existe (nunca existiu com esse nome — o comando real é
 * NormalizarTelefonesCommand, e nem ele tem método estático normalizar()). O
 * normalizador correto já existe e já é usado nos webhooks:
 * App\Services\TelefoneService::normalizar().
 */
class ContatosControllerAtualizarTelefoneTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_atualizar_telefone_do_contato_nao_da_erro_e_normaliza_o_numero(): void
    {
        $user    = $this->criarUsuario();
        $contato = Contato::factory()->create(['telefone' => '5521999990000']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'telefone' => '(21) 98888-1234',
        ]);

        $response->assertOk();
        $this->assertSame('5521988881234', $contato->fresh()->telefone);
    }

    public function test_atualizar_telefone_invalido_retorna_422_em_vez_de_erro_fatal(): void
    {
        $user    = $this->criarUsuario();
        $contato = Contato::factory()->create(['telefone' => '5521999990000']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'telefone' => '123',
        ]);

        $response->assertStatus(422);
    }
}
