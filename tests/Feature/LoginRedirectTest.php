<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_avaliador_e_redirecionado_pro_proprio_painel_apos_login(): void
    {
        $tenant    = Tenant::factory()->create();
        $avaliador = User::factory()->create([
            'tenant_id' => $tenant->id, 'perfil' => 'avaliador',
            'email' => 'regis@frete.rio.br', 'password' => Hash::make('senha12345'),
        ]);

        $response = $this->post('/login', ['email' => 'regis@frete.rio.br', 'password' => 'senha12345']);

        $response->assertRedirect(route('avaliador.dashboard'));
    }

    public function test_dono_e_redirecionado_pro_dashboard_geral_apos_login(): void
    {
        $tenant = Tenant::factory()->create();
        $dono   = User::factory()->create([
            'tenant_id' => $tenant->id, 'perfil' => 'dono',
            'email' => 'leonardo@frete.rio.br', 'password' => Hash::make('senha12345'),
        ]);

        $response = $this->post('/login', ['email' => 'leonardo@frete.rio.br', 'password' => 'senha12345']);

        $response->assertRedirect(route('dashboard'));
    }
}
