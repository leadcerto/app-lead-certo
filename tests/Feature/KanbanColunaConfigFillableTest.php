<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigFillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdr_delay_segundos_e_persistido_via_mass_assignment(): void
    {
        $tenant = Tenant::factory()->create();

        $config = KanbanColunaConfig::updateOrCreate(
            ['tenant_id' => $tenant->id, 'coluna_kanban' => 'em_atendimento'],
            ['sdr_delay_segundos' => 120]
        );

        $this->assertSame(120, $config->fresh()->sdr_delay_segundos);
    }
}
