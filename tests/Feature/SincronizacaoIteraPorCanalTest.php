<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizacaoIteraPorCanalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincronizar_contatos_grava_canal_no_ticket_criado(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511977776666@s.whatsapp.net', 'contact_name' => 'Ciclano', 'contact_FirstName' => 'Ciclano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'status' => 'connected']);

        $this->artisan('contatos:sincronizar-whatsapp', ['--tenant' => $tenant->id])->assertSuccessful();

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
