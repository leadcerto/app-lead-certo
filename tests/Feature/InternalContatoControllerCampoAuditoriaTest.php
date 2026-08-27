<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalContatoControllerCampoAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pushname_divergente_do_master_vai_para_fila_de_auditoria(): void
    {
        config(['app.service_key' => 'chave-de-teste']);

        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'telefone' => '5511988887777',
            'nome'     => 'Nome Master',
        ]);

        $response = $this->postJson('/api/internal/contato', [
            'telefone'  => '5511988887777',
            'nome'      => 'Nome Diferente Do WhatsApp',
            'origem'    => 'whatsapp',
            'tenant_id' => $tenant->id,
        ], ['X-Service-Key' => 'chave-de-teste']);

        $response->assertOk();
        $response->assertJson(['auditoria_pendente' => true]);

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        $this->assertNotNull($vinculo);
        $this->assertSame(
            [
                'sugerido' => 'Nome Diferente Do WhatsApp',
                'origem'   => 'whatsapp_pushname',
            ],
            $vinculo->campos_pendentes_auditoria['nome'] ?? null
        );

        // Master não foi alterado.
        $this->assertSame('Nome Master', $contato->fresh()->nome);
    }
}
