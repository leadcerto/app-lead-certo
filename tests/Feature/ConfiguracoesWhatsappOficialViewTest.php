<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracoesWhatsappOficialViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_de_configuracoes_whatsapp_mostra_secao_oficial(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get(route('configuracoes'));

        $response->assertOk();
        $response->assertSee('WhatsApp Oficial');
        $response->assertSee('Conectar número oficial');
    }
}
