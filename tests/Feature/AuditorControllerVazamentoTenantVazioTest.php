<?php

namespace Tests\Feature;

use App\Models\AuditoriaContato;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-10-10 (Leonardo navegando como dono de um tenant novo,
 * "Imóveis da Caixa", sem nenhum contato vinculado ainda): a tela de
 * Auditoria mostrava "Total Contatos: 29748" — a base INTEIRA da
 * plataforma — e a lista "Telefones com Erro" mostrava um contato de
 * outro tenant (Angola, "Sem Nome"). Causa: quando VinculoContatoTenant
 * do tenant está vazio, o código caía num fallback "mostra tudo" em vez
 * de mostrar zero.
 */
class AuditorControllerVazamentoTenantVazioTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_stats_de_tenant_sem_vinculo_nenhum_nao_conta_contatos_de_outro_tenant(): void
    {
        $tenantSemLeads = Tenant::factory()->create();
        $outroTenant    = Tenant::factory()->create();
        $user           = $this->criarUsuarioDono($tenantSemLeads);

        // Contatos de OUTRO tenant, vinculados a ele — nunca deveriam aparecer
        // pro tenant sem vínculo nenhum.
        $contatoDeOutro = Contato::factory()->create(['nome' => '', 'telefone' => '']);
        VinculoContatoTenant::create(['contato_id' => $contatoDeOutro->id, 'tenant_id' => $outroTenant->id]);

        $response = $this->actingAs($user)->getJson('/api/painel/auditor/stats');

        $response->assertOk();
        $response->assertJsonPath('total', 0);
        $response->assertJsonPath('sem_nome', 0);
        $response->assertJsonPath('sem_telefone', 0);
    }

    public function test_telefones_invalidos_de_tenant_sem_vinculo_nenhum_nao_mostra_contato_de_outro_tenant(): void
    {
        $tenantSemLeads = Tenant::factory()->create();
        $outroTenant    = Tenant::factory()->create();
        $user           = $this->criarUsuarioDono($tenantSemLeads);

        $contatoDeOutro = Contato::factory()->create();
        VinculoContatoTenant::create(['contato_id' => $contatoDeOutro->id, 'tenant_id' => $outroTenant->id]);
        AuditoriaContato::create([
            'contato_id' => $contatoDeOutro->id, 'tipo' => 'telefone', 'campo' => 'telefone',
            'valor_original' => '244929862903', 'status' => 'pendente',
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/auditor/telefones-invalidos');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('total', 0);
    }

    public function test_base_geral_de_contatos_de_tenant_sem_vinculo_nenhum_nao_lista_contato_de_outro_tenant(): void
    {
        $tenantSemLeads = Tenant::factory()->create();
        $outroTenant    = Tenant::factory()->create();
        $user           = $this->criarUsuarioDono($tenantSemLeads);

        $contatoDeOutro = Contato::factory()->create();
        VinculoContatoTenant::create(['contato_id' => $contatoDeOutro->id, 'tenant_id' => $outroTenant->id]);

        $response = $this->actingAs($user)->getJson('/api/painel/auditor/contatos');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
