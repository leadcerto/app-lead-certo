<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillCamposEditadosHumanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marca_como_humano_todo_campo_ja_preenchido_e_real(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'nome' => 'Marcia Souza', 'sobrenome' => 'Souza', 'empresa' => 'Fretes ABC', 'email' => 'm@x.com',
        ]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        // Roda de novo a migration específica do backfill isoladamente —
        // RefreshDatabase já rodou todas as migrations (incluindo esta) antes
        // do teste, então o dado acima foi criado DEPOIS do backfill já ter
        // rodado uma vez com a tabela vazia. Simula o cenário real (dado
        // existente antes do deploy) rodando o backfill de novo agora.
        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php', '--force' => true]);

        $vinculo->refresh();

        $this->assertArrayHasKey('nome', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('sobrenome', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('email', $vinculo->campos_editados_humano);
    }

    public function test_backfill_nao_marca_campo_vazio_ou_placeholder(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'nome' => 'Sem Nome', 'sobrenome' => null, 'empresa' => null, 'email' => null,
        ]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php', '--force' => true]);

        $vinculo->refresh();

        $this->assertNull($vinculo->campos_editados_humano);
    }
}
