<?php

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::whereNotNull('uazapi_instance_token')->each(function (Tenant $tenant) {
            $jaMigrado = WhatsappCanal::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'uazapi')
                ->exists();

            if ($jaMigrado) {
                return; // idempotente — já rodou antes
            }

            $canal = WhatsappCanal::withoutGlobalScopes()->create([
                'tenant_id'       => $tenant->id,
                'tipo'            => 'nao_oficial',
                'provider'        => 'uazapi',
                'status'          => $tenant->whatsapp_status ?? 'disconnected',
                'phone'           => $tenant->whatsapp_phone,
                'connected_since' => $tenant->whatsapp_connected_since,
                'webhook_token'   => $tenant->uazapi_webhook_token,
                'config'          => [
                    'instance_name'  => $tenant->uazapi_instance_name,
                    'instance_token' => $tenant->uazapi_instance_token,
                ],
            ]);

            // Vincula o canal migrado a TODOS os Kanbans do tenant — sem isso,
            // a seleção de canal por Kanban (Task 5) não encontraria nenhum
            // canal vinculado e a prospecção pararia de funcionar para quem
            // já estava conectado antes desta entrega.
            $kanbanIds = Kanban::where('tenant_id', $tenant->id)->pluck('id');
            $canal->kanbans()->syncWithoutDetaching($kanbanIds);
        });
    }

    public function down(): void
    {
        // Backfill não é destrutivo o suficiente para reverter com segurança
        // (canais podem já ter sido usados por tickets criados depois do backfill).
        // Reversão manual, se necessário.
    }
};
