<?php
// tests/Feature/AlertaInternoControllerTest.php
namespace Tests\Feature;

use App\Models\AlertaInterno;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInternoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarUsuario(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
    }

    public function test_lista_alertas_do_tenant_com_contagem_de_nao_lidos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);
        AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'B', 'conteudo' => 'x', 'lido_em' => now()]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJson(['nao_lidos_count' => 1]);
    }

    public function test_lista_ordena_mais_recente_primeiro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $antigo = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'Antigo', 'conteudo' => 'x', 'created_at' => now()->subDay()]);
        $novo   = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'Novo', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $novo->id);
        $response->assertJsonPath('data.1.id', $antigo->id);
    }

    public function test_marcar_lido_individual(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $alerta = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->postJson("/api/painel/alertas/{$alerta->id}/marcar-lido");

        $response->assertOk();
        $this->assertNotNull($alerta->fresh()->lido_em);
    }

    public function test_marcar_todos_lidos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        $a = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'A', 'conteudo' => 'x']);
        $b = AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'duvida_ia', 'titulo' => 'B', 'conteudo' => 'x']);

        $response = $this->actingAs($user)->postJson('/api/painel/alertas/marcar-todos-lidos');

        $response->assertOk();
        $this->assertNotNull($a->fresh()->lido_em);
        $this->assertNotNull($b->fresh()->lido_em);
    }

    public function test_isolamento_por_tenant(): void
    {
        $tenantA  = Tenant::factory()->create();
        $tenantB  = Tenant::factory()->create();
        $userA    = $this->criarUsuario($tenantA);
        $alertaB  = AlertaInterno::create(['tenant_id' => $tenantB->id, 'tipo' => 'duvida_ia', 'titulo' => 'De outro tenant', 'conteudo' => 'x']);

        $listagem = $this->actingAs($userA)->getJson('/api/painel/alertas');
        $listagem->assertJsonCount(0, 'data');

        $marcar = $this->actingAs($userA)->postJson("/api/painel/alertas/{$alertaB->id}/marcar-lido");
        $marcar->assertStatus(404);
    }

    public function test_lista_pagina_com_20_por_pagina(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);
        for ($i = 0; $i < 25; $i++) {
            AlertaInterno::create(['tenant_id' => $tenant->id, 'tipo' => 'ticket_travado', 'titulo' => "Alerta {$i}", 'conteudo' => 'x']);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonCount(20, 'data');
    }

    public function test_duvidas_nao_respondidas_aparecem_primeiro(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        // 3 alertas de outro tipo, mais recentes que a dúvida
        for ($i = 0; $i < 3; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }
        $duvida = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida antiga', 'conteudo' => 'x',
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $duvida->id);
    }

    public function test_duvida_pendente_nunca_sai_da_lista_por_volume_de_outros_tipos(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        $duvida = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida', 'conteudo' => 'x', 'created_at' => now()->subDays(2),
        ]);
        for ($i = 0; $i < 25; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($duvida->id));
        $this->assertSame($duvida->id, $ids->first());
    }

    public function test_duvida_ja_respondida_nao_conta_como_pendente(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
            'titulo' => 'Dúvida respondida', 'conteudo' => 'x',
            'resposta' => 'já respondida', 'respondido_em' => now(),
            'created_at' => now()->subDay(),
        ]);
        $recente = AlertaInterno::create([
            'tenant_id' => $tenant->id, 'tipo' => 'reassuncao_automatica',
            'titulo' => 'Recente', 'conteudo' => 'x',
        ]);

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonPath('data.0.id', $recente->id);
    }

    public function test_lista_completa_20_com_multiplas_duvidas_pendentes(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->criarUsuario($tenant);

        for ($i = 0; $i < 5; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'duvida_ia',
                'titulo' => "Dúvida {$i}", 'conteudo' => 'x',
            ]);
        }
        for ($i = 0; $i < 20; $i++) {
            AlertaInterno::create([
                'tenant_id' => $tenant->id, 'tipo' => 'ticket_travado',
                'titulo' => "Travado {$i}", 'conteudo' => 'x',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/painel/alertas');

        $response->assertJsonCount(20, 'data');
    }
}
