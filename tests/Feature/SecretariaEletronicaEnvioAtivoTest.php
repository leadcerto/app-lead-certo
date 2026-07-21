<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecretariaEletronicaEnvioAtivoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_envia_mensagem_de_abertura_quando_envio_esta_desativado(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create([
            'secretaria_token'        => 'token-teste-off',
            'secretaria_envio_ativo'  => false,
        ]);

        $response = $this->postJson('/api/secretaria/token-teste-off', [
            'numero_chamador'  => '11999998888',
            'duracao_segundos' => 0,
        ]);

        $response->assertOk();
        Queue::assertNotPushed(SequenciaMensagemJob::class);
        $this->assertSame(1, TicketAtendimento::where('tenant_id', $tenant->id)->count());
    }

    public function test_envia_mensagem_de_abertura_quando_envio_esta_ativado(): void
    {
        Queue::fake();

        Tenant::factory()->create([
            'secretaria_token'       => 'token-teste-on',
            'secretaria_envio_ativo' => true,
        ]);

        $this->postJson('/api/secretaria/token-teste-on', [
            'numero_chamador'  => '11999997777',
            'duracao_segundos' => 0,
        ]);

        Queue::assertPushed(SequenciaMensagemJob::class);
    }

    public function test_dados_painel_expoe_o_estado_do_envio(): void
    {
        $tenant = Tenant::factory()->create(['secretaria_envio_ativo' => false]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->getJson('/api/painel/secretaria-eletronica/dados');

        $response->assertOk();
        $this->assertFalse($response->json('envio_ativo'));
    }

    public function test_toggle_envio_liga_e_desliga(): void
    {
        $tenant = Tenant::factory()->create(['secretaria_envio_ativo' => true]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);

        $response = $this->actingAs($user)->postJson('/api/painel/secretaria-eletronica/toggle', [
            'ativo' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($tenant->fresh()->secretaria_envio_ativo);
    }
}
