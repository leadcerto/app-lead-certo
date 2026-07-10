<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaControllerButtonSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuarioDono(Tenant $tenant): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'perfil'    => 'dono',
            'ativo'     => true,
        ]);
    }

    private function criarSequencia(Tenant $tenant): Sequencia
    {
        return Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
    }

    public function test_store_mensagem_persiste_button_settings_e_obrigatorio(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo'        => 'Olá!',
            'delay_segundos'  => 0,
            'button_settings' => json_encode([
                ['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'pagamento'],
            ]),
            'obrigatorio' => '1',
        ]);

        $response->assertCreated();
        $msg = SequenciaMensagem::first();
        $this->assertSame(
            [['text' => 'Confirmar', 'action' => 'move_column', 'target' => 'pagamento']],
            $msg->button_settings
        );
        $this->assertTrue($msg->obrigatorio);
    }

    public function test_update_mensagem_atualiza_button_settings_e_obrigatorio(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens/{$msg->id}", [
            '_method'         => 'PUT',
            'conteudo'        => 'Olá atualizado!',
            'delay_segundos'  => 0,
            'button_settings' => json_encode([
                ['text' => 'Ver mais', 'action' => 'open_url', 'target' => 'https://exemplo.com'],
            ]),
            'obrigatorio' => '1',
        ]);

        $response->assertOk();
        $msg->refresh();
        $this->assertSame(
            [['text' => 'Ver mais', 'action' => 'open_url', 'target' => 'https://exemplo.com']],
            $msg->button_settings
        );
        $this->assertTrue($msg->obrigatorio);
    }

    public function test_button_settings_com_action_invalida_e_rejeitado(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo'        => 'Olá!',
            'delay_segundos'  => 0,
            'button_settings' => json_encode([
                ['text' => 'Foo', 'action' => 'apagar_tudo', 'target' => null],
            ]),
        ]);

        $response->assertStatus(422);
    }

    public function test_mais_de_3_botoes_e_rejeitado(): void
    {
        $tenant    = Tenant::factory()->create();
        $user      = $this->criarUsuarioDono($tenant);
        $sequencia = $this->criarSequencia($tenant);

        $response = $this->actingAs($user)->post("/api/painel/sequencias/{$sequencia->id}/mensagens", [
            'conteudo'        => 'Olá!',
            'delay_segundos'  => 0,
            'button_settings' => json_encode([
                ['text' => 'A', 'action' => 'move_column', 'target' => 'pagamento'],
                ['text' => 'B', 'action' => 'move_column', 'target' => 'pagamento'],
                ['text' => 'C', 'action' => 'move_column', 'target' => 'pagamento'],
                ['text' => 'D', 'action' => 'move_column', 'target' => 'pagamento'],
            ]),
        ]);

        $response->assertStatus(422);
    }
}
