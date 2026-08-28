<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AuditorControllerCampoTest extends TestCase
{
    use RefreshDatabase;

    private function vinculoComDoisPendentes(): VinculoContatoTenant
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcia', 'empresa' => 'Transportes Silva']);

        return VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome'    => ['sugerido' => 'Marcia Souza', 'origem' => 'google'],
                'empresa' => ['sugerido' => 'Fretes ABC',  'origem' => 'google'],
            ],
        ]);
    }

    private function vinculoComPendentesEmail(): VinculoContatoTenant
    {
        Bus::fake([EnriquecerContatoNovoViaGoogleJob::class]);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['email' => 'marcia.souza@example.com']);

        return VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'email' => ['sugerido' => 'marcia.s@newexample.com', 'origem' => 'google'],
            ],
        ]);
    }

    public function test_lista_pendentes_uma_linha_por_campo(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $res = $this->actingAs($user)->getJson('/api/painel/auditor/pendentes')->assertOk();

        $campos = collect($res->json('data'))->pluck('campo')->sort()->values();
        $this->assertSame(['empresa', 'nome'], $campos->all());
    }

    public function test_aprovar_um_campo_nao_afeta_o_outro_pendente(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/nome/aprovar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Marcia Souza', $vinculo->contato->fresh()->nome);
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('empresa', $vinculo->campos_pendentes_auditoria); // intacto
        $this->assertArrayHasKey('nome', $vinculo->campos_editados_humano ?? []); // aprovar = decisão humana
    }

    public function test_rejeitar_um_campo_mantem_valor_local_e_remove_a_pendencia(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/empresa/rejeitar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->fresh()->empresa);
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('nome', $vinculo->campos_pendentes_auditoria); // intacto
    }

    public function test_email_pendente_retorna_mascarado_nao_cru(): void
    {
        $vinculo = $this->vinculoComPendentesEmail();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $res = $this->actingAs($user)->getJson('/api/painel/auditor/pendentes')->assertOk();

        $item = collect($res->json('data'))->first();

        // Email cru não deve aparecer em valor_atual
        $this->assertNotSame('marcia.souza@example.com', $item['valor_atual']);
        // Email cru não deve aparecer em valor_sugerido
        $this->assertNotSame('marcia.s@newexample.com', $item['valor_sugerido']);

        // Ambos devem estar mascarados (formato ***.***.@...)
        $this->assertStringContainsString('*', $item['valor_atual']);
        $this->assertStringContainsString('*', $item['valor_sugerido']);
    }
}
