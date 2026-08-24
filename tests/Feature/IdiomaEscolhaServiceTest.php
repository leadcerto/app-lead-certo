<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use App\Services\IdiomaEscolhaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdiomaEscolhaServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $idiomas = ['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español'];

    public function test_envia_botoes_pro_canal_uazapi(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $tenant  = Tenant::factory()->create(['uazapi_instance_token' => 'tok']);
        $canal   = WhatsappCanal::factory()->create(['tenant_id' => $tenant->id, 'config' => ['instance_token' => 'tok']]);
        $contato = Contato::factory()->create(['telefone' => '5511900003333']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
        ]);

        $resultado = app(IdiomaEscolhaService::class)->enviarEscolha($ticket, $this->idiomas);

        $this->assertTrue($resultado);
        $this->assertTrue($ticket->fresh()->idioma_aguardando_escolha);
    }

    public function test_envia_texto_numerado_pro_canal_covercut(): void
    {
        Http::fake(['*/messages/send' => Http::response(['id' => 'wamid.x'], 200)]);
        $tenant  = Tenant::factory()->create();
        $canal   = WhatsappCanal::factory()->create([
            'tenant_id' => $tenant->id, 'tipo' => 'oficial', 'provider' => 'covercut',
            'config' => ['phone_number_id' => '123456'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5511900004444']);
        $ticket  = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id, 'whatsapp_canal_id' => $canal->id,
            'coluna_kanban' => 'novo', 'status' => 'aberto', 'aberto_em' => now(),
            'janela_expira_em' => now()->addHours(10),
        ]);

        $resultado = app(IdiomaEscolhaService::class)->enviarEscolha($ticket, $this->idiomas);

        $this->assertTrue($resultado);
        $this->assertTrue($ticket->fresh()->idioma_aguardando_escolha);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages/send')
            && str_contains($req['text']['body'] ?? '', '1) Português')
            && str_contains($req['text']['body'] ?? '', '2) English')
            && str_contains($req['text']['body'] ?? '', '3) Español'));
    }
}
