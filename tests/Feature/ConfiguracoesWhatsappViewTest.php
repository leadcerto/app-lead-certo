<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracoesWhatsappViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_de_configuracoes_whatsapp_carrega_sem_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->get(route('configuracoes'));

        $response->assertOk();
        $response->assertSee('WhatsApp Business (API Não Oficial — uazapi)');

        // Achado real 23/09 (Leonardo): o bloco "WhatsApp Messenger" existia
        // rotulado assim, mas por baixo criava uma instância Uazapi comum —
        // Uazapi só é indicado pra WhatsApp Business de verdade. Nunca mais
        // pode aparecer rotulado como Uazapi.
        $response->assertDontSee('WhatsApp Messenger (API Não Oficial — uazapi)');

        // Fase 4 do plano: reativado com provider real (messenger_proprio, sem
        // Uazapi em nenhuma camada).
        $response->assertSee('WhatsApp Messenger (integração própria)');
    }
}
