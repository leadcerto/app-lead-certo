<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do Leonardo (01/10): mostrar no painel do contato os grupos/
 * comunidades do WhatsApp em comum — dado já gravado desde a extração de
 * grupos (ImportarParticipantesGrupos::registrarGrupoEmComum()), mas nunca
 * exposto em lugar nenhum. Fica só no painel do Lead Certo, por decisão
 * dele — não empurra pro Google (já tem a etiqueta 🚩 FRIOS fazendo esse
 * papel lá).
 */
class ContatosControllerShowContatoGruposEmComumTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_grupos_em_comum_quando_vinculo_tem_dados(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();

        VinculoContatoTenant::create([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
            'grupos_whatsapp_em_comum' => [
                ['jid' => '120363417782544566@g.us', 'nome_visto' => 'Vip Membros #lll'],
            ],
        ]);

        $response = $this->actingAs($user)->getJson("/api/painel/contato/{$contato->id}");

        $response->assertOk();
        $this->assertSame(
            [['jid' => '120363417782544566@g.us', 'nome_visto' => 'Vip Membros #lll']],
            $response->json('grupos_whatsapp_em_comum')
        );
    }

    public function test_grupos_em_comum_vem_array_vazio_quando_nao_tem_vinculo(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();

        $response = $this->actingAs($user)->getJson("/api/painel/contato/{$contato->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('grupos_whatsapp_em_comum'));
    }

    public function test_grupos_em_comum_vem_array_vazio_quando_vinculo_existe_sem_grupos(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono', 'ativo' => true]);
        $contato = Contato::factory()->create();

        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson("/api/painel/contato/{$contato->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('grupos_whatsapp_em_comum'));
    }
}
