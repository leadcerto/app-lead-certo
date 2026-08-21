<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdiomaSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_novo_usuario_e_tenant_nascem_com_pt_br_por_padrao(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSame('pt-BR', $tenant->fresh()->locale);
        $this->assertSame('pt-BR', $user->fresh()->idioma);
    }

    public function test_ticket_aceita_os_campos_novos_de_deteccao_de_idioma(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'idioma_pais_ddi' => 'es-ES',
            'idioma_origem' => 'ddi',
            'idioma_confianca' => 0.90,
            'idioma_atualizado_em' => now(),
            'idioma_aguardando_escolha' => true,
        ]);

        $fresh = $ticket->fresh();
        $this->assertSame('es-ES', $fresh->idioma_pais_ddi);
        $this->assertSame('ddi', $fresh->idioma_origem);
        $this->assertSame('0.90', $fresh->idioma_confianca);
        $this->assertNotNull($fresh->idioma_atualizado_em);
        $this->assertTrue($fresh->idioma_aguardando_escolha);
    }
}
