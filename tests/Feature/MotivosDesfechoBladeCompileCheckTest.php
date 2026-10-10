<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotivosDesfechoBladeCompileCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_motivos_desfecho_renderiza_sem_erro_de_blade(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'vendedor', 'ativo' => true]);

        $response = $this->actingAs($user)->get('/kanban/motivos-desfecho');

        $response->assertOk();
    }
}
