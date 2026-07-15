<?php

namespace Tests\Feature;

use App\Models\MotivoDesfecho;
use App\Models\Tenant;
use App\Services\TenantSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSetupServiceMotivosDesfechoTest extends TestCase
{
    use RefreshDatabase;

    public function test_novo_tenant_ja_nasce_com_os_motivos_padrao(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantSetupService::class)->configurar($tenant);

        $motivos = MotivoDesfecho::where('tenant_id', $tenant->id)->get();

        $this->assertCount(6, $motivos);
        $this->assertTrue($motivos->firstWhere('chave', 'venda_fechada')->e_venda);
        $this->assertFalse($motivos->firstWhere('chave', 'sem_interesse')->e_venda);
    }

    public function test_rodar_configurar_de_novo_nao_duplica(): void
    {
        $tenant  = Tenant::factory()->create();
        $service = app(TenantSetupService::class);

        $service->configurar($tenant);
        $service->configurar($tenant);

        $this->assertSame(6, MotivoDesfecho::where('tenant_id', $tenant->id)->count());
    }
}
