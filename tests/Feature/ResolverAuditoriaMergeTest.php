<?php

namespace Tests\Feature;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolverAuditoriaMergeTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioEVinculo(Tenant $tenant, Contato $contato): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        return $user;
    }

    public function test_resolver_auditoria_sem_conflito_apenas_atualiza_o_campo(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['telefone' => '991945918', 'nome' => 'Cleiton Goncalves']);
        $user    = $this->criarUsuarioEVinculo($tenant, $contato);

        $auditoria = AuditoriaContato::create([
            'contato_id' => $contato->id, 'tipo' => 'telefone', 'campo' => 'telefone',
            'valor_original' => '991945918', 'status' => 'pendente',
        ]);

        $response = $this->actingAs($user)->postJson("/contatos/auditoria/{$auditoria->id}/resolver", [
            'valor_novo' => '5521999998888',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'contato_id' => $contato->id]);
        $this->assertSame('5521999998888', $contato->fresh()->telefone);
        $this->assertSame('resolvido', $auditoria->fresh()->status);
    }

    public function test_resolver_auditoria_com_telefone_ja_existente_mescla_os_contatos(): void
    {
        $tenant   = Tenant::factory()->create();
        $antigo   = Contato::factory()->create(['telefone' => '991945918', 'nome' => 'Cleiton Goncalves']);
        $canonico = Contato::factory()->create(['telefone' => '5521991945918', 'nome' => 'Sem Nome']);
        $user     = $this->criarUsuarioEVinculo($tenant, $antigo);

        $auditoria = AuditoriaContato::create([
            'contato_id' => $antigo->id, 'tipo' => 'telefone', 'campo' => 'telefone',
            'valor_original' => '991945918', 'status' => 'pendente',
        ]);

        $response = $this->actingAs($user)->postJson("/contatos/auditoria/{$auditoria->id}/resolver", [
            'valor_novo' => '5521991945918',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'contato_id' => $canonico->id]);

        $this->assertTrue($antigo->fresh()->trashed());
        $canonico->refresh();
        $this->assertSame('Cleiton Goncalves', $canonico->nome);
        $this->assertSame('5521991945918', $canonico->telefone);
        $this->assertSame('resolvido', $auditoria->fresh()->status);
    }
}
