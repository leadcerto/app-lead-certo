<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillWhatsappCanaisTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_cria_canal_e_vincula_ao_kanban_do_tenant(): void
    {
        $tenant = Tenant::factory()->create([
            'uazapi_instance_name'      => 'tenant-42',
            'uazapi_instance_token'     => 'token-abc',
            'uazapi_webhook_token'      => 'webhook-xyz',
            'whatsapp_status'           => 'connected',
            'whatsapp_phone'            => '5511999999999',
            'whatsapp_connected_since'  => now(),
        ]);

        // Executar a logic de backfill diretamente (equivalente à migration)
        $migration = require database_path('migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php');
        $migration->up();

        $canal = WhatsappCanal::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($canal);
        $this->assertSame('nao_oficial', $canal->tipo);
        $this->assertSame('uazapi', $canal->provider);
        $this->assertSame('token-abc', $canal->tokenUazapi());
        $this->assertSame('webhook-xyz', $canal->webhook_token);

        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertTrue($canal->kanbans->contains($kanban));
    }

    public function test_backfill_ignora_tenants_sem_uazapi_conectado(): void
    {
        Tenant::factory()->create(['uazapi_instance_token' => null]);

        // Executar a logic de backfill diretamente (equivalente à migration)
        $migration = require database_path('migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php');
        $migration->up();

        $this->assertSame(0, WhatsappCanal::withoutGlobalScopes()->count());
    }

    public function test_backfill_eh_idempotente_nao_cria_duplicatas(): void
    {
        $tenant = Tenant::factory()->create([
            'uazapi_instance_name'      => 'tenant-idempotent',
            'uazapi_instance_token'     => 'token-idempotent',
            'uazapi_webhook_token'      => 'webhook-idempotent',
            'whatsapp_status'           => 'connected',
            'whatsapp_phone'            => '5511988888888',
            'whatsapp_connected_since'  => now(),
        ]);

        // Executar a migration duas vezes (idempotency test)
        $migration = require database_path('migrations/2026_07_27_000004_backfill_whatsapp_canais_from_tenants.php');
        $migration->up();
        $migration->up();

        // Após duas execuções, deve haver exatamente 1 WhatsappCanal (não 2)
        $canalCount = WhatsappCanal::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $this->assertSame(1, $canalCount, 'Migration should be idempotent and not create duplicate WhatsappCanal rows');
    }
}
