<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContatoNomeDoMeioSempreOIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_nome_do_meio_e_sempre_o_id_do_contato_mesmo_com_lixo_no_banco(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521999990001']);

        // Simula um registro antigo/corrompido com outra coisa salva na coluna.
        \Illuminate\Support\Facades\DB::table('contatos')
            ->where('id', $contato->id)
            ->update(['nome_do_meio' => 'BAU-0300']);

        $this->assertSame((string) $contato->id, $contato->fresh()->nome_do_meio);
    }

    public function test_mass_assignment_nao_consegue_sobrescrever_nome_do_meio(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521999990002']);

        $contato->update(['nome_do_meio' => 'valor-arbitrario']);

        $this->assertSame((string) $contato->id, $contato->fresh()->nome_do_meio);
    }

    public function test_endpoint_de_atualizar_contato_ignora_nome_do_meio_enviado_no_payload(): void
    {
        Queue::fake();

        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        $contato = Contato::factory()->create(['telefone' => '5521999990003', 'nome' => 'Nome Antigo']);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson("/api/painel/contato/{$contato->id}", [
            'nome'         => 'Nome Novo',
            'nome_do_meio' => 'valor-forjado-pela-requisicao',
        ]);

        $response->assertOk();
        $contato->refresh();
        $this->assertSame('Nome Novo', $contato->nome);
        $this->assertSame((string) $contato->id, $contato->nome_do_meio);
    }
}
