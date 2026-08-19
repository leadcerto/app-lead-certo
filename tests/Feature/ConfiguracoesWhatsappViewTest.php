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
        // Achado 2026-08-19: os dois blocos não-oficiais (Business e Messenger) são
        // apps físicos diferentes por trás da mesma tecnologia Baileys/Uazapi — a
        // tela precisa deixar isso explícito, não juntar num "Não-Oficial" genérico.
        $response->assertSee('WhatsApp Business (API Não Oficial — uazapi)');
        $response->assertSee('WhatsApp Messenger (API Não Oficial — uazapi)');
    }
}
