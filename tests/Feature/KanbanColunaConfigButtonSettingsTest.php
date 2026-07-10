<?php

namespace Tests\Feature;

use App\Models\KanbanColunaConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanColunaConfigButtonSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_button_settings_e_salvo_e_lido_como_array(): void
    {
        $tenant = Tenant::factory()->create();

        $botoes = [
            ['text' => 'Falar com Humano', 'action' => 'move_column', 'target' => 'em_atendimento'],
            ['text' => 'Continuar com IA', 'action' => 'trigger_ia', 'target' => null],
            ['text' => 'Não tenho interesse', 'action' => 'opt_out', 'target' => null],
        ];

        $config = KanbanColunaConfig::updateOrCreate(
            ['tenant_id' => $tenant->id, 'coluna_kanban' => 'aguardando_lead'],
            ['button_settings' => $botoes]
        );

        $this->assertIsArray($config->fresh()->button_settings);
        $this->assertCount(3, $config->fresh()->button_settings);
        $this->assertSame('move_column', $config->fresh()->button_settings[0]['action']);
    }
}
