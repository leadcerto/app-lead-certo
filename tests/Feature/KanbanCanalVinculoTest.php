<?php

namespace Tests\Feature;

use App\Models\Kanban;
use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanCanalVinculoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vincula_e_desvincula_canais_de_um_kanban(): void
    {
        $tenant = Tenant::factory()->create();
        $kanban = Kanban::where('tenant_id', $tenant->id)->where('tipo', 'vendas')->firstOrFail();
        $canalA = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);
        $canalB = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        $kanban->canais()->attach([$canalA->id, $canalB->id]);

        $this->assertCount(2, $kanban->canais);
        $this->assertTrue($canalA->kanbans->contains($kanban));

        $kanban->canais()->sync([$canalA->id]);

        $this->assertCount(1, $kanban->fresh()->canais);
    }
}
