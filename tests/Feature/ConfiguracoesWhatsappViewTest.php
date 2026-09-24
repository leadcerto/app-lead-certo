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
        // Uazapi só é indicado pra WhatsApp Business de verdade. Removido até
        // existir uma integração própria de Messenger de verdade (ver plano
        // do canal WhatsApp Messenger próprio). A tela não pode mais prometer
        // esse rótulo como se fosse uma opção funcional.
        $response->assertDontSee('WhatsApp Messenger (API Não Oficial — uazapi)');
    }
}
