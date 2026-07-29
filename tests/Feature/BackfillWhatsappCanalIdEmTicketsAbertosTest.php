<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillWhatsappCanalIdEmTicketsAbertosTest extends TestCase
{
    use RefreshDatabase;

    private function rodarMigration(): void
    {
        $migration = require database_path('migrations/2026_07_27_000005_backfill_whatsapp_canal_id_em_tickets_abertos.php');
        $migration->up();
    }

    public function test_preenche_whatsapp_canal_id_de_ticket_existente_sem_canal(): void
    {
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create();

        // Criado diretamente (não via webhook), simulando um ticket pré-existente
        // que nunca teve whatsapp_canal_id preenchido.
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->assertNull($ticket->fresh()->whatsapp_canal_id);

        $this->rodarMigration();

        $this->assertSame($canal->id, $ticket->fresh()->whatsapp_canal_id);
    }

    public function test_nao_sobrescreve_ticket_que_ja_tinha_whatsapp_canal_id(): void
    {
        $tenant     = Tenant::factory()->create();
        $canalCerto = WhatsappCanal::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok-1'],
        ]);
        $outroCanal = WhatsappCanal::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok-2'],
        ]);
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'whatsapp_canal_id' => $outroCanal->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->rodarMigration();

        // Continua apontando pro canal que já tinha, não foi trocado pelo
        // primeiro canal uazapi encontrado pra esse tenant.
        $this->assertSame($outroCanal->id, $ticket->fresh()->whatsapp_canal_id);
        $this->assertNotSame($canalCerto->id, $ticket->fresh()->whatsapp_canal_id);
    }

    public function test_tenant_sem_canal_uazapi_e_ignorado_sem_erro(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->rodarMigration();

        $this->assertNull($ticket->fresh()->whatsapp_canal_id);
    }

    public function test_e_idempotente_ao_rodar_duas_vezes(): void
    {
        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            'status'    => 'connected',
            'config'    => ['instance_token' => 'tok'],
        ]);
        $contato = Contato::factory()->create();

        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'em_atendimento', 'agente_responsavel' => 'humano',
            'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $this->rodarMigration();
        $this->rodarMigration();

        $this->assertSame($canal->id, $ticket->fresh()->whatsapp_canal_id);
    }
}
