<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentacaoEspecificacoesVisualizacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendedor_visualiza_documentacao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/kanban/documentacao/botoes')->assertOk();
    }

    public function test_vendedor_visualiza_especificacoes_tecnicas(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $this->actingAs($user)->get('/admin/especificacoes')->assertOk();
    }
}
