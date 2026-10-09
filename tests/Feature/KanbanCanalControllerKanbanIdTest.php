<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalControllerKanbanIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_vincula_canal_no_kanban_informado_por_kanban_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $kanbanFunil = Kanban::create([
            'tenant_id' => $tenant->id, 'tipo' => 'funil_teste', 'nome' => 'Funil Teste',
            'nome_curto' => 'Funil', 'ordem' => 1,
        ]);
        $canal = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->putJson('/api/painel/kanban/canais?kanban_id=' . $kanbanFunil->id, [
            'canal_ids' => [$canal->id],
        ]);

        $response->assertOk();
        $this->assertTrue($kanbanFunil->fresh()->canais()->where('whatsapp_canais.id', $canal->id)->exists());

        $kanbanGeral = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $this->assertFalse($kanbanGeral->canais()->where('whatsapp_canais.id', $canal->id)->exists());
    }
}
