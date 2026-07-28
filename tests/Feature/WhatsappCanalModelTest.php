<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappCanalModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_pertence_ao_tenant_correto(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($tenant->canais->contains($canal));
        $this->assertSame($tenant->id, $canal->tenant->id);
    }

    public function test_token_uazapi_le_do_config_json(): void
    {
        $canal = WhatsappCanal::factory()->create([
            'config' => ['instance_token' => 'abc123'],
        ]);

        $this->assertSame('abc123', $canal->tokenUazapi());
    }

    public function test_escopo_de_tenant_isola_canais_entre_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        WhatsappCanal::factory()->create(['tenant_id' => $tenantA->id]);
        WhatsappCanal::factory()->create(['tenant_id' => $tenantB->id]);

        session(['tenant_id' => $tenantA->id]);

        $this->assertSame(1, WhatsappCanal::count());
    }
}
