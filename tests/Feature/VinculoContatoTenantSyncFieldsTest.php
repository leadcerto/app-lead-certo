<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinculoContatoTenantSyncFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_e_le_os_tres_campos_json_novos_como_array(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $vinculo = VinculoContatoTenant::create([
            'contato_id'                 => $contato->id,
            'tenant_id'                  => $tenant->id,
            'google_valores_enviados'    => ['nome' => 'Marcia Souza'],
            'campos_editados_humano'     => ['nome' => '2026-08-26T10:00:00-03:00'],
            'campos_pendentes_auditoria' => ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
        ]);

        $vinculo->refresh();

        $this->assertSame(['nome' => 'Marcia Souza'], $vinculo->google_valores_enviados);
        $this->assertSame(['nome' => '2026-08-26T10:00:00-03:00'], $vinculo->campos_editados_humano);
        $this->assertSame(
            ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
            $vinculo->campos_pendentes_auditoria
        );
    }
}
