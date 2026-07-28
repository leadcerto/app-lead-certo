<?php

namespace Tests\Feature;

use App\Jobs\SincronizarAgendaWhatsAppJob;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizarAgendaWhatsAppJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_contato_e_marca_o_canal_no_ticket_criado(): void
    {
        Http::fake([
            '*/contacts' => Http::response([
                ['jid' => '5511988887777@s.whatsapp.net', 'contact_name' => 'Fulano', 'contact_FirstName' => 'Fulano'],
            ], 200),
        ]);

        $tenant = Tenant::factory()->create();
        $canal  = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id]);

        (new SincronizarAgendaWhatsAppJob($canal->id))->handle(app(\App\Services\UazapiService::class));

        $ticket = TicketAtendimento::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($ticket);
        $this->assertSame($canal->id, $ticket->whatsapp_canal_id);
    }
}
